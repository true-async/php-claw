<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Workflow\Step;
use Claw\Workflow\WorkflowAbstract;

/**
 * A one-step workflow whose step carries a critic, for exercising the review/supervision loop.
 *
 * Named rather than anonymous so a helper can hand it back with its fields still visible to the type
 * checker — the cases that drive it care about how many times the step ran and what guidance it saw.
 */
final class CriticStepWorkflow extends WorkflowAbstract
{
    public string $work = '';

    public int $runs = 0;

    public ?string $sawCritique = null;

    public function name(): string
    {
        return 'crt';
    }

    protected function criticRules(): array
    {
        return ['must be tested' => 'the work must include a test'];
    }

    #[Step(critic: 'must be tested')]
    protected function make(): void
    {
        $this->runs++;
        $this->sawCritique = $this->critique();
        $this->work = 'v' . $this->runs;        // a distinct result per attempt, so a re-run is not byte-identical
        $this->artifact('work', $this->work);   // what the critic AND the supervisor judge
    }
}
