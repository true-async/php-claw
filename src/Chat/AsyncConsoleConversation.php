<?php

declare(strict_types=1);

namespace Claw\Chat;

use Async\ThreadChannel;
use Async\ThreadChannelException;

use Async\ThreadTransferException;
use function Async\delay;
use function Async\spawn;
use function Async\spawn_thread;

/**
 * Async terminal conversation with a fixed colored footer:
 *
 *   ╔═══════════════ PHP Claw ◆ TrueAsync 8.6 ════════════╗  rows 1-3 (fixed banner)
 *   ║                                                      ║
 *   ╚══════════════════════════════════════════════════════╝
 *   │  scrolling chat history                              │  rows 4…N-3
 *   ──────────────────────────────────────────────────────   row N-2  (separator)
 *   ⠙ Thinking… / ↑120 ↓30 tokens                          row N-1  (status)
 *   User: _                                                 row N    (input)
 *
 * Terminal size is detected via env vars / readline_info. Resize is handled by
 * re-detecting size in the spinner loop.
 */
final class AsyncConsoleConversation implements ConversationInterface
{
    // ANSI color shortcuts
    private const string C_RESET   = "\033[0m";
    private const string C_BOLD    = "\033[1m";
    private const string C_DIM     = "\033[2m";
    private const string C_BANNER  = "\033[1;96m";   // bold bright cyan — banner
    private const string C_SEP     = "\033[90m";      // dark gray — separator
    private const string C_SPIN    = "\033[93m";      // bright yellow — spinner
    private const string C_TOOL    = "\033[96m";      // cyan — tool name
    private const string C_TOKENS  = "\033[32m";      // green — token count
    private const string C_USER    = "\033[97m";      // bright white — user prefix
    private const string C_CLAW    = "\033[1;97m";    // bold white — claw response prefix

    private readonly ThreadChannel $inputChannel;
    private readonly ThreadChannel $outputChannel;

    /**
     * @throws ThreadTransferException
     */
    public function __construct()
    {
        $this->inputChannel  = new ThreadChannel(4);
        $this->outputChannel = new ThreadChannel(16);

        spawn_thread(
            task: $this->drawer(...),
            bootloader: static function (): void {
                require_once __DIR__ . '/../../vendor/autoload.php';
            },
        );
    }

