<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Evaluate a single PHP expression (one function call) and return its value.
 *
 * Dangerous: this runs arbitrary PHP in-process. It is gated by the permission
 * layer; intended for quick calculations like `strtoupper("hi")` or `gmdate(...)`.
 */
final readonly class PhpEvalTool implements ToolInterface
{
    public function name(): string
    {
        return 'php_eval';
    }

    public function description(): string
    {
        return 'Evaluate a single PHP expression and return its value, e.g. strlen("abc") or 2 ** 10. Runs arbitrary PHP.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string', 'description' => 'A single PHP expression to evaluate (no trailing statements)'],
            ],
            'required' => ['code'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Dangerous;
    }

    public function handle(array $input): string
    {
        $code = trim((string) ($input['code'] ?? ''));

        if ($code === '') {
            throw new ToolException('php_eval: "code" is required');
        }

        try {
            $result = eval('return ' . rtrim($code, "; \t\n\r") . ';');
        } catch (\Throwable $e) {
            throw new ToolException('php_eval error: ' . $e->getMessage());
        }

        return is_string($result) ? $result : var_export($result, true);
    }
}
