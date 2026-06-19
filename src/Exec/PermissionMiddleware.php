<?php

declare(strict_types=1);

namespace Claw\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Chat\Approval;
use Claw\Chat\ConversationInterface;
use Claw\Exceptions\ToolException;
use Claw\Permission\Decision;
use Claw\Permission\Policy;
use Claw\Store\SessionStore;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;

/**
 * The security layer, as a middleware. Resolves the call's tool to read its Risk,
 * asks the Policy for a verdict, then blocks, asks the user (Confirm), or passes
 * through. An "always" approval is persisted as a rule so we never ask again.
 */
final readonly class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Policy $policy,
        private Registry $tools,
        private ConversationInterface $conversation,
        private ?SessionStore $store = null,
    ) {
    }

    public function handle(ToolCall $call, callable $next): ToolResultBlock
    {
        try {
            $tool = $this->tools->get($call->name);
        } catch (ToolException) {
            return $next($call);   // unknown tool — let the terminal report it
        }

        $verdict = $this->policy->check($tool, $call->input);

        if ($verdict->decision === Decision::Deny) {
            return new ToolResultBlock($call->id, 'blocked: ' . $verdict->reason, true);
        }

        if ($verdict->decision === Decision::Confirm && !$this->approved($call)) {
            return new ToolResultBlock($call->id, 'denied by the user', true);
        }

        return $next($call);
    }

    /** A saved "always" rule skips the prompt; otherwise ask, and remember "always". */
    private function approved(ToolCall $call): bool
    {
        if ($this->store !== null && $this->store->isToolAllowed($call->name)) {
            return true;
        }

        return match ($this->conversation->confirm('Allow ' . $call->summary() . '?')) {
            Approval::No     => false,
            Approval::Once   => true,
            Approval::Always => $this->remember($call->name),
        };
    }

    private function remember(string $tool): bool
    {
        $this->store?->allowTool($tool);

        return true;
    }
}
