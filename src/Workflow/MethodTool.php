<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\ToolException;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;

/**
 * Adapts a workflow's {@see Tool}-marked method into a {@see ToolInterface}, so a plain method on a
 * workflow can be exposed to the model as a tool. The schema is derived from the method's typed
 * parameters; a call maps the model's named input onto those parameters and returns the method's
 * result as a string. The method runs in the workflow's own scope, so it reaches files/shell only
 * through {@see WorkflowAbstract::tool()} — a local tool is never more privileged than the workflow.
 */
final class MethodTool implements ToolInterface
{
    public function __construct(
        private readonly WorkflowAbstract $workflow,
        private readonly \ReflectionMethod $method,
        private readonly Tool $meta,
    ) {
    }

    public function name(): string
    {
        return $this->meta->name ?? self::toSnake($this->method->getName());
    }

    public function description(): string
    {
        return $this->meta->description;
    }

    public function inputSchema(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->method->getParameters() as $param) {
            $properties[$param->getName()] = ['type' => self::jsonType($param->getType())];
            if (!$param->isOptional()) {
                $required[] = $param->getName();
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /** A local tool acts only through the workflow's gated tool() path, so the wrapper itself is safe. */
    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $args = [];
        foreach ($this->method->getParameters() as $param) {
            $name = $param->getName();
            if (\array_key_exists($name, $input)) {
                $args[] = $input[$name];
            } elseif ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new ToolException("tool '{$this->name()}': missing required argument '{$name}'");
            }
        }

        try {
            $result = $this->method->invokeArgs($this->workflow, $args);
        } catch (\TypeError $e) {
            throw new ToolException("tool '{$this->name()}': bad arguments — {$e->getMessage()}");
        }

        if (\is_string($result)) {
            return $result;
        }

        return \is_scalar($result)
            ? (string) $result
            : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Map a PHP parameter type onto its JSON-schema type; unknown/untyped falls back to string. */
    private static function jsonType(?\ReflectionType $type): string
    {
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

        return match ($name) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }

    /** camelCase method name -> snake_case tool name, matching the project's tool-naming convention. */
    private static function toSnake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
