# php-claw — Architecture

A minimal personal AI agent (OpenClaw / NanoClaw style) built **entirely on PHP
TrueAsync**. You message it from Telegram; it runs a Claude agent loop that can
take real actions on the host (bash, files) and replies in the same chat.
"Claude Code whose terminal is Telegram."

## Core idea: single thread, all coroutines

Everything the agent does is **I/O-bound**: HTTP to Claude, HTTP to Telegram,
`bash` subprocesses, SQLite. Under TrueAsync all of these `await` and cost no CPU
while suspended. So there is **no ThreadPool and no threads** — one OS process,
one reactor, and a coroutine per concurrent unit of work. Hundreds of chats run
concurrently in a single thread.

(ThreadPool is only worth adding later for genuinely CPU-bound work — there is
none in v1. Sub-agents, if added, are just more coroutines.)

## Model

```
                         one process, one reactor
  ┌──────────────────────────────────────────────────────────────────┐
  │  Telegram poll loop  (async curl getUpdates, long-poll)            │
  │        │                                                           │
  │        ▼                                                           │
  │  Router: authorize chat_id                                         │
  │        ├─ message  ──▶ session inbound Channel  (per chat_id)      │
  │        └─ callback ──▶ Approval registry (resolve pending Future)  │
  │                                                                    │
  │  per chat_id: a session coroutine                                  │
  │        while (msg = inbox.recv()):  runTurn(msg)                   │
  │                                                                    │
  │  runTurn = agent loop (AgentInterface) + tool execution (await'd)  │
  │  state persisted in per-session SQLite (PDO pool)                  │
  └──────────────────────────────────────────────────────────────────┘
```

All boxes are coroutines in the same thread. `Channel` and `Future` here are the
in-thread coroutine primitives (no cross-thread copying).

## One turn

A *turn* = full handling of one user message until a final reply. Internally it
is the agentic loop (many Claude round-trips + actions), so it can take
seconds–minutes — but it only ever `await`s, never blocks the thread:

```
runTurn(text):
  history = store.load(chatId)
  loop:
    resp = await agent.send(AgentRequest{system, history, tools, model})  # async, retry
    history += Message(Assistant, resp.content)
    if resp.stopReason == ToolUse:
        foreach resp.toolCalls as call:
            result = await executor.call(toToolCall(call))   # security + bash subprocess
            history += Message(Tool, ToolResult(call.id, result))
        continue
    else:
        store.save(chatId, history)
        return resp.text                                     # delivered to Telegram
```

## Agent (LLM backend) interface

The agent **only decides the next action; it never executes tools** — that is the
`Executor` chain's job (where security lives). So the interface is a single model
round-trip: given history + tool specs, return the model's next move (text or
tool-use requests). The turn loop owns the loop.

```php
interface AgentInterface {
    // One model round-trip. May await (async HTTP). No tool execution here.
    public function send(AgentRequest $request): AgentResponse;
}
```

Provider-neutral value types (so any backend fits, not just Anthropic):

```php
AgentRequest  { string system; Message[] messages; ToolSpec[] tools;
                string model; int maxTokens; float temperature; }
AgentResponse { ?string text; ToolUseBlock[] toolCalls; StopReason stopReason; Usage usage; }

Message         { Role role; ContentBlock[] content; }   // Role: User | Assistant | Tool
TextBlock       { string text; }
ToolUseBlock    { string id; string name; array input; }
ToolResultBlock { string toolUseId; string content; bool isError; }
ToolSpec        { string name; string description; array inputSchema; }  // from ToolInterface
enum StopReason { EndTurn, ToolUse, MaxTokens }
```

Implementations (the persona is just `$system` — no class per persona; only per
provider):

- `ClaudeAgent`          — native Anthropic.
- `OpenAiCompatibleAgent` — one class for DeepSeek / Groq / Mistral / Qwen /
  Ollama / OpenRouter (differ only by base URL + model + key).
- `GeminiAgent`          — native Google (or via its OpenAI-compatible endpoint).

Cheap + capable defaults to start with: DeepSeek (`deepseek-chat`), Gemini Flash,
Groq-hosted Llama/Qwen. Exact model ids and pricing confirmed at implementation.

## Chat interface (Telegram now, WhatsApp later)

