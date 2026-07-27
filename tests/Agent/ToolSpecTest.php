<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\ToolSpec;
use Testo\Assert;
use Testo\Test;

final class ToolSpecTest
{
    /**
     * A no-parameter tool writes `'properties' => []`, which json-encodes to `[]` and the OpenAI/Claude
     * schema validators reject ("[] is not of type object"). A live run took the whole exchange down on
     * exactly this; ToolSpec coerces it to `{}`.
     */
    #[Test]
    public function emptyPropertiesAreCoercedToAnObject(): void
    {
        $spec = new ToolSpec('run_tests', 'runs tests', ['type' => 'object', 'properties' => []]);
        $json = (string) json_encode($spec->inputSchema);

        Assert::true(str_contains($json, '"properties":{}'));
        Assert::false(str_contains($json, '"properties":[]'));
    }

    /** Real properties stay an object, and `required` stays an ARRAY — the coercion is surgical. */
    #[Test]
    public function realPropertiesAndRequiredArraysAreUntouched(): void
    {
        $spec = new ToolSpec('edit', 'edits', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ]);
        $json = (string) json_encode($spec->inputSchema);

        Assert::true(str_contains($json, '"properties":{"path"'));
        Assert::true(str_contains($json, '"required":["path"]'));
    }

    /** Nested empty properties (e.g. an array item that is a bare object) are coerced too. */
    #[Test]
    public function nestedEmptyPropertiesAreCoerced(): void
    {
        $spec = new ToolSpec('t', 'd', [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]],
        ]);
        $json = (string) json_encode($spec->inputSchema);

        Assert::false(str_contains($json, '"properties":[]'));
    }
}
