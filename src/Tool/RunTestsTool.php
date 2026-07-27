<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Find and run the project's test suite — and RETURN what it printed. It exists because working out HOW a
 * project runs its tests is a recurring stumble: a live supervisor guessed `phpunit`, could not find it,
 * and gave up on work that was fine. This tool encodes the discovery once (a composer `test` script, then
 * a vendored phpunit, then a phpunit config) so the model does not have to rediscover it every time.
 *
 * Effect is Read for palette purposes: it runs the suite to OBSERVE the outcome, it does not change the
 * source under review — so a reviewer may hold it. What the tests themselves do is their own business.
 */
final readonly class RunTestsTool implements ToolInterface
{
    public function __construct(
        private Workspace $workspace,
        private ?Secrets $secrets = null,
        private int $timeoutMs = 0,
    ) {
    }

    public function name(): string
    {
        return 'run_tests';
    }

    public function description(): string
    {
        return 'Find and run the project\'s test suite (a composer "test" script, else vendor/bin/phpunit, '
            . 'else a phpunit config) and return its output and pass/fail. If the project runs its tests '
            . 'some other way, run that with bash instead.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function effects(): array
    {
        return [Effect::Read];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $root = $this->workspace->root();
        $command = $this->discover($root);

        if ($command === null) {
            throw new ToolException(
                'run_tests: could not find a test runner — no composer "test" script, no vendor/bin/phpunit, '
                . 'no phpunit.xml(.dist). Run the project\'s own test command with bash.',
            );
        }

        $shell = new BashTool($root, $this->secrets, $this->timeoutMs);

        return "ran `{$command}`:\n" . $shell->handle(['command' => $command]);
    }

    /** The first test runner this project actually has, or null. */
    private function discover(string $root): ?string
    {
        $composer = $root . '/composer.json';

        if (is_file($composer)) {
            $json = json_decode((string) file_get_contents($composer), true);

            if (\is_array($json) && isset($json['scripts']['test'])) {
                return 'composer test';
            }
        }

        if (is_file($root . '/vendor/bin/phpunit')) {
            return './vendor/bin/phpunit';
        }

        if (is_file($root . '/phpunit.xml') || is_file($root . '/phpunit.xml.dist')) {
            return 'phpunit';
        }

        return null;
    }
}