The chat is an abstraction over the messenger — its only job is "what did the
human say?" and "send this to the human". A socket-style shape: the gateway
`accept()`s the next new chat and hands back a `Conversation` bound to it.

```php
interface ChatInterface {                          // gateway
    public function accept(): ConversationInterface; // await the next new conversation
}

interface ConversationInterface {              // one chat, bound (no chatId)
    public function receive(): ?string;         // next message, null when closed
    public function send(string $text): void;
}
```

Demultiplexing many chats over one connection (Telegram) is the gateway's
internal concern; the Session sees only its own `Conversation`. The main loop is
`while (true) { $c = $chat->accept(); spawn(fn () => new Session($c, ...)->run()); }`.
`ConsoleChat` yields a single stdin/stdout conversation.

## Tools

A tool is a typed function the model can call:

```php
interface ToolInterface {
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;        // JSON Schema
    public function risk(): Risk;                // Safe | Mutating | Dangerous
    public function handle(array $input): string; // tool_result text; may await
}
```

v1 set:
- `bash`       — Mutating. Async subprocess in workspace dir, scrubbed env, timeout.
- `read_file`  — Safe. Confined to workspace.
- `write_file` — Mutating. Confined to workspace.

Tool errors are returned to the model as `tool_result(is_error)`; tools are never
auto-retried. Skills (markdown playbooks) are phase 2 — not tools.

## Tool execution: one `.call()`, a middleware chain inside

The turn loop never touches a tool directly. It calls one transparent entry
point — `ExecutorInterface::call(toolCall): string` — and everything (security,
audit, timeout, transparency) is a middleware in an onion chain. Adding behavior =
adding a middleware, not editing the loop.

```php
interface ExecutorInterface {
    public function call(ToolCall $call): string;     // runs the middleware chain
}

interface MiddlewareInterface {
    // Wrap the next stage: inspect, short-circuit, modify, time, log.
    public function handle(ToolCall $call, callable $next): string; // tool_result text
}
```

Chain (outer → inner); the terminal stage resolves the tool and `await`s it:

1. **Audit**        — log intent before, result/verdict after (even denials).
2. **Permission**   — the security layer; may short-circuit (see below).
3. **Transparency** — echo intent to chat ("running: `…`" + `[stop]`).
4. **Timeout**      — wrap `next` in `\Async\timeout()`.
5. **terminal**     — `await registry.get(call.name).handle(call.input)`.

The whole pipeline is `await`-able end to end, so a middleware can suspend (e.g.
Permission awaiting a button) without blocking the thread.

### Permission middleware (the security layer)

Because every call funnels through `.call()`, the permission middleware
transparently sees the agent's full intent for *every* action and can stop it.
Ordered checks, first decisive wins:

1. **Denylist** — hard rules (`rm -rf`, fork bombs, workspace escape, reading the
   secrets file) → blocked, not unlockable.
2. **Rules** — persisted allow/deny rules from the session SQLite.
3. **Risk default** — `Safe` → allow, `Mutating` → confirm, `Dangerous` → deny.

```php
public function handle(ToolCall $call, callable $next): string {
    $verdict = $this->policy->check($call);            // Allow | Deny | Confirm
    if ($verdict->isDeny())    return "blocked: {$verdict->reason}";   // is_error
    if ($verdict->isConfirm()) {
        $choice = $this->chat->ask($call->chatId, $call->summary(), [Allow, Deny, Always]);
        if ($choice === Deny)   return "user denied";
        if ($choice === Always) $this->policy->persistRule($call);
    }
    return $next($call);                                // proceed to inner stages
}
```

**Interrupt while running.** Tools execute under the turn's cancellation token.
`/stop` from the user (or the Timeout middleware) cancels the turn coroutine;
TrueAsync cancellation propagates into the awaited `bash` subprocess and kills it.
So the layer blocks *before* and aborts *during*.

## Memory

One SQLite file per `chat_id` (one writer → no contention), via the PDO pool:
- `messages` — conversation history
- `rules`    — persisted permission rules
- `audit`    — every tool call

Plus a host-level `CLAUDE.md` persona / system prompt.

## Retry & guardrails

