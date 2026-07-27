<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Find where a PHP symbol is DEFINED — a class, interface, trait, enum, function, method, or const, by
 * name. The cheap symbol tier: no ctags, no language server, no on-disk index — a definition-aware search
 * over the project's .php files, so it answers "where is X defined" precisely where plain {@see GrepTool}
 * would also return every mention. Read-only.
 *
 * It is deliberately a DEFINITION finder, not a references/callers engine — that (type-resolved) needs a
 * PHP-aware indexer or an LSP, which is a separate, heavier track. This closes the common, cheap case.
 */
final readonly class FindDefinitionTool implements ToolInterface
{
    private const array SKIP_DIRS = ['.git', '.svn', '.hg', 'vendor', 'node_modules', 'dist', 'build', '.idea'];

    public function __construct(
        private Workspace $workspace,
        private int $maxMatches = 100,
    ) {
    }

    public function name(): string
    {
        return 'find_definition';
    }

    public function description(): string
    {
        return 'Find where a PHP symbol is DEFINED — a class, interface, trait, enum, function, method or '
            . 'const — by name. Returns path:line: the definition line. Use this to jump to a definition; '
            . 'it is more precise than grep, which also returns every use. (For "who USES/calls X", use '
            . 'grep — reference resolution is not this tool.)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The symbol name to find the definition of'],
            ],
            'required' => ['name'],
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
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new ToolException('find_definition: "name" is required');
        }

        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new ToolException("find_definition: \"{$name}\" is not a PHP symbol name");
        }

        $regex = self::definitionRegex(ltrim($name, '\\'));
        $base = $this->workspace->root();
        $matches = [];
        $truncated = false;

        foreach ($this->walk($base) as $file) {
            $rel = ltrim(str_replace($base, '', $file), \DIRECTORY_SEPARATOR);

            try {
                $safe = $this->workspace->resolveExisting($rel);   // confinement + secret guard
            } catch (ToolException) {
                continue;
            }

            $handle = @fopen($safe, 'r');

            if ($handle === false) {
                continue;
            }

            $line = 0;

            while (($text = fgets($handle)) !== false) {
                ++$line;

                if (preg_match($regex, $text) !== 1) {
                    continue;
                }

                if (\count($matches) >= $this->maxMatches) {
                    $truncated = true;

                    break;
                }

                $matches[] = "{$rel}:{$line}: " . trim($text);
            }

            fclose($handle);

            if ($truncated) {
                break;
            }
        }

        if ($matches === []) {
            return "no definition of {$name} found";
        }

        $out = implode("\n", $matches);

        return $truncated ? $out . "\n... [truncated at {$this->maxMatches} matches]" : $out;
    }

    /** Match a DEFINITION of $name: a type declaration, a function/method, or a const of that name. */
    private static function definitionRegex(string $name): string
    {
        $n = preg_quote($name, '~');

        return '~\b(?:'
            . "(?:final\\s+|abstract\\s+|readonly\\s+)*class\\s+{$n}\\b"
            . "|interface\\s+{$n}\\b"
            . "|trait\\s+{$n}\\b"
            . "|enum\\s+{$n}\\b"
            . "|function\\s+{$n}\\s*\\("
            . "|const\\s+{$n}\\b"
            . ')~';
    }

    /**
     * Every .php file under $root, skipping the dependency and VCS trees. A generator so a huge repo is
     * walked lazily and the match cap can stop it early.
     *
     * @return \Generator<string>
     */
    private function walk(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($f->isDir() && \in_array($f->getFilename(), self::SKIP_DIRS, true)),
            ),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                yield $entry->getPathname();
            }
        }
    }
}