    /**
     * Stop the worker thread. The thread parks on outputChannel->recv() between
     * commands and only leaves that loop on a closed channel or on readline EOF
     * (see true-async/php-async#162). When a conversation ends without the user
     * hitting EOF, the thread would otherwise stay parked and keep the process
     * alive forever. Closing the output channel wakes recv() so the thread returns.
     *
     * Only outputChannel is closed here: the thread never recv()s on inputChannel
     * (it only sends), and it closes inputChannel itself on EOF — leaving that to
     * the thread avoids a double-close race.
     */
    public function close(): void
    {
        if (!$this->outputChannel->isClosed()) {
            $this->outputChannel->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function receive(): ?string
    {
        $this->outputChannel->send(['type' => Command::Prompt->value]);

        try {
            return $this->inputChannel->recv();
        } catch (ThreadChannelException) {
            return null;
        }
    }

    public function send(string $text): void
    {
        $this->outputChannel->send(['type' => Command::Send->value, 'text' => 'Claw: ' . $text . "\n"]);
    }

    public function updateStatus(?Status $status): void
    {
        if ($status === null) {
            $this->outputChannel->send(['type' => Command::Clear->value]);
        } else {
            $this->outputChannel->send([
                'type'     => Command::Status->value,
                'animated' => $status->animated,
                'label'    => $status->label(),
            ]);
        }
    }

    // ── Worker thread ──────────────────────────────────────────────────────────

    /**
     * Worker-thread entry point and main loop.
     * @internal
     */
    private function drawer(): void
    {
        $in  = $this->inputChannel;
        $out = $this->outputChannel;
        [$rows, $cols] = self::detectSize();

        // ── Build layout ─────────────────────────────────────────────────
        [$chatStart, $chatRows, $sepRow, $statusRow, $inputRow, $sep, $banner]
            = self::buildLayout($rows, $cols);

        // ── Helpers ──────────────────────────────────────────────────────
        $draw   = self::makeDrawer($chatStart, $chatRows, $sepRow, $statusRow, $inputRow, $sep, $banner);
        $statusW = static function (string $text) use ($statusRow): void {
            fwrite(STDOUT, "\033[s\033[{$statusRow};1H\033[2K{$text}" . self::C_RESET . "\033[u");
        };
        $inputW  = static function (string $text) use ($inputRow): void {
            fwrite(STDOUT, "\033[s\033[{$inputRow};1H\033[2K{$text}" . self::C_RESET . "\033[u");
        };
        $chatW   = static function (string $text) use ($chatRows): void {
            fwrite(STDOUT, "\033[{$chatRows};1H{$text}" . self::C_RESET);
        };

        // Rebuild layout + writers for the current $rows/$cols and repaint.
        // Called on resize from both the spinner's periodic check and the
        // Resize command. ($statusW keeps its original binding by design.)
        $relayout = function () use (
            &$rows, &$cols,
            &$chatStart, &$chatRows, &$sepRow, &$statusRow, &$inputRow, &$sep, &$banner,
            &$draw, &$chatW, &$inputW
        ): void {
            [$chatStart, $chatRows, $sepRow, $statusRow, $inputRow, $sep, $banner]
                = self::buildLayout($rows, $cols);
            $draw   = self::makeDrawer($chatStart, $chatRows, $sepRow, $statusRow, $inputRow, $sep, $banner);
            $chatW  = static function (string $text) use (&$chatRows): void {
                fwrite(STDOUT, "\033[{$chatRows};1H{$text}" . self::C_RESET);
            };
            $inputW = static function (string $text) use (&$inputRow): void {
                fwrite(STDOUT, "\033[s\033[{$inputRow};1H\033[2K{$text}" . self::C_RESET . "\033[u");
            };
            $draw();
        };

        // Initial draw
        $draw();

        // Restore terminal state on exit
        register_shutdown_function(static function () use ($rows): void {
            fwrite(STDOUT, "\033[0m\033[r\033[{$rows};1H\n");
        });

        // ── Main loop ────────────────────────────────────────────────────
        $spinner   = null;
        $tokenInfo = '';

        // Cancel + clear the running spinner coroutine, if any.
        $cancelSpinner = static function () use (&$spinner): void {
            if ($spinner !== null) {
                $spinner->cancel();
                $spinner = null;
            }
        };

        while (true) {
            try {
                $cmd = $out->recv();
            } catch (ThreadChannelException) {
                return;
            }

            switch (Command::from($cmd['type'])) {

                case Command::Send:
                    $cancelSpinner();
                    $statusW('');
                    // Colour the "Claw:" prefix
                    $text = preg_replace('/^(Claw: )/', self::C_CLAW . '$1' . self::C_RESET, $cmd['text']);
                    $chatW($text ?? $cmd['text']);
                    break;

                case Command::Status:
                    $cancelSpinner();

                    if ($cmd['animated']) {
                        $label = $cmd['label'];
                        $inputW('');
                        $spinner = spawn(
                            static function () use ($label, $statusW, &$rows, &$cols, $relayout): void {
                                static $frames = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
                                $frame      = 0;
                                $resizeTick = 0;
                                try {
                                    while (true) {
                                        $statusW(self::C_SPIN . $frames[$frame % 10] . $label . self::C_RESET);
                                        $frame++;
                                        $resizeTick++;
                                        delay(100);

                                        // Check for resize every ~2 seconds
                                        if ($resizeTick % 20 === 0) {
                                            [$newRows, $newCols] = self::detectSize();
                                            if ($newRows !== $rows || $newCols !== $cols) {
                                                $rows = $newRows;
                                                $cols = $newCols;
                                                $relayout();
                                            }
                                        }
                                    }
                                } catch (\Async\OperationCanceledException) {}
                            }
                        );
                    } else {
                        $tokenInfo = $cmd['label'];
                        $statusW(self::C_TOKENS . $tokenInfo . self::C_RESET);
                    }
                    break;

                case Command::Clear:
                    $cancelSpinner();
                    $statusW('');
                    $inputW('');
                    $tokenInfo = '';
                    break;

                case Command::Prompt:
                    $cancelSpinner();
                    $tokenInfo = '';

                    fwrite(STDOUT, "\033[{$inputRow};1H\033[2K");

                    while (true) {
                        $line = readline(self::C_USER . 'User: ' . self::C_RESET);
                        if ($line === false) { $in->close(); return; }
                        $line = trim($line);
                        if ($line !== '') {
                            readline_add_history($line);
                            break;
                        }
                    }

                    // Re-anchor layout after Enter (may shift fixed rows on last line).
                    fwrite(STDOUT,
                        "\033[{$chatStart};{$chatRows}r"
                        . "\033[{$sepRow};1H\033[2K" . self::C_SEP . $sep . self::C_RESET
                        . "\033[{$statusRow};1H\033[2K"
                        . "\033[{$inputRow};1H\033[2K"
                    );

                    $chatW(self::C_USER . 'User: ' . self::C_RESET . $line . "\n");
                    $in->send($line);
                    break;

                case Command::Resize:
                    // No producer sends this today — the spinner's periodic check
                    // already handles live resize. Kept as an external trigger hook.
                    [$rows, $cols] = self::detectSize();
                    $relayout();
                    break;
            }
        }
    }

    // ── Private helpers (static so they can be used inside the worker thread) ──

    /**
     * @return array{int,int}  [rows, cols]
     */
    private static function detectSize(): array
    {
        // 1. Environment variables (set by some terminals on startup)
        $rows = (int)(getenv('LINES')   ?: 0);
        $cols = (int)(getenv('COLUMNS') ?: 0);

        // 2. readline_info — available when PHP readline extension is loaded.
        if ($rows < 4 || $cols < 20) {
            $w = (int)(readline_info('terminal_width')  ?: 0);
            $h = (int)(readline_info('terminal_height') ?: 0);
            if ($w > 20) $cols = $w;
            if ($h > 4)  $rows = $h;
        }

        return [max(8, $rows ?: 30), max(40, $cols ?: 120)];
    }

    /**
     * @return array{int,int,int,int,int,string,string}
     *         [chatStart, chatRows, sepRow, statusRow, inputRow, sep, banner]
     */
    private static function buildLayout(int $rows, int $cols): array
    {
        $chatStart = 4;
        $chatRows  = $rows - 3;
        $sepRow    = $rows - 2;
        $statusRow = $rows - 1;
        $inputRow  = $rows;
        $sep       = str_repeat('─', $cols);

        $inner  = '  PHP Claw  ◆  TrueAsync 8.6  ';
        $padded = str_pad($inner, $cols - 2, ' ', STR_PAD_BOTH);
        $top    = '╔' . str_repeat('═', $cols - 2) . '╗';
        $mid    = '║' . $padded . '║';
        $bot    = '╚' . str_repeat('═', $cols - 2) . '╝';
        $banner = self::C_BANNER . $top . "\n" . $mid . "\n" . $bot . self::C_RESET . "\n";

        return [$chatStart, $chatRows, $sepRow, $statusRow, $inputRow, $sep, $banner];
    }

    /**
     * Returns a closure that does a full redraw of the fixed chrome
     * (banner + scroll region + separator + status + input bars).
     */
    private static function makeDrawer(
        int $chatStart, int $chatRows,
        int $sepRow, int $statusRow, int $inputRow,
        string $sep, string $banner
    ): \Closure {
        return static function () use (
            $chatStart, $chatRows,
            $sepRow, $statusRow, $inputRow,
            $sep, $banner
        ): void {
            fwrite(STDOUT,
                "\033[?25l"
                . "\033[2J\033[H"
                . $banner
                . "\033[{$chatStart};{$chatRows}r"
                . "\033[{$sepRow};1H\033[2K" . self::C_SEP . $sep . self::C_RESET
                . "\033[{$statusRow};1H\033[2K"
                . "\033[{$inputRow};1H\033[2K"
                . "\033[{$chatStart};1H"
                . "\033[?25h"
            );
        };
    }
}
