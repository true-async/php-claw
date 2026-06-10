<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Testo\Assert;
use Testo\Test;

final class RegistryTest
{
    #[Test]
    public function addGetHasAll(): void
    {
        $registry = new Registry();
        Assert::false($registry->has('noop'));

        $registry->add($this->noopTool());

        Assert::true($registry->has('noop'));
        Assert::same($registry->get('noop')->name(), 'noop');
        Assert::same(count($registry->all()), 1);
    }

    #[Test]
    public function unknownToolThrows(): void
    {
        $threw = false;
        try {
            (new Registry())->get('nope');
        } catch (ToolException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'Unknown tool'));
        }

        Assert::true($threw);
    }

    private function noopTool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function name(): string
            {
                return 'noop';
            }

            public function description(): string
            {
                return 'does nothing';
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
        };
    }
}
