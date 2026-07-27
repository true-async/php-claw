<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * `search_tools` — the model's door to the DEFERRED tools. A large palette does not ship every tool's full
 * schema in every request; the occasional ones ({@see DeferredToolInterface}) are withheld and only NAMED
 * in the briefing. The model describes what it needs — `search_tools("download a web page")` — and this
 * matches that intent against each deferred tool's tags, name and description, returns the winners' full
 * schemas, and (through {@see \Claw\Agent\DefaultTurnLoop}) makes them callable from the NEXT turn.
 * Everything NOT deferred is always fully present, so the common path never touches this.
 */
final readonly class ToolSearchTool implements ToolInterface
{
    public function __construct(private Registry $palette)
    {
    }

    public function name(): string
    {
        return 'search_tools';
    }

    public function description(): string
    {
        return 'Find and load a tool by describing what you want to do. The briefing NAMES the tools that '
            . 'are not yet loaded — they cannot be called until you find them here. Pass a `query` (e.g. '
            . '"fetch a url", "recall project notes", "run this later"); this returns the matching tools\' '
            . 'full schemas, and you can call them on your NEXT turn.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What you want to do, in a few words.'],
            ],
            'required' => ['query'],
        ];
    }

    public function effects(): array
    {
        return [Effect::Read];
    }

    public function risk(): Risk
    {
        return Risk::Safe;   // it only reveals schemas — no side effect
    }

    public function handle(array $input): string
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            throw new ToolException("search_tools: 'query' is required — describe what you need to do");
        }

        $names = $this->matches($query);

        if ($names === []) {
            return "No tool matched \"{$query}\". What is already loaded is all this step has — solve with that, or say you cannot.";
        }

        $sections = [];

        foreach ($names as $name) {
            $tool = $this->palette->get($name);
            $schema = json_encode($tool->inputSchema(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $sections[] = "LOADED `{$name}` — {$tool->description()}\n  input schema: {$schema}";
        }

        return "These tools are now callable on your NEXT turn:\n\n" . implode("\n\n", $sections);
    }

    /**
     * Rank the DEFERRED tools by how well $query hits their tags, name and description (a term is a hit if
     * it appears anywhere in that text). Deterministic — the turn loop re-runs it to learn which tools a
     * `search_tools` call loaded. Best match first; empty when nothing hits.
     *
     * @return list<string>
     */
    public function matches(string $query): array
    {
        $terms = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($query)) ?: []));

        if ($terms === []) {
            return [];
        }

        $scored = [];

        foreach ($this->palette->all() as $tool) {
            if (!$tool instanceof DeferredToolInterface) {
                continue;
            }

            $haystack = strtolower($tool->name() . ' ' . implode(' ', $tool->searchTags()) . ' ' . $tool->description());
            $score = 0;

            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    ++$score;
                }
            }

            if ($score > 0) {
                $scored[$tool->name()] = $score;
            }
        }

        arsort($scored);

        return array_keys($scored);
    }
}
