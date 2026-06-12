<?php

declare(strict_types=1);

namespace Claw\Chat;

use Claw\Agent\Usage;
use Claw\Chat\Status\SpinnerBlock;
use Claw\Chat\Status\StatusBlockInterface;
use Claw\Chat\Status\TextBlock;
use Claw\Chat\Status\TokenUsageBlock;
use Claw\Chat\Status\ToolCallBlock;

/**
 * A composable status line: one or more blocks rendered side by side.
 */
final class Status
{
    /**
     * @param list<StatusBlockInterface> $blocks
     * @param bool $animated True = keep re-rendering in a loop (spinner); false = write once.
     */
    public function __construct(
        private readonly array $blocks,
        public readonly bool $animated = false,
    ) {}

    public static function typing(string $message = 'Thinking…'): self
    {
        return new self([new SpinnerBlock(), new TextBlock(' ' . $message)], animated: true);
    }

    public static function toolCall(string $name): self
    {
        return new self([new SpinnerBlock(), new ToolCallBlock($name)], animated: true);
    }

    public static function done(Usage $usage): self
    {
        return new self([new TokenUsageBlock($usage)]);
    }

    public function render(): string
    {
        return implode('', array_map(static fn(StatusBlockInterface $b) => $b->render(), $this->blocks));
    }

    /**
     * Text for the terminal thread: animated statuses skip the SpinnerBlock
     * (the thread animates its own spinner), static statuses return the full render.
     */
    public function label(): string
    {
        $blocks = $this->animated
            ? array_filter($this->blocks, static fn(StatusBlockInterface $b) => !($b instanceof SpinnerBlock))
            : $this->blocks;

        return implode('', array_map(static fn(StatusBlockInterface $b) => $b->render(), $blocks));
    }
}
