<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Effect;
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
            new Registry()->get('nope');
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

    /**
     * The counterpart to only(): narrowing by SUBTRACTION. It matters that both exist — an allow-list
     * has to be revisited every time a tool is added to the run, and nothing says when it was not, so a
     * scope that should see a new capability silently stops seeing anything new.
     */
    #[Test]
    public function exceptNarrowsBySubtractionAndKeepsWhatComesLater(): void
    {
        $registry = new Registry();
        $read = new StubTool('read');
        $registry->add($read);
        $registry->add(new StubTool('write'));
        $registry->add(new StubTool('bash'));

        $subset = $registry->except(['write']);

        Assert::count($subset->all(), 2);
        Assert::false($subset->has('write'));
        Assert::true($subset->has('read'));
        Assert::true($subset->has('bash'));
        Assert::same($subset->get('read'), $read);   // the same instance, not a copy
    }

    /** Subtracting something that is not there means believing you are protected when you are not. */
    #[Test]
    public function exceptThrowsOnAnUnknownName(): void
    {
        $registry = new Registry();
        $registry->add(new StubTool('read'));

        $threw = false;

        try {
            $registry->except(['ghost']);
        } catch (ToolException) {
            $threw = true;
        }

        Assert::true($threw);
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

    #[Test]
    public function exceptEffectDropsEveryToolThatCanWriteAndKeepsTheReadOnlyOnes(): void
    {
        $registry = new Registry();
        $registry->add($this->effectTool('reader', Effect::Read));
        $registry->add($this->effectTool('writer', Effect::Write));
        $registry->add($this->effectTool('both', Effect::Read, Effect::Write));

        $kept = array_map(
            static fn (ToolInterface $t): string => $t->name(),
            $registry->exceptEffect(Effect::Write)->all(),
        );

        Assert::same($kept, ['reader']);   // writer and both are out — anything that can write is denied
    }

    #[Test]
    public function exceptEffectKeepsAToolThatDeclaresNoEffectAtAll(): void
    {
        $registry = new Registry();
        $registry->add($this->effectTool('reader', Effect::Read));
        $registry->add($this->effectTool('effectless'));   // empty effects: it denies nothing, so it stays

        $kept = array_map(
            static fn (ToolInterface $t): string => $t->name(),
            $registry->exceptEffect(Effect::Write)->all(),
        );

        Assert::same($kept, ['reader', 'effectless']);
    }

    private function effectTool(string $name, Effect ...$effects): ToolInterface
    {
        return new class ($name, array_values($effects)) implements ToolInterface {
            /** @param list<Effect> $effects */
            public function __construct(private readonly string $toolName, private readonly array $effects)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'x';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function effects(): array
            {
                return $this->effects;
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

            public function effects(): array
            {
                return [Effect::Read, Effect::Write];
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