- Retry is **cause-aware and lives at the agent level**, not in the HTTP client.
  Each agent normalizes the failure into a typed exception (`AgentErrors`):
  transport / overloaded / server / rate-limit are `TransientErrorInterface`;
  auth / bad-request are permanent. Retry lives in the agent itself: `AbstractAgent::send()`
  wraps the provider's one-shot `attempt()` and retries by the policy — transient
  → backoff + jitter; `RateLimitException` → honor its
  `retryAfterMs` if near, else give up so the bot reports the resume time;
  permanent → never. `CurlHttpClient` is a single request (no retry). Sleeping
  via `\Async\delay()` blocks nothing. A different consumer (Telegram) gets its
  own retry at its own boundary — not stacked under the agent's.
- Tool errors: returned to the model, no transport retry.
- Per turn: max tool iterations + `\Async\timeout()`.
- `Session` reacts to the cause: rate-limit → "try again in N"; auth → config
  error; otherwise a generic message — the conversation survives either way.

## Security (honest)

- **Authorization (must-have #1)**: Router drops any `chat_id` not in the
  allowlist — otherwise a stranger gets a shell.
- A single-process agent runs `bash` with full process privileges. Real isolation
  is an OS concern (low-priv user / container). Documented, not enforced in code.
- `TELEGRAM_BOT_TOKEN` and the agent API key live in the host; the `bash` tool
  gets a scrubbed env.
- Workspace confinement + audit log of every tool call.

## Config

- `CLAW_AGENT` — which `AgentInterface` impl (`claude` | `openai-compatible` | `gemini`)
- agent key for the chosen provider (`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` /
  `GEMINI_API_KEY` / …) + optional `CLAW_BASE_URL` for OpenAI-compatible providers
- `CLAW_MODEL` — model id
- `TELEGRAM_BOT_TOKEN`, `CLAW_ALLOWED_CHATS`, `CLAW_WORKSPACE`

## File layout

```
php-claw/
  bin/claw                  entrypoint: autoload, then Cli->run(argv)
  src/
    Cli/   Cli.php          arg dispatch: pick a mode + agent factory
           WorkflowMode.php  default mode: create/issue/run/log (per-issue solver runs)
           SessionMode.php   --session: bootstrap reactor; accept() -> spawn Session
    Config.php
    Session.php             conversation state + agentic loop (run/handle/execute)
    Chat/  ChatInterface.php (accept) ConversationInterface.php
           ConsoleChat.php ConsoleConversation.php TelegramChat.php (todo)
    Agent/  AgentInterface.php ClaudeAgent.php OpenAiCompatibleAgent.php GeminiAgent.php
            AgentRequest.php AgentResponse.php Message.php ContentBlock.php (+Text/
            ToolUse/ToolResult) Role.php StopReason.php Usage.php ToolSpec.php
            AbstractAgent.php (send() = retry loop; attempt() = one request)
            AgentErrors.php (classify) AgentRetryPolicyInterface.php BackoffAgentRetryPolicy.php
    Http/  HttpClientInterface.php CurlHttpClient.php (one-shot) HttpResponse.php
    Exec/  ExecutorInterface.php ChainExecutor.php MiddlewareInterface.php
           AuditMiddleware.php PermissionMiddleware.php
           TransparencyMiddleware.php TimeoutMiddleware.php
    Tool/  ToolInterface.php Risk.php ToolCall.php Registry.php Workspace.php
           ReadFileTool.php WriteFileTool.php ListFilesTool.php BashTool.php (proc_open)
           DateTool.php (current time) PhpEvalTool.php (eval one expression; Dangerous)
           ScheduleTool.php (one-shot reminder; spawns a delay coroutine)
    Permission/  Policy.php
    Store/  SessionStore.php Schema.php
    Exceptions/  ClawException.php (base) ConfigException.php ChatException.php
                 HttpException.php ToolException.php
                 AgentException.php (base) TransientErrorInterface.php
                 RateLimitException.php OverloadedException.php ServerErrorException.php
                 TransportException.php AuthException.php BadRequestException.php
  skills/                   phase 2: *.md playbooks
  workspace/                sandboxed working dir for tools
  CLAUDE.md                 agent persona
  README.md  ARCHITECTURE.md
```

## Phasing

- **v1**: Telegram, session coroutines, AgentTurn + 3 tools, per-action approvals,
  per-session SQLite, chat_id allowlist, retry + guardrails.
- **Phase 2**: skills, scheduler (proactive messages), plan-mode, WhatsApp,
  sub-agents, ThreadPool for any CPU-bound work.
