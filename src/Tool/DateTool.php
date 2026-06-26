<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Return the current date and time. Safe (read-only). Useful because the model
 * has no clock of its own.
 */
final readonly class DateTool implements ToolInterface
{
    public function name(): string
    {
        return 'current_date';
    }

    public function description(): string
    {
        return 'Get the current date and time. Optional "format" (PHP date() format) and "timezone".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => ['type' => 'string', 'description' => 'PHP date() format, default "Y-m-d H:i:s"'],
                'timezone' => ['type' => 'string', 'description' => 'IANA timezone, e.g. "UTC" or "Europe/Moscow"'],
            ],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $format = (string) ($input['format'] ?? 'Y-m-d H:i:s');
        $timezone = $input['timezone'] ?? null;

        try {
            $tz = (is_string($timezone) && $timezone !== '') ? new \DateTimeZone($timezone) : null;
        } catch (\Exception $e) {
            throw new ToolException("current_date: invalid timezone '{$timezone}'");
        }

        return new \DateTimeImmutable('now', $tz)->format($format);
    }
}
