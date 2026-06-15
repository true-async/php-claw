<?php

declare(strict_types=1);

namespace Claw;

use function Async\await;

use Async\Coroutine;

use function Async\spawn;

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
use Claw\Exec\AuditMiddleware;
use Claw\Exec\ChainExecutor;
use Claw\Exec\ExecutorInterface;
use Claw\Exec\PermissionMiddleware;
use Claw\Exec\TimeoutMiddleware;
use Claw\Permission\Policy;
use Claw\Store\SessionStore;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;
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

    /** Runs each tool call through the security/audit middleware chain. */
    private readonly ExecutorInterface $executor;

    /** @var Coroutine<mixed>|null The coroutine running the current turn, so "/stop" can cancel it. */
    private ?Coroutine $currentTurn = null;

    public function __construct(
        private readonly ConversationInterface $conversation,
        private readonly AgentInterface $agent,
        private readonly Registry $tools,
        private readonly string $system,
        private readonly string $model,
        private readonly int $maxHistory = 0,
        private readonly Policy $policy = new Policy(),
        private readonly ?SessionStore $store = null,
        private readonly int $toolTimeoutMs = 0,
    ) {
        $this->specs = $this->buildSpecs();

        // Tool execution funnels through one chain: audit logs every call (even
        // denials), permission gates it, an optional timeout bounds the run, and
        // the terminal stage runs the tool.
        $middlewares = [
            new AuditMiddleware($this->store),
            new PermissionMiddleware($this->policy, $this->tools, $this->conversation, $this->store),
        ];
        if ($this->toolTimeoutMs > 0) {
            $middlewares[] = new TimeoutMiddleware($this->toolTimeoutMs);   // innermost: bound each tool run
        }

        $this->executor = new ChainExecutor($middlewares, $this->runTool(...));

        // A "/stop" from the user cancels the in-flight turn (if one is running).
        $this->conversation->onInterrupt($this->requestStop(...));
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

        $deferredMessages = [];
        $currentTurn = null;

        while (($message = $this->conversation->receive()) !== null) {
            $deferredMessages[] = $message;

            if ($currentTurn === null || $currentTurn->isCompleted()) {
                $currentTurn = spawn($this->startTurn(...), $deferredMessages);
                $deferredMessages = [];
            }
        }
    }

    /** Cancel the in-flight turn, if any (invoked when the user sends "/stop"). */
    private function requestStop(): void
    {
        $this->currentTurn?->cancel();
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

    private function startTurn(string|array $text): void
    {
        if (is_array($text)) {
            foreach ($text as $line) {
                $this->history[] = Message::userText($line);
            }
        } else {
            $this->history[] = Message::userText($text);
        }

        // The turn runs in its own coroutine so a "/stop" can cancel it
        // mid-flight. A failure ends this task, not the conversation.
        $checkpoint = \count($this->history);

        try {
            $this->turnLoop();
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
        } catch (\Cancellation $e) {
            // "/stop": discard the abandoned turn so the history stays valid
            // (no dangling tool_use), then acknowledge.
            $this->history = \array_slice($this->history, 0, $checkpoint);
            $this->conversation->send('Stopped: '.$e->getMessage());
        }
    }

    /** Process one user message to a final answer (the ReAct loop). */
    private function turnLoop(): void
    {
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
        return $this->executor->call(new ToolCall($call->id, $call->name, $call->input));
    }

    /** Terminal stage of the executor: resolve the tool and run it; failures become error results. */
    private function runTool(ToolCall $call): ToolResultBlock
    {
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
