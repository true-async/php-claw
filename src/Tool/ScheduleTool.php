<?php

declare(strict_types=1);

namespace Claw\Tool;

use function Async\delay;
use function Async\spawn;

use Claw\Exceptions\ToolException;

/**
 * Lets the agent schedule a one-shot reminder: after the given delay, a message
 * is delivered to the user. Each scheduled item is its own coroutine that awaits
 * the delay and then fires — there is no central timer loop. Reminders live in
 * memory, so they do not survive a restart (persistence is a later layer).
 */
final readonly class ScheduleTool implements ToolInterface, DeferredToolInterface
{
    /** @return list<string> */
    public function searchTags(): array
    {
        return ['schedule', 'cron', 'timer', 'later', 'remind', 'recurring', 'defer', 'periodic'];
    }

    /**
     * @param \Closure(string): void $deliver Sends a message to the user.
     */
    public function __construct(private \Closure $deliver)
    {
    }

    public function name(): string
    {
        return 'schedule';
    }

    public function description(): string
    {
        return 'Schedule a one-shot reminder: after the given number of seconds, the message is sent to the user.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'after_seconds' => ['type' => 'number', 'description' => 'Delay in seconds before sending (e.g. 60 for one minute).'],
                'message' => ['type' => 'string', 'description' => 'The reminder text to send to the user.'],
            ],
            'required' => ['after_seconds', 'message'],
        ];
    }

    public function effects(): array
    {
        return [Effect::Write];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $rawAfter = $input['after_seconds'] ?? null;
        $after = is_numeric($rawAfter) ? (float) $rawAfter : 0.0;

        $rawMessage = $input['message'] ?? null;
        $message = is_string($rawMessage) ? trim($rawMessage) : '';

        if ($after <= 0) {
            throw new ToolException('schedule: "after_seconds" must be greater than zero');
        }

        if ($message === '') {
            throw new ToolException('schedule: "message" is required');
        }

        $deliver = $this->deliver;
        $ms = (int) round($after * 1000);

        // Fire-and-forget: this coroutine parks on delay() and costs nothing while
        // suspended; when it wakes it pushes the reminder to the user.
        spawn(static function () use ($ms, $message, $deliver): void {
            delay($ms);
            $deliver('⏰ ' . $message);
        });

        return 'Scheduled: the reminder will be sent in ' . $after . ' seconds.';
    }
}
