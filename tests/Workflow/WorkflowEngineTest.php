<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Workflow\Critic;
use Claw\Workflow\RetryPolicy;
use Claw\Workflow\Score;
use Claw\Workflow\Step;
use Claw\Workflow\StepExecutor;
use Claw\Workflow\StepOutcome;
use Claw\Workflow\StepSpec;
use Claw\Workflow\WorkflowEngine;
use Testo\Assert;
use Testo\Test;

final class WorkflowEngineTest
{
    #[Test]
    public function proceedsOnAGoodScore(): void
    {
        $engine = new WorkflowEngine($this->executor(), $this->critic([new Score(5)]));

        $result = $engine->runStep(new StepSpec('do the thing'), 1);

        Assert::same($result->outcome, StepOutcome::Proceed);
        Assert::same($result->artifacts['done'] ?? null, true);
    }

    #[Test]
    public function retriesThenEscalatesAfterMaxAttempts(): void
    {
        $critic = $this->critic([new Score(1), new Score(1)]);   // never good enough
        $engine = new WorkflowEngine($this->executor(), $critic, new RetryPolicy(passThreshold: 3, maxAttempts: 2));

        $result = $engine->runStep(new StepSpec('flaky step'), 1);

        Assert::same($result->outcome, StepOutcome::AwaitHuman);
    }

    #[Test]
    public function escalatesWhenCriticAdvisesHuman(): void
    {
        $engine = new WorkflowEngine($this->executor(), $this->critic([new Score(4, adviseHuman: true)]));

        $result = $engine->runStep(new StepSpec('needs a human'), 1);

        Assert::same($result->outcome, StepOutcome::AwaitHuman);
    }

    private function executor(): StepExecutor
    {
        return new class () implements StepExecutor {
            public function execute(Step $step): void
            {
                $step->artifacts = ['done' => true];
            }
        };
    }

    /** @param list<Score> $scores */
    private function critic(array $scores): Critic
    {
        return new class ($scores) implements Critic {
            /** @param list<Score> $scores */
            public function __construct(private array $scores)
            {
            }

            public function evaluate(Step $step): Score
            {
                return array_shift($this->scores) ?? new Score(5);
            }
        };
    }
}
