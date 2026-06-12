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
use Claw\Chat\ConversationInterface;
use Claw\Chat\Status;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\ToolException;
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
    ) {
        $this->specs = $this->buildSpecs();
    }

    /** Drive the conversation: each message is one task. Ends when it closes. */
    public function run(): void
    {
        while (($text = $this->conversation->receive()) !== null) {
            // A failure ends this task, not the conversation. React by cause.
            try {
                $this->handle($text);
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

    private function execute(ToolUseBlock $call): ToolResultBlock
    {
        // LATER: through the Executor + middleware chain (security, approvals, audit).
        try {
            return new ToolResultBlock($call->id, $this->tools->get($call->name)->handle($call->input), false);
        } catch (ToolException $e) {
            return new ToolResultBlock($call->id, $e->getMessage(), true);
        }
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
