<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;

/** A minimal named tool for tests. */
class StubTool implements ToolInterface
{
    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'a stub tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        return 'ok';
    }
}
