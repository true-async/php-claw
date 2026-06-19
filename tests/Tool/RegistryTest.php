<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Testo\Assert;
use Testo\Test;
use Tests\Support\StubAgentTool;
use Tests\Support\StubTool;

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

    #[Test]
    public function agentsReturnsOnlyTheAgentTools(): void
    {
        $registry = new Registry();
        $registry->add(new StubTool('bash'));
        $registry->add(new StubAgentTool('reviewer'));
        $registry->add(new StubTool('read'));

        $agents = $registry->agents();

        Assert::count($agents, 1);
        Assert::same($agents[0]->name(), 'reviewer');
        Assert::same(count($registry->all()), 3);   // all() still returns every tool
    }

    #[Test]
    public function specsAdvertisesEveryRegisteredTool(): void
    {
        $registry = new Registry();
        $registry->add(new StubTool('read'));
        $registry->add(new StubTool('bash'));

        $specs = $registry->specs();   // narrowing is only()'s job; specs() advertises the whole registry

        Assert::count($specs, 2);
        Assert::same($specs[0]->name, 'read');
        Assert::same($specs[1]->name, 'bash');
    }

    #[Test]
    public function onlyNarrowsToASubsetSharingInstances(): void
    {
        $registry = new Registry();
        $read = new StubTool('read');
        $registry->add($read);
        $registry->add(new StubTool('write'));
        $registry->add(new StubTool('bash'));

        $subset = $registry->only(['read', 'bash']);

        Assert::count($subset->all(), 2);
        Assert::true($subset->has('read'));
        Assert::true($subset->has('bash'));
        Assert::false($subset->has('write'));        // narrowed: write cannot be resolved, so cannot be run
        Assert::same($subset->get('read'), $read);   // the same instance, not a copy
    }

    #[Test]
    public function onlyThrowsOnAnUnknownName(): void
    {
        $registry = new Registry();
        $registry->add(new StubTool('read'));

        $threw = false;
        try {
            $registry->only(['read', 'ghost']);
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
