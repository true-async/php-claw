<?php

declare(strict_types=1);

namespace Claw;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentRequest;
use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolSpec;
use Claw\Agent\ToolUseBlock;
use Claw\Chat\Approval;
use Claw\Chat\ConversationInterface;
use Claw\Chat\Status;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\ToolException;
use Claw\Permission\Decision;
use Claw\Permission\Policy;
use Claw\Store\SessionStore;
use Claw\Tool\Registry;
use Claw\Tool\ToolInterface;

/**
 * One conversation: holds history and runs the agentic loop. `run()` drives the
 * dialogue (one message = one task); the model and tools are fixed for its life.
 */
final class Session
{
    /** @var list<Message> */
    private array $history = [];

    /** How many history messages are already written to the store. */
    private int $persisted = 0;

    /**
     * Tool specs are constant for the session — built once.
     *
     * @var list<ToolSpec>
     */
    private readonly array $specs;

    public function __construct(
        private readonly ConversationInterface $conversation,
        private readonly AgentInterface $agent,
        private readonly Registry $tools,
        private readonly string $system,
        private readonly string $model,
        private readonly int $maxHistory = 0,
        private readonly Policy $policy = new Policy(),
        private readonly ?SessionStore $store = null,
    ) {
        $this->specs = $this->buildSpecs();
    }

    /** Drive the conversation: each message is one task. Ends when it closes. */
    public function run(): void
    {
        // Resume a prior conversation: the stored history becomes the starting
        // context, so the agent "remembers" across restarts.
        if ($this->store !== null) {
            $this->history = $this->store->load();
            $this->persisted = \count($this->history);
        }

        while (($text = $this->conversation->receive()) !== null) {
            // A failure ends this task, not the conversation. React by cause.
            try {
                $this->handle($text);
                $this->persist();
            } catch (ContextLengthException $e) {
                $this->conversation->send('The conversation got too long for the model. Please start a new one.');
            } catch (QuotaExceededException $e) {
                $this->conversation->send($this->quotaMessage($e));
            } catch (RateLimitException $e) {
                $this->conversation->send($this->rateLimitMessage($e));
            } catch (AuthException $e) {
                $this->conversation->send('Configuration error: ' . $e->getMessage());
            } catch (AgentException $e) {
                $this->conversation->send('Error: ' . $e->getMessage());
            }
        }
    }

    private function quotaMessage(QuotaExceededException $e): string
    {
        $base = 'Quota exhausted (out of tokens or credits).';

        return $e->retryAfterMs > 0
            ? $base . ' Resets in ~' . (int) ceil($e->retryAfterMs / 1000) . 's.'
            : $base . ' Top up to continue.';
    }

    private function rateLimitMessage(RateLimitException $e): string
    {
        if ($e->retryAfterMs > 0) {
            return 'Rate limit reached. Try again in ~' . (int) ceil($e->retryAfterMs / 1000) . 's.';
        }

        return 'Rate limit reached. Please try again later.';
    }

    /** Process one user message to a final answer (the ReAct loop). */
    private function handle(string $text): void
    {
        $this->history[] = Message::userText($text);

        $totalInput  = 0;
        $totalOutput = 0;

        // Loops until the model returns a final answer (no tool_use). The bound
        // is memory: the model's context window (the API rejects an oversized
        // history -> ContextLengthException), plus an optional soft cap.
        for (;;) {
            if ($this->maxHistory > 0 && \count($this->history) >= $this->maxHistory) {
                throw new ContextLengthException("History reached the configured limit of {$this->maxHistory} messages");
            }

            $this->conversation->updateStatus(Status::typing());

            // The model is stateless: every call must carry the FULL history
            // (system + all messages + tool results). The repeated prefix is
            // cheap via prompt caching; trimming/summarization is a later layer.
            $response = $this->agent->send(new AgentRequest(
                model: $this->model,
                messages: $this->history,
                system: $this->system,
                tools: $this->specs,
            ));

            $totalInput  += $response->usage->inputTokens;
            $totalOutput += $response->usage->outputTokens;

            $this->history[] = new Message(Role::Assistant, $response->content);

            if (!$response->wantsToolUse()) {
                $this->conversation->send($response->text ?? '');
                $this->conversation->updateStatus(Status::done(new \Claw\Agent\Usage($totalInput, $totalOutput)));

                return;
            }

            $results = [];
            foreach ($response->toolCalls as $call) {
                $this->conversation->updateStatus(Status::toolCall($call->name));
                $results[] = $this->execute($call);
            }

            $this->history[] = new Message(Role::User, $results);
        }
    }

    /** Write the messages added since the last save to the store (the new tail only). */
    private function persist(): void
    {
        if ($this->store === null) {
            return;
        }

        $new = \array_slice($this->history, $this->persisted);
        if ($new !== []) {
            $this->store->append(...$new);
            $this->persisted = \count($this->history);
        }
    }

    private function execute(ToolUseBlock $call): ToolResultBlock
    {
        // The gatekeeper runs before the tool: a hard rule blocks it outright, a
        // Mutating tool asks the user, Safe ones run straight through. A refusal
        // (or block) is returned to the model as an error tool_result, so the
        // agent simply continues without having done the action.
        try {
            $tool = $this->tools->get($call->name);

            $verdict = $this->policy->check($tool, $call->input);
            if ($verdict->decision === Decision::Deny) {
                return new ToolResultBlock($call->id, 'blocked: ' . $verdict->reason, true);
            }

            if ($verdict->decision === Decision::Confirm && !$this->approved($call)) {
                return new ToolResultBlock($call->id, 'denied by the user', true);
            }

            return new ToolResultBlock($call->id, $tool->handle($call->input), false);
        } catch (ToolException $e) {
            return new ToolResultBlock($call->id, $e->getMessage(), true);
        }
    }

    /**
     * Decide a Confirm: a saved "always" rule skips the prompt; otherwise ask the
     * user. An "always" answer is remembered so we never ask for that tool again.
     */
    private function approved(ToolUseBlock $call): bool
    {
        if ($this->store !== null && $this->store->isToolAllowed($call->name)) {
            return true;
        }

        return match ($this->conversation->confirm($this->confirmPrompt($call))) {
            Approval::No     => false,
            Approval::Once   => true,
            Approval::Always => $this->remember($call->name),
        };
    }

    /** Persist an "always allow" rule (if a store is configured) and allow the call. */
    private function remember(string $tool): bool
    {
        $this->store?->allowTool($tool);

        return true;
    }

    /** A short, human-readable summary of a tool call for the approval prompt. */
    private function confirmPrompt(ToolUseBlock $call): string
    {
        $detail = '';
        if ($call->input !== []) {
            $encoded = json_encode($call->input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $detail = $encoded === false ? '' : ' ' . $encoded;
        }

        return "Allow `{$call->name}`{$detail}?";
    }

    /**
     * Build the tool specs advertised to the model (Tool -> Agent bridge).
     *
     * @return list<ToolSpec>
     */
    private function buildSpecs(): array
    {
        return array_map(
            static fn (ToolInterface $tool): ToolSpec => new ToolSpec(
                $tool->name(),
                $tool->description(),
                $tool->inputSchema(),
            ),
            $this->tools->all(),
        );
    }
}
