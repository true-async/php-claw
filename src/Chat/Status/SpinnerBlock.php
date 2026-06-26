<?php

declare(strict_types=1);

namespace Claw\Chat\Status;

final class SpinnerBlock implements StatusBlockInterface
{
    private static int $frame = 0;

    /** The braille spinner frames — the single source for any spinner animation (also the async console's). */
    public const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public function render(): string
    {
        $char = self::FRAMES[self::$frame % \count(self::FRAMES)];
        self::$frame++;

        return $char;
    }
}
