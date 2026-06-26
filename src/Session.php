<?php

declare(strict_types=1);

namespace Claw;

use Async\AsyncCancellation;

use function Async\await;

use Async\Channel;
use Async\ChannelException;
use Async\Coroutine;

use function Async\spawn;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentRequest;
use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolSpec;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\Usage;
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
use Claw\Tool\DefineWorkflowTool;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;

/**
 * One conversation. The main loop reads user input continuously and never blocks
 * on the agent: each turn (the ReAct loop) runs in its own coroutine, so the user
 * can keep typing — messages pile up and are sent to the next turn — and a "/stop"
 * cancels the running turn.
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

    /** @var Channel<string> Queue of user messages from the input loop to the turns loop. */
    private readonly Channel $inbox;

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
        ?string $workflowDir = null,
    ) {
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

        // Workflows: the agent can define a reusable workflow (a WorkflowAbstract subclass)
        // as its own tool — validated and saved on disk. Running it lands with the workflow
        // driver once that is wired into a run.
        if ($workflowDir !== null) {
            $workflowStore = new WorkflowStore($workflowDir, $this->conversation->id());
            $this->tools->add(new DefineWorkflowTool($workflowStore, new WorkflowValidator()));
        }

        // Built last, so the workflow tools registered above are advertised too.
        $this->specs = $this->tools->specs();

        // Buffered so the input loop can keep queuing while a turn runs without blocking.
        $this->inbox = new Channel(16);
    }

    /**
     * Drive the conversation. The loop never blocks on a turn: it reads input,
     * cancels on "/stop", and otherwise queues the message — starting a new turn
     * (with everything queued) whenever no turn is running. Ends when the chat
     * closes; the last turn is awaited so it finishes cleanly.
     */
    public function run(): void
    {
        // Resume a prior conversation: the stored history becomes the starting
        // context, so the agent "remembers" across restarts.
        if ($this->store !== null) {
            $this->history = $this->store->load();
            $this->persisted = \count($this->history);
        }

        // Turns run in their own coroutine so they chain back-to-back: the moment
        // one finishes, the next starts on whatever queued while it ran. This loop
        // only collects input (and cancels on "/stop") — it never blocks on a turn.
        $turns = spawn($this->turnsMainLoop(...));

        while (($message = $this->conversation->receive()) !== null) {
            if ($message === '/stop') {
                $this->currentTurn?->cancel();

                continue;
            }

            $this->inbox->send($message);                 // hand it to the turns loop
            $this->conversation->showDeferred($message);  // show it queued (dim) until a turn takes it
        }

        // Chat closed: closing the channel lets the turns loop drain and exit.
        $this->inbox->close();
        await($turns);
    }

    /**
     * Run queued turns one at a time, back-to-back. Each turn is its own coroutine
     * (so "/stop" cancels just that turn); when it finishes we immediately pick up
     * whatever queued while it ran. Exits once the chat is closed and drained.
     */
    private function turnsMainLoop(): void
    {
        for (;;) {
            try {
                $batch = [$this->inbox->recv()];   // blocks until a message — no polling
            } catch (ChannelException) {
                return;   // channel closed and drained: the chat is over
            }

            // Take everything else already queued, so one turn handles the burst.
            while (!$this->inbox->isEmpty()) {
                $batch[] = $this->inbox->recv();
            }

            $this->conversation->flushDeferred();   // the queued lines are now committed (sent)

            try {
                $turn = spawn($this->startTurn(...), $batch);
                $this->currentTurn = $turn;
                await($turn);
            } finally {
                $this->currentTurn = null;
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

    /**
     * Run one turn: append the queued user messages, then loop the agent to a
     * final answer. A failure ends this turn, not the conversation.
     *
     * @param list<string> $messages
     */
    private function startTurn(array $messages): void
    {
        // Checkpoint before adding the user turns, so a "/stop" can fully discard
        // this turn and leave the history valid (no dangling tool_use).
        $checkpoint = \count($this->history);

        foreach ($messages as $line) {
            $this->history[] = Message::userText($line);
        }

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
        } catch (AsyncCancellation) {
            // "/stop": discard the abandoned turn so the history stays valid
            // (no dangling tool_use), then acknowledge.
            $this->history = \array_slice($this->history, 0, $checkpoint);
            $this->conversation->send('Stopped.');
        }
    }

    /** Process the accumulated history to a final answer (the ReAct loop). */
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

            // Terminate on the dispatchable subset (toolCalls), not the advertised intent
            // (wantsToolUse): a response can carry a tool_use stop reason yet no parseable tool
            // calls (a truncated turn). Branching on toolCalls — like DefaultTurnLoop — ends with
            // the text present instead of looping forever on an empty tool batch.
            if ($response->toolCalls === []) {
                $this->conversation->send($response->text ?? '');
                $this->conversation->updateStatus(Status::done(new Usage($totalInput, $totalOutput)));

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
}
