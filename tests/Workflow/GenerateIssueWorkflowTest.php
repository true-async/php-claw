<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\AgentResponse;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\DefineWorkflowTool;
use Claw\Tool\ListFilesTool;
use Claw\Tool\ReadFileTool;
use Claw\Tool\Registry;
use Claw\Tool\Workspace;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\GenerateIssueWorkflow;
use Claw\Workflow\InMemoryStateStore;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedAgent;

final class GenerateIssueWorkflowTest
{
    #[Test]
    public function generatesAndSavesASolverWorkflowForTheIssue(): void
    {
        $dir = self::tempDir();

        try {
            $store = new WorkflowStore($dir . '/workflows', 'p1');
            $registry = new Registry();
            $workspace = new Workspace($dir);
            $registry->add(new ReadFileTool($workspace));
            $registry->add(new ListFilesTool($workspace));
            $registry->add(new DefineWorkflowTool($store, new WorkflowValidator()));

            // Each step calls ai(); between steps the engine forms a handoff (continuing the step's
            // conversation). draft is critic-gated, so after the code comes the solverReview critic's
            // verdict: plan, [handoff], difficulty, [handoff], code, OK(critic). save makes no model call.
            $agent = new ScriptedAgent(
                self::answer('Plan: read the file, then summarize it.'),
                self::answer('handoff after understand'),
                self::answer('simple — a localized, mechanical change.'),
                self::answer('handoff after assess'),
                self::answer(self::solverCode('Issue7Solver')),
                self::answer('OK'),   // the solverReview critic passes the drafted solver
            );

            $env = new Environment()
                ->set(EnvKey::Worker, $agent)
                ->set(EnvKey::Registry, $registry)
                ->set(EnvKey::ModelId, 'm')
                ->set(EnvKey::SystemPrompt, '')
                ->set(EnvKey::Store, new InMemoryStateStore());

            $issue = new Issue('7', 'p1', 'Summarize the README');
            $project = new Project('p1', 'Demo');

            new GenerateIssueWorkflow($env, 'gen', [
                'solverName' => 'Issue7Solver',
                'solverNamespace' => 'ClawWorkflow\\Common',
                'solverTools' => ['read_file', 'write_file', 'list_files', 'bash'],
            ], $issue, $project)->run();

            $saved = $store->path('Issue7Solver', true);
            Assert::true(is_file($saved));
            Assert::true(str_contains((string) file_get_contents($saved), 'class Issue7Solver'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * The draft prompt must carry the TICKET, not only the plan.
     *
     * It used to open "Write a PHP class that solves the task below" with no task below it: the only
     * description of the work reaching the drafting model was understand()'s paraphrase, so anything the
     * plan happened to drop was gone. The critic judging the draft did get the real ticket, which left
     * the reviewer better informed than the author.
     */
    #[Test]
    public function theDraftPromptCarriesTheTicketAndNotOnlyThePlan(): void
    {
        $dir = self::tempDir();

        try {
            $agent = self::generate($dir, 'Issue8Solver', 'simple', new Issue(
                '8',
                'p1',
                'Fix the CSV export',
                'Rows with an embedded comma are written unquoted.',
            ));

            // request #5 is the draft call (plan, handoff, difficulty, handoff, DRAFT, critic)
            $draft = self::textOf($agent, 4);
            Assert::true(str_contains($draft, 'Fix the CSV export'));
            Assert::true(str_contains($draft, 'Rows with an embedded comma are written unquoted.'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * "Complexity: simple" is a simple task.
     *
     * The verdict was read by testing the first token for the SUBSTRING 'complex' before 'simple', so
     * that entirely ordinary reply tokenized to "complexity:", matched 'complex', and routed a trivial
     * change to the expensive model — the check written to protect the classification inverting it.
     */
    #[Test]
    public function aLabelledDifficultyIsNotReadAsItsOwnOpposite(): void
    {
        $dir = self::tempDir();

        try {
            $agent = self::generate($dir, 'Issue9Solver', 'Complexity: simple');

            // The difficulty is folded into the draft prompt, so that is where the verdict shows. A line
            // that is not one of the three bare words lands on `moderate` — the middle, which cannot be
            // wrong in an expensive direction. What matters is that it is no longer read as `complex`.
            $draft = self::textOf($agent, 4);
            Assert::false(str_contains($draft, 'assessed as **complex**'));
            Assert::true(str_contains($draft, 'assessed as **moderate**'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * The reviewer of a generated solver must be able to reject it on substance, and must be given what
     * the author was given.
     *
     * The rubric said "judge whether this class will ACTUALLY solve the task" and then closed the reject
     * list to three formal defects, ending "Otherwise reply exactly: OK" — so a solver that edited the
     * wrong file, or did blind string surgery on source (the sin the draft prompt spends a section
     * forbidding), matched none of them and had to be passed. One of the three named "the recipe", a
     * document the critic was never handed.
     */
    #[Test]
    public function theSolverReviewCanRejectOnSubstanceAndSeesWhatTheAuthorSaw(): void
    {
        $dir = self::tempDir();

        try {
            $agent = self::generate($dir, 'Issue10Solver', 'simple');

            // request #6 is the critic (plan, handoff, difficulty, handoff, draft, CRITIC)
            $review = self::textOf($agent, 5);

            Assert::false(str_contains($review, 'REJECT only if'));   // the closed list is gone
            Assert::true(str_contains($review, 'Plan: change the thing.'));            // the plan
            Assert::true(str_contains($review, 'HOW TO DECIDE THE STEPS'));            // the recipe
            Assert::true(str_contains($review, 'Do a thing'));                         // the ticket
            Assert::true(str_contains($review, 'does not address the task'));          // a substantive cause
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * A chosen approach reaches the model that writes the solver, and sits BESIDE the general recipe
     * rather than replacing it.
     *
     * The two answer different questions: the constant recipe says what a step is and how few to use —
     * mechanics, identical for every ticket — while the approach says what work of this kind has to
     * accomplish. Folding a per-type procedure into the machinery is exactly how the old fixed
     * seven-phase recipe came to stamp ceremony onto one-file changes.
     */
    #[Test]
    public function aChosenApproachIsHandedToTheDrafterAlongsideTheGeneralRecipe(): void
    {
        $dir = self::tempDir();

        try {
            $agent = self::generate($dir, 'Issue11Solver', 'simple', recipe: 'PROVE IT RED FIRST, then build.');

            $draft = self::textOf($agent, 4);
            Assert::true(str_contains($draft, 'THE APPROACH TO FOLLOW'));
            Assert::true(str_contains($draft, 'PROVE IT RED FIRST, then build.'));
            Assert::true(str_contains($draft, 'HOW TO DECIDE THE STEPS'));   // the general recipe stays
        } finally {
            self::rmrf($dir);
        }
    }

    /** With no approach chosen the block is absent entirely — not an empty heading the model must read. */
    #[Test]
    public function noApproachMeansNoApproachSection(): void
    {
        $dir = self::tempDir();

        try {
            $agent = self::generate($dir, 'Issue12Solver', 'simple');

            Assert::false(str_contains(self::textOf($agent, 4), 'THE APPROACH TO FOLLOW'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * Run the generator end to end against a scripted model and hand back the agent, so a test can read
     * the exact prompts it was sent. $verdict is assess()'s reply — the line whose parsing is under test.
     */
    private static function generate(string $dir, string $class, string $verdict, ?Issue $issue = null, string $recipe = ''): ScriptedAgent
    {
        $store = new WorkflowStore($dir . '/workflows', 'p1');
        $registry = new Registry();
        $workspace = new Workspace($dir);
        $registry->add(new ReadFileTool($workspace));
        $registry->add(new ListFilesTool($workspace));
        $registry->add(new DefineWorkflowTool($store, new WorkflowValidator()));

        $agent = new ScriptedAgent(
            self::answer('Plan: change the thing.'),
            self::answer('handoff after understand'),
            self::answer($verdict),
            self::answer('handoff after assess'),
            self::answer(self::solverCode($class)),
            self::answer('OK'),
        );

        $env = new Environment()
            ->set(EnvKey::Worker, $agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, 'm')
            ->set(EnvKey::SystemPrompt, '')
            ->set(EnvKey::Store, new InMemoryStateStore());

        new GenerateIssueWorkflow($env, 'gen', [
            'solverName' => $class,
            'solverNamespace' => 'ClawWorkflow\\Common',
            'solverTools' => ['read_file', 'write_file', 'list_files', 'bash'],
            'recipe' => $recipe,
        ], $issue ?? new Issue('9', 'p1', 'Do a thing'), new Project('p1', 'Demo'))->run();

        return $agent;
    }

    /** The text of the last user message in the agent's $nth recorded request. */
    private static function textOf(ScriptedAgent $agent, int $nth): string
    {
        $messages = $agent->requests[$nth]->messages;
        $key = array_key_last($messages) ?? throw new \RuntimeException('the request carried no message');
        $last = $messages[$key];
        $text = '';

        foreach ($last->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /** A valid solver class the WorkflowValidator accepts. */
    private static function solverCode(string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace ClawWorkflow\\Common;

            use Claw\\Workflow\\Step;
            use Claw\\Workflow\\WorkflowAbstract;

            final class {$class} extends WorkflowAbstract
            {
                private string \$summary = '';

                public function name(): string
                {
                    return 'solve';
                }

                #[Step]
                protected function summarize(): void
                {
                    \$this->summary = \$this->ai('Summarize the project.', ['read_file']);
                }
            }
            PHP;
    }

    private static function answer(string $text): AgentResponse
    {
        return new AgentResponse([new TextBlock($text)], [], StopReason::EndTurn, new Usage(), $text);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-genwf-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
