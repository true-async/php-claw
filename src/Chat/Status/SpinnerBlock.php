<?php

declare(strict_types=1);

namespace Claw\Chat\Status;

final class SpinnerBlock implements StatusBlockInterface
{
    private static int $frame = 0;
    private const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public function render(): string
    {
        $char = self::FRAMES[self::$frame % \count(self::FRAMES)];
        self::$frame++;

        return $char;
    }
}
