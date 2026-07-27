<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Check code for errors WITHOUT running it — a syntax check of one file (`php -l`), or the project's own
 * static analysis when no path is given (phpstan, or a composer analyse/lint script). A cheap, first-class
 * verification a step can run and record as evidence, or a reviewer can run to judge — the fast half of
 * "does this work" that does not need the whole suite.
 */
final readonly class LintTool implements ToolInterface
{
    /** composer script names, in order, that mean "analyse this project". */
    private const array ANALYSE_SCRIPTS = ['analyse', 'analyze', 'phpstan', 'stan', 'lint', 'cs'];

    public function __construct(
        private Workspace $workspace,
        private ?Secrets $secrets = null,
        private int $timeoutMs = 0,
    ) {
    }

    public function name(): string
    {
        return 'lint';
    }

    public function description(): string
    {
        return 'Check code for errors without running it. With "path" to a .php file: a syntax check '
            . '(php -l). Without a path: the project\'s static analysis (phpstan, or a composer '
            . 'analyse/lint script). Returns the checker\'s output and pass/fail.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'A .php file to syntax-check; omit to run project static analysis'],
            ],
        ];
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
        $shell = new BashTool($root, $this->secrets, $this->timeoutMs);
        $path = trim((string) ($input['path'] ?? ''));

        if ($path !== '') {
            $this->workspace->resolveExisting($path);   // inside the workspace, exists, not a secret

            if (!str_ends_with($path, '.php')) {
                throw new ToolException("lint: a per-file check handles .php files (php -l); got {$path}");
            }

            return $shell->handle(['command' => 'php -l ' . escapeshellarg($path)]);
        }

        if (is_file($root . '/vendor/bin/phpstan')) {
            return "phpstan:\n" . $shell->handle(['command' => './vendor/bin/phpstan analyse --no-progress --no-interaction']);
        }

        $script = $this->analyseScript($root);

        if ($script !== null) {
            return "composer {$script}:\n" . $shell->handle(['command' => "composer {$script}"]);
        }

        throw new ToolException(
            'lint: no project analyser found. Pass "path" to a .php file for a syntax check, or add phpstan '
            . 'or a composer analyse/lint script.',
        );
    }

    private function analyseScript(string $root): ?string
    {
        $composer = $root . '/composer.json';

        if (!is_file($composer)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($composer), true);
        $scripts = \is_array($json) && \is_array($json['scripts'] ?? null) ? $json['scripts'] : [];

        foreach (self::ANALYSE_SCRIPTS as $name) {
            if (isset($scripts[$name])) {
                return $name;
            }
        }

        return null;
    }
}
