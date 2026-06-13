<?php

declare(strict_types=1);

namespace Claw\Chat\Status;

interface StatusBlockInterface
{
    public function render(): string;
}
