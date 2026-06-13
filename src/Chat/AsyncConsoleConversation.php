<?php

declare(strict_types=1);

namespace Claw\Chat;

use Async\Coroutine;

use function Async\delay;
use function Async\spawn;

/**
 * Async terminal conversation with a fixed coloured chrome:
 *
 *   ╔═══ PHP Claw | PHP x.y | TrueAsync z ═══╗   banner   (rows 1-3)
 *   │  scrolling chat history                │   rows 4 … N-5
 *   ⠙ Thinking…                                  status   (row N-4)
 *   ──────────────────────────────────────      separator (row N-3)
 *   User: _                                      input    (row N-2)
 *   ──────────────────────────────────────      separator (row N-1)
 *   ↑120 ↓30 tokens                              bottom bar (row N)
 *
 * Everything runs in the caller's thread as coroutines. Input (fgets) and output
 * (fwrite) on STDIN/STDOUT are async under TrueAsync — they park the coroutine
 * instead of blocking the thread — so no worker thread is needed. A resize-watcher
 * coroutine repaints when the console window changes.
 */
final class AsyncConsoleConversation implements ConversationInterface
{
    // ANSI color shortcuts
    private const string C_RESET   = "\033[0m";
    private const string C_DIM     = "\033[2m";
    private const string C_BANNER  = "\033[1;96m";   // bold bright cyan — banner
    private const string C_SEP     = "\033[90m";      // dark gray — separator
    private const string C_SPIN    = "\033[93m";      // bright yellow — spinner
    private const string C_TOKENS  = "\033[32m";      // green — token count
    private const string C_USER    = "\033[97m";      // bright white — user prefix
    private const string C_CLAW    = "\033[1;97m";    // bold white — claw response prefix

    // ── Render state ──────────────────────────────────────────────────────────
    private int $rows = 0;
    private int $cols = 0;
    private int $chatStart = 0;
    private int $chatRows = 0;
    private int $statusRow = 0;   // transient activity (spinner + label)
    private int $sepTopRow = 0;   // separator above the input line
    private int $inputRow = 0;    // input line (static)
    private int $sepBotRow = 0;   // separator below the input line
    private int $barRow = 0;      // bottom slot bar (tokens left, reserved right)
    private string $sep = '';
    private string $banner = '';
    /** @var Coroutine<mixed>|null */
    private ?Coroutine $spinner = null;
    /** @var Coroutine<mixed>|null */
    private ?Coroutine $resizeWatcher = null;
    private string $statusLabel = '';
    private string $tokens = '';
    private bool $awaitingInput = true;   // the background reader keeps the prompt active

    /** @var list<string> Chat lines (with trailing "\n"), replayed after a resize. */
    private array $history = [];
    private const int HISTORY_MAX = 500;

    /** @var Coroutine<mixed>|null */
    private ?Coroutine $reader = null;
    /** @var list<string> Submitted lines awaiting consumption by receive(). */
    private array $inbox = [];
    private bool $eof = false;

    public function __construct()
    {
        [$this->rows, $this->cols] = self::detectSize();
        $this->buildLayout();
        $this->draw();

        // Repaint when the window is resized (runs in this thread's reactor).
        $this->resizeWatcher = spawn($this->watchResize(...));

        // Read input in the background so the user can type even while the agent
        // is busy ("thinking"). receive() just consumes what this produces.
        $this->reader = spawn($this->readLoop(...));
    }

    public function receive(): ?string
    {
        // The background reader (readLoop) does the actual input; here we just
        // wait for the next submitted line. Because the reader keeps reading
        // while the agent works, the user can type ahead during "thinking".
        while ($this->inbox === [] && !$this->eof) {
            delay(50);
        }

        if ($this->inbox !== []) {
            return array_shift($this->inbox);
        }

        $this->close();   // EOF — nothing more to read

        return null;
    }

