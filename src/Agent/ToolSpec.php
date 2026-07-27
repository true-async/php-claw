<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A tool advertised to the model (no handler — execution is the Executor's job).
 * Built from a ToolInterface; `inputSchema` is JSON Schema.
 */
final class ToolSpec
{
    /** @var array<string, mixed> the JSON Schema, with empty `properties` coerced to an object */
    public readonly array $inputSchema;

    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        array $inputSchema,
    ) {
        $this->inputSchema = self::objectifyEmptyProperties($inputSchema);
    }

    /**
     * JSON Schema requires `properties` to be an OBJECT; an empty PHP array json-encodes to `[]`, which
     * the OpenAI and Claude schema validators reject ("[] is not of type object"). A no-parameter tool
     * naturally writes `'properties' => []`, so every empty `properties` is coerced to `{}` here, once —
     * rather than each such tool having to remember the stdClass trick. Found by a live run: `run_tests`
     * took the whole exchange down at the API before this.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function objectifyEmptyProperties(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && $value === []) {
                $schema[$key] = new \stdClass();
            } elseif (\is_array($value)) {
                $schema[$key] = self::objectifyEmptyProperties($value);
            }
        }

        return $schema;
    }
}