    /**
     * Background input reader: keeps the prompt on the input row, reads lines
     * (cooked mode echoes them and handles backspace), records each in history
     * and queues it for receive(). Runs for the whole session; cancelled by close().
     */
    private function readLoop(): void
    {
        while (true) {
            fwrite(STDOUT, "\033[{$this->inputRow};1H\033[2K");
            fflush(STDOUT);
            $line = fgets(STDIN);

            if ($line === false) {
                $this->eof = true;   // EOF (Ctrl-D / closed stdin)
                return;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Re-assert the scroll region + separators (the Enter newline may
            // nudge them), record the line, then re-home the cursor.

            fwrite(
                STDOUT,
                "\033[{$this->chatStart};{$this->chatRows}r"
                . "\033[{$this->sepTopRow};1H\033[2K" . self::C_SEP . $this->sep . self::C_RESET
                . "\033[{$this->sepBotRow};1H\033[2K" . self::C_SEP . $this->sep . self::C_RESET
                . "\033[{$this->inputRow};1H\033[2K"
            );

            $this->appendChat(self::C_USER . 'User: ' . self::C_RESET . $line . "\n");
            $this->inbox[] = $line;
        }
    }

    public function send(string $text): void
    {
        $this->cancelSpinner();
        $this->writeStatus('');
        $msg     = 'Claw: ' . $text . "\n";
        $colored = preg_replace('/^(Claw: )/', self::C_CLAW . '$1' . self::C_RESET, $msg);
        $this->appendChat($colored ?? $msg);
    }

    public function updateStatus(?Status $status): void
    {
        $this->cancelSpinner();

        if ($status === null) {
            $this->statusLabel = '';
            $this->writeStatus('');
            return;
        }

        if ($status->animated) {
            $this->statusLabel = $status->label();
            $this->spinner = spawn($this->spin(...));
        } else {
            // "Done": activity stops; token usage moves to the bottom bar.
            $this->statusLabel = '';
            $this->writeStatus('');
            $this->tokens = $status->label();
            $this->writeBar();
        }
    }

    public function close(): void
    {
        $this->cancelSpinner();
        $this->cancelResizeWatcher();

        if ($this->reader !== null) {
            $this->reader->cancel();
            $this->reader = null;
        }

        $this->restoreTerminal();
    }

    public function __destruct()
    {
        $this->close();
    }

    // ── Rendering primitives ───────────────────────────────────────────────────

    /**
     * Full redraw of the fixed chrome (banner + scroll region + separators +
     * status + input + bottom bar) for the current layout fields.
     */
    private function draw(): void
    {
        fwrite(
            STDOUT,
            "\033[?25l"
            . "\033[2J\033[H"
            . $this->banner
            . "\033[{$this->chatStart};{$this->chatRows}r"
            . "\033[{$this->statusRow};1H\033[2K"
            . "\033[{$this->sepTopRow};1H\033[2K" . self::C_SEP . $this->sep . self::C_RESET
            . "\033[{$this->inputRow};1H\033[2K"
            . "\033[{$this->sepBotRow};1H\033[2K" . self::C_SEP . $this->sep . self::C_RESET
            . "\033[{$this->barRow};1H\033[2K"
            . "\033[{$this->chatStart};1H"
            . "\033[?25h"
        );

        $this->writeBar();
        $this->renderHistory();
        fflush(STDOUT);
    }

    /** Recompute layout for the current $rows/$cols and repaint. */
    private function relayout(): void
    {
        $this->buildLayout();
        $this->draw();

        if ($this->awaitingInput) {
            fwrite(STDOUT, "\033[{$this->inputRow};1H");   // keep the cursor on the input row
            fflush(STDOUT);
        }
    }

    /**
     * Bottom slot bar: token usage left, a reserved slot right (model/state/
     * progress — no semantic source yet). Right-aligned by display width.
     */
    private function writeBar(): void
    {
        $left  = $this->tokens === '' ? '' : self::C_TOKENS . $this->tokens . self::C_RESET;
        $right = '';
        $used  = mb_strwidth(self::stripAnsi($left)) + mb_strwidth(self::stripAnsi($right));
        $gap   = max(1, $this->cols - $used);

        fwrite(STDOUT, "\033[s\033[{$this->barRow};1H\033[2K" . $left . str_repeat(' ', $gap) . $right . self::C_RESET . "\033[u");
        fflush(STDOUT);
    }

    private static function stripAnsi(string $s): string
    {
        return preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $s) ?? $s;
    }

    private function writeStatus(string $text): void
    {
        fwrite(STDOUT, "\033[s\033[{$this->statusRow};1H\033[2K{$text}" . self::C_RESET . "\033[u");
        fflush(STDOUT);
    }

    private function writeChat(string $text): void
    {
        fwrite(STDOUT, "\033[{$this->chatRows};1H{$text}" . self::C_RESET);
        fflush(STDOUT);
    }

    /** Append a chat line to the scroll region and remember it for redraws. */
    private function appendChat(string $line): void
    {
        $this->history[] = $line;

        if (\count($this->history) > self::HISTORY_MAX) {
            $this->history = \array_slice($this->history, -self::HISTORY_MAX);
        }

        $this->writeChat($line);

        // Return the cursor to the input row: the background reader waits there,
        // and cooked-mode echo must land on the input line, not in the history.
        fwrite(STDOUT, "\033[{$this->inputRow};1H");
        fflush(STDOUT);
    }

    /** Replay buffered history into the scroll region (after a full redraw). */
    private function renderHistory(): void
    {
        $height = $this->chatRows - $this->chatStart + 1;
        foreach (\array_slice($this->history, -$height) as $line) {
            fwrite(STDOUT, "\033[{$this->chatRows};1H{$line}" . self::C_RESET);
        }
    }

    private function cancelSpinner(): void
    {
        if ($this->spinner !== null) {
            $this->spinner->cancel();
            $this->spinner = null;
        }
    }

    private function cancelResizeWatcher(): void
    {
        if ($this->resizeWatcher !== null) {
            $this->resizeWatcher->cancel();
            $this->resizeWatcher = null;
        }
    }

    /** Re-detect the terminal size every ~200ms and relayout when it changes. */
    private function watchResize(): void
    {
        while (true) { // @phpstan-ignore while.alwaysTrue (infinite loop; cancelled via cancelResizeWatcher())
            delay(200);
            $this->syncSize();
        }
    }

    private function syncSize(): void
    {
        [$rows, $cols] = self::detectSize();
        if ($rows !== $this->rows || $cols !== $this->cols) {
            $this->rows = $rows;
            $this->cols = $cols;
            $this->relayout();
        }
    }

    /** Spinner coroutine: animate the status label. Cancelled by cancelSpinner(). */
    private function spin(): void
    {
        static $frames = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
        $frame = 0;

        // Cancelled via cancelSpinner() — the runtime unwinds the cancellation.
        while (true) { // @phpstan-ignore while.alwaysTrue
            $this->writeStatus(
                self::C_SPIN . $frames[$frame % 10] . self::C_RESET . self::C_DIM . $this->statusLabel . self::C_RESET
            );

            $frame++;
            delay(100);
        }
    }

    private function restoreTerminal(): void
    {
        fwrite(STDOUT, "\033[0m\033[r\033[{$this->rows};1H\n");
        fflush(STDOUT);
    }

    /**
     * @return array{int,int}  [rows, cols]
     */
    private static function detectSize(): array
    {
        // Windows: the real, live window size via WinAPI (FFI). Unlike
        // readline_info (static here), this refreshes on every resize.
        if (PHP_OS_FAMILY === 'Windows') {
            $size = self::winConsoleSize();
            if ($size !== null) {
                return $size;
            }
        }

        // Fallback (non-Windows, or FFI/console unavailable):
        $rows = (int)(getenv('LINES') ?: 0);
        $cols = (int)(getenv('COLUMNS') ?: 0);

        // Linux/macOS: `stty size` prints "rows cols" for the tty — live on resize.
        if (($rows < 4 || $cols < 20) && PHP_OS_FAMILY !== 'Windows') {
            $out = @shell_exec('stty size 2>/dev/null');
            if (is_string($out) && preg_match('/^(\d+)\s+(\d+)/', trim($out), $m)) {
                $rows = (int) $m[1];
                $cols = (int) $m[2];
            }
        }

        // Last resort: the readline extension, if it is loaded.
        if (($rows < 4 || $cols < 20) && function_exists('readline_info')) {
            $w = (int)(readline_info('terminal_width') ?: 0);
            $h = (int)(readline_info('terminal_height') ?: 0);

            if ($w > 20) {
                $cols = $w;
            }

            if ($h > 4) {
                $rows = $h;
            }
        }

        return [max(8, $rows ?: 30), max(40, $cols ?: 120)];
    }

    /**
     * Live console window size on Windows via GetConsoleScreenBufferInfo.
     * Returns null on any failure (FFI off, not attached to a real console).
     *
     * @return array{int,int}|null  [rows, cols]
     */
    private static function winConsoleSize(): ?array
    {
        static $k = null;

        try {
            if ($k === null) {
                $k = \FFI::cdef(
                    'typedef void* HANDLE;'
                    . 'typedef unsigned short WORD; typedef short SHORT;'
                    . 'typedef struct { SHORT X; SHORT Y; } COORD;'
                    . 'typedef struct { SHORT Left; SHORT Top; SHORT Right; SHORT Bottom; } SMALL_RECT;'
                    . 'typedef struct { COORD a; COORD b; WORD c; SMALL_RECT w; COORD d; } CONSOLE_SCREEN_BUFFER_INFO;'
                    . 'HANDLE GetStdHandle(unsigned long nStdHandle);'
                    . 'int GetConsoleScreenBufferInfo(HANDLE h, CONSOLE_SCREEN_BUFFER_INFO* info);',
                    'kernel32.dll'
                );
            }
            $info = $k->new('CONSOLE_SCREEN_BUFFER_INFO');
            if (!$k->GetConsoleScreenBufferInfo($k->GetStdHandle(0xFFFFFFF5), \FFI::addr($info))) {
                return null;   // STD_OUTPUT_HANDLE = (DWORD)-11
            }
            $cols = $info->w->Right - $info->w->Left + 1;
            $rows = $info->w->Bottom - $info->w->Top + 1;
            return ($cols < 20 || $rows < 4) ? null : [$rows, $cols];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Compute the fixed-chrome row positions, separator and banner into fields. */
    private function buildLayout(): void
    {
        // Bottom chrome (5 fixed rows): status / ─── / input / ─── / bar.
        $this->chatStart = 4;
        $this->statusRow = $this->rows - 4;
        $this->sepTopRow = $this->rows - 3;
        $this->inputRow  = $this->rows - 2;
        $this->sepBotRow = $this->rows - 1;
        $this->barRow    = $this->rows;
        $this->chatRows  = $this->rows - 5;   // last row of the scroll region
        $this->sep       = str_repeat('─', $this->cols);

        $php    = PHP_VERSION;
        $async  = phpversion('true_async') ?: 'dev';
        $inner  = "  PHP Claw  |  PHP {$php}  |  TrueAsync {$async}  ";
        $padded = mb_str_pad($inner, $this->cols - 2, ' ', STR_PAD_BOTH);
        $top    = '╔' . str_repeat('═', $this->cols - 2) . '╗';
        $mid    = '║' . $padded . '║';
        $bot    = '╚' . str_repeat('═', $this->cols - 2) . '╝';
        $this->banner = self::C_BANNER . $top . "\n" . $mid . "\n" . $bot . self::C_RESET . "\n";
    }
}
