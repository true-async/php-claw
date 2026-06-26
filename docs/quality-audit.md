# php-claw — Аудит качества кода

> **Дата:** 2026-06-26 · **Режим:** только чтение, код не изменялся.
>
> **Метод:** многоагентный аудит — 20 финдеров (11 по подсистемам + 5 сквозных + 4 фокусных) по дименсиям (архитектура / SOLID / дублирование / запахи / комментарии / нейминг), затем независимая состязательная верификация каждой находки по файлам.
>
> **Итог:** 125 находок → **85 подтверждено**, 39 отсеяно при верификации.


---

## Содержание

- [Архитектура](#архитектура) — 7
- [Нарушения SOLID](#нарушения-solid) — 7
- [Дублирование](#дублирование) — 18
- [Плохой запах](#плохой-запах) — 33
- [Плохие комментарии](#плохие-комментарии) — 7
- [Конвенции / нейминг](#конвенции-нейминг) — 9
- [Отсеяно при верификации](#отсеяно-при-верификации) — 39

---


## Архитектура


### 1. 🟠 MEDIUM · `src/Agent/ClaudeAgent.php:51`

**json() throws HttpException outside the try block, bypassing the AgentException contract**


$response->json() at line 51 sits outside the try/catch and can throw HttpException, which AbstractAgent::send() (catches only AgentException) won't normalize or retry.


```php
return self::decodeResponse($response->json());
```


**Почему проблема:** The try/catch only wraps $this->http->post(). HttpResponse::json() throws HttpException on a malformed-but-2xx body, and that throw sits outside the catch in both attempt() methods. AgentInterface::send() documents `@throws \Claw\Exceptions\AgentException` and AbstractAgent::send() only catches AgentException, so a raw HttpException leaks straight out of the agent and is never retried/normalized. The neutral error abstraction (AgentErrors) is skipped for this path.


**Предложение (необязательное):** Decode the body inside the same try/catch and convert a JSON/HttpException via AgentErrors (e.g. a TransportException), so every failure exits send() as a typed AgentException. Same fix in OpenAiCompatibleAgent.php:50.


### 2. 🟠 MEDIUM · `src/Workflow/Environment.php:85-96`

**Workflow run-path bypasses the security Executor chain ARCHITECTURE.md promises**


executor() builds a ChainExecutor with an empty middleware list (no permission/audit/denylist) while the run-path registers BashTool against the user's real repo — an allow-all autonomous run.


```php
return new ChainExecutor([], static function (ToolCall $call) use ($registry): ToolResultBlock {
    ... return new ToolResultBlock($call->id, $registry->get($call->name)->handle($call->input), false);
... // Permission/audit middleware is the run-path's to add; an autonomous run is allow-all.
```


**Почему проблема:** ARCHITECTURE.md (lines 148-205) states 'the turn loop never touches a tool directly... everything (security, audit, timeout) is a middleware' and that the Permission middleware 'transparently sees the agent's full intent for every action and can stop it.' The workflow path builds a ChainExecutor with an EMPTY middleware list and adds BashTool(project->path) (WorkflowMode.php:197) pointed at the user's REAL repository. So an autonomous, model-driven run executes bash/write_file on the real project with no denylist, no confirm-on-Mutating, no audit gate — the exact opposite of the documented security model. This is a missing seam, not just a TODO: the code's own comment admits 'allow-all'.


**Предложение (необязательное):** Make the executor() factory accept (or the run-path inject) the Permission/Audit middleware so the documented denylist + risk-default still applies on autonomous runs; at minimum gate the bash/write tools behind the Policy described in ARCHITECTURE.md before running on a real folder.


### 3. 🟠 MEDIUM · `src/Workflow/WorkflowAbstract.php:690-737`

**State persistence reflects every subclass property indiscriminately**


captureState() persists every non-static subclass property indiscriminately with no opt-out, so a field holding a service/closure/resource silently breaks or pollutes the store.


```php
foreach ($this->stateProperties() as $property) {
            if ($property->isInitialized($this)) {
                $state[$property->getName()] = $property->getValue($this);
```


**Почему проблема:** captureState() snapshots ALL non-static properties the subclass declares and hands them to the store to persist, with the only contract being an undocumented assumption that workflow fields are pure, serializable state. A generated solver that holds a service, closure, or resource in a field will silently break the store or persist transient junk, and there is no opt-out marker. The implicit 'every field is durable state' rule is a hidden coupling between the base and how authors are allowed to write fields.


**Предложение (необязательное):** Make the contract explicit — either an attribute marking persisted fields, or validate that captured values are serializable and fail loudly otherwise.


### 4. 🟠 MEDIUM · `ARCHITECTURE.md:250-287`

**ARCHITECTURE.md is stale: the entire Workflow/Trace/Project subsystem (the actual default mode) is undocumented**


ARCHITECTURE.md documents only the v1 Telegram session bot and omits the entire Workflow/Trace/Project subsystem (DefineWorkflow/Finish/Handoff/Recall tools) that now backs the default WorkflowMode.


```php
src/
    Cli/   Cli.php          arg dispatch: pick a mode + agent factory
           WorkflowMode.php  default mode: create/issue/run/log ...
    ... Tool/  ToolInterface.php Risk.php ToolCall.php Registry.php Workspace.php
           ReadFileTool.php WriteFileTool.php ListFilesTool.php BashTool.php (proc_open)
```


**Почему проблема:** The file layout and the whole 'One turn' / Session / Executor-chain narrative describe a Telegram session bot, but the default mode is now WorkflowMode (per-issue generated solvers). There is no mention anywhere of the Claw\Workflow, Claw\Trace, or Claw\Project namespaces, nor of DefineWorkflowTool/FinishTool/HandoffTool/RecallTool — i.e. the dominant, most complex part of the current codebase. A reviewer reading ARCHITECTURE.md first would build a wrong mental model and miss the real seams (Environment, Tracer, WorkflowAbstract).


**Предложение (необязательное):** Add a section documenting the workflow run-path (WorkflowMode -> Environment/EnvKey -> WorkflowAbstract -> Tracer/TraceStore/TraceReader) and the Project ledger, and reconcile the 'File layout' tree with src/. State which of the two run-paths (Session vs WorkflowMode) is canonical.


### 5. 🟡 LOW · `src/Trace/TraceReader.php:36`

**TraceReader reaches into the `runs` table owned by ProjectStore**


runs() in the Trace namespace selects from the `runs` ledger table created/owned by ProjectStore, not by TraceStore


```php
$stmt = $this->pdo->prepare('SELECT id, issue_id, workflow, status FROM runs ORDER BY id DESC LIMIT :n');
```


**Почему проблема:** The `runs` table is created and owned by ProjectStore (src/Project/ProjectStore.php:278). TraceReader queries its columns directly, coupling the trace component to another component's schema it does not own or ensure exists — if a db without that table is opened, this query throws. The class docblock even claims it 'keeps the schema in one place by reusing TraceStore', which is contradicted by this cross-store read.


**Предложение (необязательное):** Move runs()/ledger reads to a ProjectStore method (or inject a small run-ledger reader), so TraceReader only touches the `trace` table it co-owns with TraceStore.


### 6. 🟡 LOW · `src/Store/SessionStore.php:5`

***Store family is placed inconsistently across namespaces**


SessionStore sits alone in Claw\Store while ProjectStore/TraceStore/WorkflowStore are colocated with their domains


```php
namespace Claw\Store;
```


**Почему проблема:** Four classes share the *Store suffix but their namespacing is inconsistent: SessionStore lives in a dedicated Claw\Store namespace that contains nothing else, while ProjectStore (Claw\Project), TraceStore (Claw\Trace) and WorkflowStore (Claw\Workflow) are colocated with their domain. The lone Store namespace doesn't pull its weight and makes the persistence layer's organization arbitrary — a reader can't predict where a given *Store lives.


**Предложение (необязательное):** Pick one rule: either colocate SessionStore with its domain (e.g. Claw\Chat or alongside Session) and drop the single-class Store namespace, or move all persistence stores under Claw\Store. Consistency over the current split.


### 7. 🟡 LOW · `src/Permission/Policy.php:35-40`

**Policy reaches into the bash tool's 'command' input key — leaky coupling to a tool's schema**


Policy's denylist only inspects a hardcoded 'command' input key, coupling the generic gatekeeper to BashTool's schema and silently no-op'ing if that key is ever renamed/varies.


```php
$command = isset($input['command']) && \is_string($input['command']) ? $input['command'] : '';
if ($command !== '' && $this->isDenied($command)) {
```


**Почему проблема:** The generic permission gatekeeper hardcodes knowledge that one specific tool (BashTool) names its argument 'command'. The denylist is meaningful only for that tool, yet Policy inspects the raw input array of every tool. If the bash tool renames the field, the denylist silently stops protecting anything, with no compile-time link. This is feature envy across the Tool/Permission boundary.


**Предложение (необязательное):** Have the tool expose what to screen (e.g. a method returning its security-relevant command text, or risk evaluated by the tool), so Policy depends on an abstraction rather than a magic input key.


## Нарушения SOLID


### 8. 🟠 MEDIUM · `src/Chat/AsyncConsoleConversation.php:28-582`

**AsyncConsoleConversation is a god class with many reasons to change**


AsyncConsoleConversation bundles conversation semantics, TUI layout/render, background stdin reading, spinner, a global error handler, and Win32 FFI into one 582-line class


```php
final class AsyncConsoleConversation implements ConversationInterface  // 582 lines: readLoop(), draw()/relayout(), buildLayout(), detectSize()/winConsoleSize() FFI, handlePhpError(), spin()
```


**Почему проблема:** This single class owns conversation semantics (receive/send/confirm), full TUI layout math, ANSI rendering primitives, background stdin reading, spinner animation, a global PHP error handler (set_error_handler), and Windows console-size detection via raw FFI/kernel32. Each is an independent reason to change and a distinct testing concern, all crammed behind a ConversationInterface. The Windows FFI/`stty` terminal-size detection in particular is a self-contained capability with zero coupling to chat logic.


**Предложение (необязательное):** Extract a TerminalSize detector (stty/FFI), a Screen/Layout renderer, and an input reader; let the conversation orchestrate them. The PHP error-handler concern also does not belong in a chat transport.


### 9. 🟠 MEDIUM · `src/Cli/WorkflowMode.php:164-321`

**runIssue is a god method: CLI parsing, composition root, generation, approval and repair loop in one**


runIssue() (lines 164-321, ~157 lines) bundles CLI parsing, composition-root wiring, generation+approval, and the repair/resume loop in one method


```php
private function runIssue(array $args, ?string $projectDir, ?Level $verbosity): int
{ ... $registry->add(...); $env = new Environment()->set(...); ... new GenerateIssueWorkflow(...)->run(); ... while (true) { try { $solver = new $currentClass(...); ... } catch (\Throwable $e) { ... $this->repairSolver(...) } }
```


**Почему проблема:** This single ~160-line method validates argv, opens the store, loads Config, builds the whole Workspace/Registry/Environment composition root, generates a solver, prompts the human, then runs a multi-attempt repair-and-resume loop. It has many reasons to change (tool palette, env wiring, repair policy, CLI UX) and is impossible to unit-test in isolation. The run/repair orchestration in particular is domain logic that does not belong in a CLI mode class.


**Предложение (необязательное):** Extract the composition-root wiring (Registry/Environment build) and the run-with-repair loop into a dedicated runner collaborator, leaving WorkflowMode to parse args and dispatch.


### 10. 🟡 LOW · `src/Chat/AsyncConsoleConversation.php:531-558`

**AsyncConsoleConversation embeds Win32 FFI terminal probing inside a chat class**


~75 lines of Windows kernel32 FFI console-size probing live inside the chat-conversation class


```php
$k = \FFI::cdef(
                    'typedef void* HANDLE;'
                    . 'typedef unsigned short WORD; typedef short SHORT;' ... 'kernel32.dll'
```


**Почему проблема:** Detecting the console size via WinAPI (plus the stty/readline fallbacks in detectSize) is a platform terminal-metrics concern that has nothing to do with conversation semantics. It bloats the class with ~75 lines of OS-specific code and gives it a second reason to change (Windows console API).


**Предложение (необязательное):** Extract a TerminalSize/Viewport helper exposing detect(): array{rows,cols}; the conversation just consumes it.


### 11. 🟡 LOW · `src/Chat/AsyncConsoleConversation.php:275-314`

**AsyncConsoleConversation owns an unrelated global PHP error handler**


The conversation class installs a process-wide set_error_handler and owns level-name mapping plus a re-entrancy guard


```php
private function handlePhpError(int $level, string $message, string $file = '', int $line = 0): bool
```


**Почему проблема:** Capturing/formatting PHP warnings, mapping error levels to names, and installing/restoring a process-wide error handler is a diagnostics-routing responsibility distinct from rendering a chat TUI. The class already does layout, rendering, input, coroutine lifecycle, and history; the error handler is a fourth axis of change and adds the re-entrancy guard field ($handlingError) just to cope.


**Предложение (необязательное):** Move diagnostic capture into a small DiagnosticSink the conversation renders from; keep set_error_handler wiring in the composition root.


### 12. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:40-738`

**WorkflowAbstract is a god class with many reasons to change**


WorkflowAbstract (~740 lines) concentrates budget, critic/supervisor, snapshot, tool-discovery and turn-loop concerns in one base.


```php
abstract class WorkflowAbstract implements WorkflowInterface
```


**Почему проблема:** The class doc claims it is 'a HELPER, not an engine', but it concentrates at least six independent responsibilities, each a distinct reason to change: snapshot/restore via reflection (captureState/restoreState 690-737), budget enforcement+policy+token-parsing (414-461), the critic judging loop (critic 518-537), supervisor escalation logic (superviseStep 564-606), local-tool discovery/registry merging (localTools/withLocalTools 329-368), and turn-loop assembly+tracing (ai 251-314). A change to the escalation protocol, the budget policy, or the persistence format each forces edits to this one ~740-line base. The critic/supervisor 'engine' in particular contradicts the stated helper role and is a natural seam to extract.


**Предложение (необязательное):** Extract the critic/supervisor rework loop into a collaborator (e.g. a StepReviewerInterface) and the budget enforcement into a BudgetGuard, leaving WorkflowAbstract to orchestrate. Advisory.


### 13. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:382-606`

**WorkflowAbstract clusters four independent subsystems (SRP)**


The base clusters budget, critic/supervisor, state snapshot and tool/step discovery subsystems that change for different reasons.


```php
private function budget() / turnBudget() / enforceBudget() / budgetPolicy() / parseExtraTokens() / numEnv() ... private function stepCritic() / stepMaxRounds() / critic() / criticRole() / superviseStep()
```


**Почему проблема:** The 738-line base bundles at least four cohesive responsibilities that each change for different reasons: budget enforcement (6 methods, ~80 lines), the critic+supervisor escalation subsystem (6 methods, ~120 lines), reflection-based state snapshot/restore (captureState/restoreState/stateProperties), and reflection-based local-tool/step discovery. A tweak to budget policy or critic escalation forces edits to the class every workflow subtypes.


**Предложение (необязательное):** Extract a BudgetGuard and a CriticSupervisor collaborator (injected via Environment), and a StateSnapshotter for the reflection capture/restore. The base then delegates, shrinking to the DSL surface plus step orchestration.


### 14. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:281-291`

**ai() news up the concrete DefaultTurnLoop instead of injecting it (DIP)**


ai() hard-instantiates concrete DefaultTurnLoop (9 args) rather than injecting a TurnLoopInterface.


```php
$loop = new DefaultTurnLoop(
                $scope->findWorker(),
                $scope->executor(),
                $scope->findModelId(),
                $system, ...
```


**Почему проблема:** Every model call hard-instantiates a concrete DefaultTurnLoop. The base class — the root of all workflows — is welded to one turn-loop implementation, so it cannot be swapped for tests or alternatives, and the 9-argument construction is a wiring concern leaking into the DSL helper.


**Предложение (необязательное):** Inject a TurnLoopFactoryInterface (or resolve the loop from the Environment scope) so ai() depends on an abstraction rather than constructing the collaborator.


## Дублирование


### 15. 🔴 HIGH · `src/Session.php:239-284`

**Session re-implements the ReAct loop that DefaultTurnLoop already owns (parallel, divergent)**


Session::turnLoop() is a second hand-written ReAct loop that has diverged from DefaultTurnLoop and re-introduces the empty-tool-batch infinite-loop bug


```php
private function turnLoop(): void { ... for (;;) { if ($this->maxHistory > 0 && \count($this->history) >= $this->maxHistory) { throw new ContextLengthException("History reached the configured limit of {$this->maxHistory} messages"); } ... $response = $this->agent->send(new AgentRequest( model: $this->model, messages: $this->history, system: $this->system, tools: $this->specs, )); ... if (!$response->wantsToolUse()) {
```


**Почему проблема:** DefaultTurnLoop::run() (src/Agent/DefaultTurnLoop.php:69-169) is a headless, reusable implementation of exactly this loop: max-history guard with the identical ContextLengthException message, the same AgentRequest construction, usage accumulation, assistant-message append, tool dispatch through the executor, and user-result append. Session keeps a second hand-written copy. The two have already DIVERGED in a dangerous way: Session branches on $response->wantsToolUse() while DefaultTurnLoop deliberately branches on $response->toolCalls === [] with a comment (lines 131-137) explaining that wantsToolUse() loops forever on a truncated/empty tool batch. Session therefore carries the exact bug the other copy was written to avoid. This is a classic parallel-hierarchy maintenance trap: every loop fix must be made twice and they will keep drifting.


**Предложение (необязательное):** Have Session construct and delegate to a DefaultTurnLoop (it can pass its conversation as the status/ask seam, or wrap the loop and emit updateStatus around it) instead of maintaining turnLoop()/execute()/runTool() as a second engine. At minimum, share the toolCalls === [] termination decision so the two cannot disagree.


### 16. 🟠 MEDIUM · `src/Chat/TelegramClient.php:90-95`

**call() discards the HTTP response and never checks Telegram's ok flag**


call() discards the HttpResponse and never checks ok/status, so failed write calls (sendMessage etc.) look like success — inconsistent with getUpdates which does check ok.


```php
$this->http->post($this->base . $method, $body, ['Content-Type: application/json']);
```


**Почему проблема:** getUpdates() parses the body and throws on ($data['ok'] ?? false) !== true, but every write call (sendMessage, sendChatAction, answerCallbackQuery) routes through call(), which throws away the HttpResponse entirely. Telegram returns HTTP 200 with {"ok":false,"description":...} for application-level failures (message too long, chat blocked, bad markup). Those are silently lost here, an inconsistent and surprising error-handling split within the same client — a failed reply looks like success to the caller.


**Предложение (необязательное):** Have call() decode the response and apply the same ok-check/HttpException path as getUpdates(), so write failures surface consistently.


### 17. 🟡 LOW · `src/Agent/ClaudeAgent.php:31-52`

**ClaudeAgent::attempt() and OpenAiCompatibleAgent::attempt() share an identical HTTP + error-mapping skeleton**


ClaudeAgent::attempt and OpenAiCompatibleAgent::attempt duplicate the same post/fromTransport/isOk-fromResponse/decode skeleton, differing only in URL and headers.


```php
try { $response = $this->http->post(rtrim($this->baseUrl, '/') . '/v1/messages', json_encode(self::encodeRequest($request), JSON_THROW_ON_ERROR), [...]); } catch (HttpException $e) { throw AgentErrors::fromTransport($e); } if (!$response->isOk()) { throw AgentErrors::fromResponse($response); } return self::decodeResponse($response->json());
```


**Почему проблема:** OpenAiCompatibleAgent::attempt() (src/Agent/OpenAiCompatibleAgent.php:31-51) is the same five steps in the same order: post the JSON-encoded request, map HttpException via AgentErrors::fromTransport, map a non-OK response via AgentErrors::fromResponse, decode. Only the URL suffix ('/v1/messages' vs '/chat/completions') and the auth/version headers differ. This repeated transport/error-mapping wrapper is precisely the kind of duplicated retry/error plumbing AbstractAgent exists to absorb, yet it sits in every concrete agent.


**Предложение (необязательное):** Lift the post/try-catch/isOk/decode skeleton into AbstractAgent as a protected helper (e.g. postJson(string $path, array $headers, AgentRequest $request): array), letting each concrete agent supply only the path, headers, encode and decode.


### 18. 🟡 LOW · `src/Agent/OpenAiCompatibleAgent.php:31-51`

**attempt() transport+error-handling is copy-pasted across both agents**


The post/try-catch/isOk/decodeResponse skeleton in attempt() is duplicated between ClaudeAgent and OpenAiCompatibleAgent


```php
} catch (HttpException $e) {
            throw AgentErrors::fromTransport($e);
        }

        if (!$response->isOk()) {
            throw AgentErrors::fromResponse($response);
        }

        return self::decodeResponse($response->json());
```


**Почему проблема:** ClaudeAgent::attempt() (lines 31-52) and OpenAiCompatibleAgent::attempt() (lines 31-51) are byte-for-byte identical except for the URL path, the headers array, and the decode function. The transport-call/try-catch/isOk/decode skeleton is duplicated, so any change to error handling (e.g. the json() fix above) must be made in two places and can drift.


**Предложение (необязательное):** Pull the post/catch/isOk/decode skeleton into AbstractAgent as a template method, with subclasses supplying only path, headers, and the decode callable.


### 19. 🟡 LOW · `src/Chat/AsyncConsoleConversation.php:461-467`

**Spinner frames duplicated across SpinnerBlock and AsyncConsoleConversation::spin()**


The 10-element braille spinner frame list is duplicated between spin() and SpinnerBlock::FRAMES, and the SpinnerBlock animation is re-implemented here


```php
static $frames = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏']; ... $frames[$frame % 10]
```


**Почему проблема:** This exact 10-element braille frame list is also defined in SpinnerBlock::FRAMES (src/Chat/Status/SpinnerBlock.php:10). The two will diverge if anyone edits one. Worse, Status::typing()/toolCall() build a SpinnerBlock that the async implementation deliberately discards via Status::label(), so the SpinnerBlock animation is effectively re-implemented here instead of reused.


**Предложение (необязательное):** Single-source the frame set (e.g. let the conversation drive a SpinnerBlock, or move FRAMES to one shared constant) and have the async spinner consume it.


### 20. 🟡 LOW · `src/Chat/TelegramConversation.php:49-52`

**Busy-poll inbox/pending wait loop duplicated three times**


The 'while empty: delay(50); array_shift' busy-poll is duplicated in receive(), confirm(), and TelegramChat::accept().


```php
while ($this->inbox === []) {
            delay(50);
        }

        return array_shift($this->inbox);
```


**Почему проблема:** The same 'spin on an empty array with delay(50)' pattern is copy-pasted in receive() (49-52), confirm() (84-86) and TelegramChat::accept() (57-59). Besides the duplication, polling a queue every 50ms is a workaround for not having an async primitive: it adds up to 50ms latency per message and wakes the scheduler continuously. On a TrueAsync runtime this should be a suspend/notify (channel or future) rather than three hand-rolled spin loops.


**Предложение (необязательное):** Introduce a single awaitable queue/channel abstraction and have receive(), confirm() and accept() block on it, removing the triplicated delay(50) spin.


### 21. 🟡 LOW · `src/Chat/TelegramConversation.php:47-89`

**Inbox-polling primitive 'while empty: delay(50); array_shift' copy-pasted across chat classes**


The inbox poll-and-shift idiom (and the coroutine-cancel guard) is hand-rolled across five spots in three chat classes.


```php
while ($this->inbox === []) { delay(50); } return array_shift($this->inbox);
```


**Почему проблема:** The same busy-wait-then-shift block appears in TelegramConversation::receive() and ::confirm() (lines 49-53, 84-88), in AsyncConsoleConversation::receive() and ::confirm() (src/Chat/AsyncConsoleConversation.php:113-122, 214-222), and in TelegramChat::accept() (src/Chat/TelegramChat.php:57-61). It is the same hand-rolled 50ms-poll queue consume in five spots, and it also bakes a magic 50ms literal into each. Likewise the coroutine-cancel guard (`if ($this->x !== null) { $this->x->cancel(); $this->x = null; }`) is repeated as cancelSpinner/cancelResizeWatcher (AsyncConsoleConversation) and cancelTyping (TelegramConversation).


**Предложение (необязательное):** Extract a tiny awaitable queue (e.g. a shared 'await next item' helper or use a Channel) so the poll interval and consume logic live once, and a single cancel-coroutine helper for the repeated cancel guards.


### 22. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:468-495`

**Step attribute reflected twice per step run**


stepCritic() (470) and stepMaxRounds() (491) each independently reflect the same method's Step attribute, so step() reflects it twice per run.


```php
$attributes = new \ReflectionMethod($this, $name)->getAttributes(Step::class);
```


**Почему проблема:** stepCritic() (line 470) and stepMaxRounds() (line 491) each independently reflect the same method, fetch the same Step attribute, and newInstance() it; step() calls both for every step, so the identical reflection/instantiation runs twice. The two readers of the same attribute have diverging null-handling too, which invites drift.


**Предложение (необязательное):** Resolve the Step instance once (a private stepAttribute(string $name): ?Step) and have both callers read $critic / $maxRounds off it.


### 23. 🟡 LOW · `src/Workflow/SuperviseWorkflow.php:100-109`

**extractCode() is duplicated verbatim across two workflows**


extractCode() (docblock + regex) is byte-for-byte duplicated in SuperviseWorkflow and GenerateIssueWorkflow, both extending WorkflowAbstract


```php
private function extractCode(string $text): string
    {
        $text = trim($text);
        if (preg_match('/```(?:php)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }
```


**Почему проблема:** This method — including its docblock and regex — is byte-for-byte identical to GenerateIssueWorkflow::extractCode (lines 281-289). Both subclasses of WorkflowAbstract need to unwrap a fenced code block returned by the model; copy-pasting it means a fix to the fence-stripping logic must be made in two places.


**Предложение (необязательное):** Lift extractCode() into WorkflowAbstract (as a protected helper) since stripping a model's markdown fence is a base-level concern shared by any code-generating workflow.


### 24. 🟡 LOW · `src/Workflow/GenerateIssueWorkflow.php:145-170`

**define_workflow save-retry-or-throw block duplicated between GenerateIssueWorkflow and SuperviseWorkflow**


save() control flow (define_workflow → 'saved as' check → one repair pass → retry → throw) is structurally identical in GenerateIssueWorkflow and SuperviseWorkflow.


```php
$result = $this->tool('define_workflow', ['name' => $name, 'code' => $this->code, 'shared' => true]);
        if (str_contains($result, 'saved as')) {
            return;
        }
```


**Почему проблема:** The whole save() control flow — call define_workflow, detect success by substring, do one repair pass through the same prompt, retry, throw WorkflowException on a second failure — is structurally identical to SuperviseWorkflow::save (lines 35-60). The two only differ in the property/prompt names. This parallel logic will drift (e.g. one gets a third retry, the other does not).


**Предложение (необязательное):** Extract a shared protected helper on WorkflowAbstract, e.g. saveGeneratedWorkflow(string $name, string $code, callable $repairPrompt): string, that owns the save/detect/repair/retry loop.


### 25. 🟡 LOW · `src/Workflow/GenerateIssueWorkflow.php:136-142`

**Rejected-redraft repair block duplicated across review() and save()**


The 'reviewer rejected ... Return ONLY the corrected PHP source. The constraints are unchanged:' + draftPrompt() + 'The code you produced was:' repair prompt is duplicated between review() and save().


```php
$this->code = $this->extractCode($this->ai(
            "A senior reviewer rejected the workflow you wrote. Problems to fix:\n{$verdict}\n\n"
            . "Return ONLY the corrected PHP source. The constraints are unchanged:\n\n"
            . $this->draftPrompt() . "\n\nThe code you produced was:\n\n" . $this->code,
```


**Почему проблема:** save() (lines 157-163) repeats the identical pattern: '...rejected... Return ONLY the corrected PHP source. The constraints are unchanged:' + draftPrompt() + '...The code you produced was:' + $this->code, then re-extracts. Two near-verbatim repair prompts that will drift apart when the wording is tuned.


**Предложение (необязательное):** Extract a private reviseCode(string $reason): string that builds the repair prompt and re-extracts, called by both steps.


### 26. 🟡 LOW · `src/Trace/TraceReader.php:210-217`

**Glyph + indent line assembly duplicated between live sink and history reader**


glyph match + `str_repeat indent . glyph . trim(type.summary)` assembly is duplicated between renderRows() and ConsoleTraceSink::write()


```php
$glyph = match ($this->str($row, 'phase')) {
                'enter' => '▶',
                'exit' => '◀',
                default => '·',
            };
            ...
            $lines[] = str_repeat('  ', $depth) . $glyph . ' ' . trim($type . ' ' . TraceFormat::summary($type, $data));
```


**Почему проблема:** This is a copy of ConsoleTraceSink::write (src/Trace/ConsoleTraceSink.php:35-43): the same phase→glyph match and the same `str_repeat('  ', depth) . glyph . ' ' . trim(type . ' ' . summary)` assembly. TraceFormat was extracted precisely so live and history can 'never diverge', yet only summary() was unified — the glyph and indentation logic still lives in two places and can drift. The reader's own comment ('Same one-line renderer as the live console') reveals the intent that was only half-met.


**Предложение (необязательное):** Add a TraceFormat::line(string $phase, int $depth, string $type, array $data) that produces the full glyph+indent+summary line, and call it from both ConsoleTraceSink and renderRows.


### 27. 🟡 LOW · `src/Trace/TraceReader.php:223-229`

**Identical str() scalar-coercion helper duplicated across reader and formatter**


private str() scalar-coercion helper is byte-for-byte identical to TraceFormat::str()


```php
private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
```


**Почему проблема:** This helper is byte-for-byte identical to TraceFormat::str (src/Trace/TraceFormat.php:65-70). The same null/scalar-coercion concern is solved twice in the same subsystem; a change to coercion rules (e.g. handling bool casing) must be made in two private copies that can diverge.


**Предложение (необязательное):** Promote one shared helper (e.g. a small TraceData::str() or a static on TraceFormat) and have both call it.


### 28. 🟡 LOW · `src/Cli/WorkflowMode.php:221`

**Duplicated FQCN namespace/short-name string surgery**


FQCN namespace/short-name split via substr+strrpos('\\') hand-rolled three times (lines 221, 342, 344)


```php
$solverNamespace = substr($solverClass, 0, (int) strrpos($solverClass, '\\'));
... (line 342) $fixedNamespace = substr($fixedClass, 0, (int) strrpos($fixedClass, '\\'));
... (line 344) $brokenShort = substr($brokenClass, (int) strrpos($brokenClass, '\\') + 1);
```


**Почему проблема:** The same strrpos('\\')-based split of a fully-qualified class name into namespace/short-name is hand-rolled three times across runIssue() and repairSolver(). It is easy to get the off-by-one wrong (the +1 on line 344 vs not on 221/342) and any change to the convention must be made in every copy.


**Предложение (необязательное):** Use a single helper (or WorkflowStore, which already maps names<->classes) to derive the namespace and short name, instead of repeating substr/strrpos at each call site.


### 29. 🟡 LOW · `src/Cli/WorkflowMode.php:221`

**Namespace-from-FQCN extraction duplicated with fragile casts**


Namespace-from-FQCN extraction duplicated with (int)-cast over possible strrpos false (duplicate of [4])


```php
$solverNamespace = substr($solverClass, 0, (int) strrpos($solverClass, '\\'));
```


**Почему проблема:** The same substr/strrpos('\\') idiom (with the (int) cast papering over a possible false) recurs at line 342 for $fixedNamespace and again at line 344 for the short class name. Copy-pasted string surgery on FQCNs that WorkflowStore (which already knows classFor/path) could expose directly.


**Предложение (необязательное):** Add WorkflowStore::namespaceFor()/shortNameFor() (or return a small struct) so callers don't re-derive namespace and short name by hand.


### 30. 🟡 LOW · `src/Store/SessionStore.php:161-192`

**Content-block wire (de)serialization duplicated between the Agent layer and the Store layer**


Content-block (de)serialization is duplicated between SessionStore and ClaudeAgent, with a slight drift (input (object) cast)


```php
$block instanceof ToolUseBlock => ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => $block->input], $block instanceof ToolResultBlock => ['type' => 'tool_result', 'tool_use_id' => $block->toolUseId, 'content' => $block->content, 'is_error' => $block->isError],
```


**Почему проблема:** This block->array mapping (text / tool_use / tool_result, keyed exactly as the Anthropic wire format) is a copy of ClaudeAgent::encodeBlock() (src/Agent/ClaudeAgent.php:145-163), and SessionStore::decodeBlock() (lines 182-192) mirrors ClaudeAgent::decodeResponse() (lines 99-129). The same content-block schema is now hand-maintained in two layers (transport and persistence) that have no reason to share a format other than copy-paste; the two have already drifted slightly (Claude casts tool_use input to (object), the store does not). Adding a new block type (e.g. thinking) requires editing both, and a divergence silently corrupts either the wire call or the stored history.


**Предложение (необязательное):** Extract one canonical ContentBlock <-> array codec (e.g. a ContentBlockCodec) and have both ClaudeAgent and SessionStore use it, so the block schema lives in exactly one place.


### 31. 🟡 LOW · `src/Session.php:320-330`

**Session::buildSpecs() duplicates Registry::specs()**


Session::buildSpecs() re-derives the Tool->ToolSpec mapping that Registry::specs() already provides


```php
return array_map( static fn (ToolInterface $tool): ToolSpec => new ToolSpec( $tool->name(), $tool->description(), $tool->inputSchema(), ), $this->tools->all(), );
```


**Почему проблема:** Registry already exposes exactly this mapping in specs() (src/Tool/Registry.php:50-58: `new ToolSpec($tool->name(), $tool->description(), $tool->inputSchema())` over its tools). Session holds the same Registry yet re-derives the specs itself, so the ToolInterface->ToolSpec bridge now lives in two places. WorkflowAbstract::ai() correctly calls $registry->specs() (line 286), making Session the odd one out.


**Предложение (необязательное):** Replace Session::buildSpecs() with a call to $this->tools->specs().


### 32. 🟡 LOW · `src/Config.php:169-193`

**Config merges file+env two different ways with diverging empty-value semantics**


parseAgents re-implements the file/env merge instead of reusing the $get closure, and diverges on empty-string env semantics (empty env overwrites file then drops the role, vs $get falling back to file).


```php
$merged = $file;
foreach (getenv() as $key => $value) {
    $merged[$key] = $value;
}
```


**Почему проблема:** parseAgents re-implements the file/env precedence already centralized in the $get closure (lines 78-85), but with different rules: $get ignores an empty-string env var and falls back to the file, whereas parseAgents lets an empty env value overwrite the file entry (then drops it via the `$model !== ''` guard). Two parallel merge strategies for the same .env-vs-environment concept invite drift.


**Предложение (необязательное):** Build the merged map once and reuse it, or route per-key lookups through a single helper so empty-value precedence is defined in exactly one place.


## Плохой запах


### 33. 🟠 MEDIUM · `src/Agent/ConsoleSpeaker.php:37-39`

**ConsoleSpeaker returns '' on EOF, which the loop treats as a real answer**


On EOF, ConsoleSpeaker returns '' instead of null, so the turn loop treats 'no one answered' as a real empty answer and re-prompts with empty user messages until the next MAX_TURNS checkpoint.


```php
$line = \is_resource($this->input) ? fgets($this->input) : false;

        return $line === false ? '' : rtrim($line, "\r\n");
```


**Почему проблема:** SpeakerInterface defines null as 'no answer, pass it up'. On a closed/EOF input stream ConsoleSpeaker returns '' (an empty but non-null answer). In DefaultTurnLoop::run() the [question] path treats any non-null answer as real: it appends Message::userText('') and continues the loop, so the model is re-prompted, asks again, gets '' again — an empty-message churn until the next MAX_TURNS checkpoint instead of a clean stop. EOF on a terminal human channel genuinely means 'no one answered'.


**Предложение (необязательное):** Distinguish EOF from a deliberate blank line; the contract for 'no input available' is better served by stopping/escalating than by injecting an empty user turn back into the model loop.


### 34. 🟠 MEDIUM · `src/Chat/TelegramConversation.php:84-88`

**confirm() consumes any queued inbox message, mis-reading a stale text reply as an approval**


confirm() shifts whatever is already in $inbox with no correlation to the prompt, so a type-ahead message is misread as the approval token.


```php
while ($this->inbox === []) {
            delay(50);
        }

        return Approval::fromInput((string) array_shift($this->inbox));
```


**Почему проблема:** receive() and confirm() both pop from the same $inbox with no correlation. confirm() does not drain/snapshot the inbox before sending the button prompt, so any message already queued (e.g. a 'hello' the agent had not yet consumed) is immediately shifted off and fed to Approval::fromInput — which maps anything non-y/a to Approval::No. The result: a tool gets silently refused because of an unrelated earlier message, and the inline buttons just sent dangle. Symmetrically, a 'y'/'a' typed while no confirm() is pending is swallowed by receive() as an ordinary turn. The class comment only justifies merging click+text into one queue; it does not address this temporal coupling.


**Предложение (необязательное):** Separate the approval channel from the message channel, or have confirm() establish a 'waiting for approval' marker so deliver() routes the next token to a dedicated approval slot rather than letting any pre-existing inbox entry satisfy it.


### 35. 🟠 MEDIUM · `src/Tool/BashTool.php:79-83`

**BashTool reads stdout fully before stderr — classic pipe-buffer deadlock**


Draining stdout to EOF before reading stderr can deadlock when the child fills the stderr pipe buffer.


```php
$stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
```


**Почему проблема:** stream_get_contents($pipes[1]) drains stdout to EOF before stderr is ever read. EOF only arrives when the child exits, but a child that writes more than the OS pipe buffer (~64KB) to stderr blocks on that write and never exits — so the parent blocks on stdout and the child blocks on stderr: deadlock. This is a single coroutine, so the reactor cannot interleave the two reads; the class doc's claim that 'proc_open's pipes are driven by libuv, so the read is non-blocking' (lines 11-13) does not save a sequential drain-one-then-the-other pattern. proc_close (line 83) then blocks until the (hung) process terminates.


**Предложение (необязательное):** Set both pipes non-blocking and read them together (stream_select loop / concurrent awaits) until both hit EOF, or merge stderr into stdout via the descriptor spec, before calling proc_close.


### 36. 🟠 MEDIUM · `src/Workflow/GenerateIssueWorkflow.php:88-98`

**assess() classifies difficulty by str_contains over the model's full reply, including its reasoning sentence**


assess() runs str_contains for 'complex'/'simple' over the model's ENTIRE reply (word + reasoning sentence), so a reasoning sentence mentioning the other word misclassifies the tier.


```php
$this->difficulty = str_contains($verdict, 'complex') ? 'complex'
            : (str_contains($verdict, 'simple') ? 'simple' : 'moderate');
```


**Почему проблема:** The prompt asks for one word on the first line followed by 'one sentence of reasoning', but the whole reply (word + reasoning) is then scanned with str_contains. A reply like 'moderate\nThis is not a simple change...' is misclassified as 'simple', and 'simple\nThough not very complex...' as 'complex'. The reasoning sentence routinely contains these exact words, so the classification is unreliable and silently picks the wrong worker tier.


**Предложение (необязательное):** Parse only the first line/word (e.g. strtok/explode on newline, then match the leading token) instead of substring-scanning the entire response.


### 37. 🟠 MEDIUM · `src/Trace/Tracer.php:63-73`

**exit() silently discards still-open child spans — leaves unbalanced enter/exit in the store**


exit() pops orphaned children without emitting their 'exit' records or clearing spanLevel, leaving unbalanced enter/exit rows that make stepBounds() fall back to PHP_INT_MAX


```php
while ($this->stack !== [] && $this->top() !== $id) {
            array_pop($this->stack);
        }
```


**Почему проблема:** When a parent span is closed while children are still open, those children are popped off the stack but no 'exit' record is emitted for them and their $this->spanLevel[childId] entries are never unset. The persisted trace then contains 'enter' rows with no matching 'exit'. stepBounds() depends on finding an 'exit' by span_id (line 185-189); a step whose exit is never emitted falls back to PHP_INT_MAX, making its bounds swallow every subsequent row. The comment claims it closes children 'defensively', but it discards them instead of closing them.


**Предложение (необязательное):** When popping an unmatched child during exit(), emit a synthetic 'exit' record for it and unset its spanLevel entry, so every opened span has a matching close and the spanLevel map cannot leak.


### 38. 🟠 MEDIUM · `src/Cli/WorkflowMode.php:269,316,261,296,306`

**Run status is bare magic strings while issue status is a typed enum**


Run status is stringly-typed ('running'/'generated'/'done'/'failed') while the adjacent issue status uses the IssueStatus enum


```php
$store->setRunStatus($runId, 'generated'); ... $store->setRunStatus($runId, 'done'); ... $store->setRunStatus($runId, 'failed');
```


**Почему проблема:** Run lifecycle states ('running', 'generated', 'done', 'failed') are stringly-typed and scattered across WorkflowMode and ProjectStore (recordRun default 'running', resumableRun's hardcoded WHERE status = 'running'). A typo compiles, and 'running' as the resume sentinel is duplicated in two files. IssueStatus is a proper enum — runs deserve the same, given the resume logic hinges on the exact literal.


**Предложение (необязательное):** Introduce a RunStatus enum (mirroring IssueStatus) and pass it through setRunStatus/recordRun/resumableRun instead of free strings.


### 39. 🟠 MEDIUM · `src/Cli/WorkflowMode.php:164-321`

**runIssue() is a god method orchestrating ~10 distinct responsibilities**


runIssue() orchestrates many distinct responsibilities in one ~157-line method (duplicate of [0])


```php
private function runIssue(array $args, ?string $projectDir, ?Level $verbosity): int
    { ... $registry = new Registry(); $registry->add(new BashTool(...)); ... $env = new Environment()->set(...)->set(...) ... while (true) { try { $solver = new $currentClass(...); ... } catch (WorkflowFinished) ... catch (\Throwable $e) ...
```


**Почему проблема:** This ~160-line method parses args, loads config, builds the agent, assembles the Registry, builds the 12-key Environment, derives solver class/namespace via string surgery, manages the run ledger, drives generation + a console confirm, and runs the repair/resume loop. Beyond being a long method, it mixes the composition-root wiring with run-lifecycle policy (status transitions, repair budget), so any change to wiring, to the tool set, or to the retry policy all edit the same method. It is the single hardest place in the file to reason about.


**Предложение (необязательное):** Extract collaborator assembly (registry+environment+tracer) into a small builder, and the generate/confirm and run/repair loop into their own private methods, so runIssue() reads as a short sequence of named phases.


### 40. 🟠 MEDIUM · `src/Cli/WorkflowMode.php:164-321`

**runIssue() is a 157-line method doing wiring, orchestration and a repair loop**


runIssue() is the composition root + run lifecycle + repair policy in one method (duplicate of [0]/[3])


```php
$env = new Environment()
            ->set(EnvKey::Worker, $agent)
            ->set(EnvKey::Registry, $registry) ... (12 .set calls) ... while (true) { try { $solver = new $currentClass(...); $solver->run(); break; } catch (WorkflowFinished) ...
```


**Почему проблема:** A single method resolves the store/config/agent, builds the workspace, registry (7 tool registrations) and Environment (12 setters), computes solver class names, manages run resume/record, sets up tracing, conditionally generates+confirms a workflow, then runs the solver inside a repair-and-resume loop. It is the composition root, the run lifecycle, and the error-recovery policy all at once — far too many reasons to change and hard to test in pieces.


**Предложение (необязательное):** Extract a RunWiring/composition-root builder for the registry+Environment, a SolverRunner for the repair loop, and keep runIssue() as the thin argument-validation + delegation shell.


### 41. 🟠 MEDIUM · `src/Cli/WorkflowMode.php:253-316`

**Run status uses magic strings while issue status is an enum**


Run lifecycle uses bare string literals while issue lifecycle uses the IssueStatus enum (duplicate of [1])


```php
$store->setRunStatus($runId, 'failed'); ... $store->setRunStatus($runId, 'done');
```


**Почему проблема:** Issue lifecycle is a typed enum (IssueStatus) round-tripped through the db, but the parallel concept — run lifecycle — is handled with bare string literals ('running','failed','done') scattered across ProjectStore::recordRun/resumableRun and four call sites in WorkflowMode ('failed' is repeated). This is inconsistent enum usage and primitive obsession: a typo or a status renamed in one place silently breaks the resumableRun query that hard-codes status = 'running'.


**Предложение (необязательное):** Introduce a RunStatus enum mirroring IssueStatus and have ProjectStore's recordRun/setRunStatus/resumableRun accept and persist it, removing every 'running'/'failed'/'done' literal.


### 42. 🟠 MEDIUM · `src/Project/IssueStatus.php:17-26`

**IssueStatus::fromName silently defaults to Open on an unknown status**


fromName() silently falls back to self::Open on an unknown stored status instead of failing loudly


```php
public static function fromName(string $name): self
{
    foreach (self::cases() as $case) {
        if ($case->name === $name) {
            return $case;
        }
    }

    return self::Open;
}
```


**Почему проблема:** Loading an issue with a corrupt or unrecognized status value (e.g. a renamed enum case) silently re-opens it as Open rather than surfacing the data problem. A Done issue read back with a bad status would be treated as Open and could be re-run. This swallows invalid persisted data — contrast PHP's native Role::from used elsewhere, which throws.


**Предложение (необязательное):** Throw a ClawException on an unknown name (or use a tryFrom-style accessor and handle the null at the call site) so corrupt state fails loudly.


### 43. 🟡 LOW · `src/Agent/OpenAiCompatibleAgent.php:118-123`

**Malformed tool-call arguments are silently dropped to an empty input**


Invalid/non-array tool-call arguments JSON is silently coerced to [] via the is_array guard, discarding the model's intended input


```php
$arguments = json_decode($call['function']['arguments'] ?? '{}', true);
            $useBlock = new ToolUseBlock(
                (string) ($call['id'] ?? ''),
                (string) ($call['function']['name'] ?? ''),
                is_array($arguments) ? $arguments : [],
            );
```


**Почему проблема:** If the provider returns an arguments string that is not valid JSON (or a JSON scalar), json_decode returns null/non-array and the is_array() guard quietly substitutes []. The model's intended tool input is discarded with no signal, so the tool then runs with empty args and the failure surfaces far downstream as a confusing tool error rather than a decode error.


**Предложение (необязательное):** On non-array decode, surface it (log/throw a BadRequestException or mark the block) rather than swallowing it into an empty input.


### 44. 🟡 LOW · `src/Agent/AgentResponse.php:17-23`

**AgentResponse stores text redundantly alongside the TextBlocks in content**


AgentResponse::$text duplicates the concatenation of content's TextBlocks, which each decoder recomputes by hand and could drift


```php
public readonly array $content,
        public readonly array $toolCalls,
        public readonly StopReason $stopReason,
        public readonly Usage $usage,
        public readonly ?string $text = null,
```


**Почему проблема:** `text` is just the concatenation of the TextBlocks already present in `content`, and each decoder recomputes it by hand (ClaudeAgent collects $texts then implode; OpenAiCompatibleAgent sets $text alongside the TextBlock). The derived field can drift from content and adds a second thing every new decoder must remember to populate consistently.


**Предложение (необязательное):** Derive text on demand via a method that folds the TextBlocks in content, instead of storing it as a separate constructor field each decoder must keep in sync.


### 45. 🟡 LOW · `src/Agent/DefaultTurnLoop.php:202-204`

**extractQuestion falls back to the literal marker as the question text**


On a bare '[question]' marker the fallback returns the literal '[question]' as the question text


```php
$question = trim(str_replace(self::QUESTION_MARKER, '', $text));

        return $question === '' ? trim($text) : $question;
```


**Почему проблема:** When the model emits a bare '[question]' with no text, the stripped remainder is empty, so the method returns trim($text) which is the literal string '[question]'. The ask channel (a human or another agent) then receives '[question]' as the question to answer — a meaningless prompt. The comment claims 'a bare marker still asks something', but what it asks is the marker itself.


**Предложение (необязательное):** On an empty remainder, return a real fallback prompt (e.g. 'The worker paused for input but gave no question.') or treat a content-free marker as a normal final answer rather than a question.


### 46. 🟡 LOW · `src/Chat/AsyncConsoleConversation.php:467`

**Magic number `% 10` hardcodes the spinner frame count**


Spinner index uses literal `% 10` instead of count($frames), so editing the frame list silently breaks indexing


```php
self::C_SPIN . $frames[$frame % 10] . self::C_RESET
```


**Почему проблема:** The modulus is the literal 10 rather than count($frames). SpinnerBlock does the same thing correctly with `self::$frame % \count(self::FRAMES)`. If a frame is added or removed here, the index silently goes out of range or skips frames.


**Предложение (необязательное):** Use `$frame % \count($frames)`.


### 47. 🟡 LOW · `src/Chat/AsyncConsoleConversation.php:113-119`

**receive()/confirm() poll a shared inbox with delay(50) — temporal coupling between coroutines**


receive() and confirm() busy-poll a shared inbox with delay(50) instead of using a channel/future, adding latency and temporal coupling to readLoop()


```php
while ($this->inbox === [] && !$this->eof) { delay(50); } ... return array_shift($this->inbox);
```


**Почему проблема:** Both receive() (113-119) and confirm() (214-222) spin on a 50ms poll over $this->inbox, which a separate readLoop() coroutine mutates. This is a hand-rolled producer/consumer with no signaling primitive: it adds up to 50ms latency per line and couples the two coroutines through shared mutable array state and an implicit timing assumption rather than a channel/future.


**Предложение (необязательное):** Use a proper async channel/queue (the codebase already has an inbox channel in Session) so the consumer parks until a line is actually available instead of polling.


### 48. 🟡 LOW · `src/Chat/Status/SpinnerBlock.php:9-18`

**SpinnerBlock keeps mutable static state and mutates it inside render()**


SpinnerBlock's frame counter is a process-global static mutated as a side effect of render(), so it's shared across all instances and render() is non-idempotent.


```php
private static int $frame = 0; ... public function render(): string { $char = self::FRAMES[self::$frame % \count(self::FRAMES)]; self::$frame++;
```


**Почему проблема:** render() is expected to be a pure read on a value object (TextBlock/ToolCallBlock/TokenUsageBlock all are), but SpinnerBlock advances a process-global static counter as a side effect. Every SpinnerBlock instance shares one frame counter, so two concurrent status lines interfere, and calling render() twice for the same paint yields different glyphs. Hidden global mutable state behind a 'render' name is surprising.


**Предложение (необязательное):** Make the frame an instance field, or have the renderer own the animation clock and pass the frame index in.


### 49. 🟡 LOW · `src/Chat/TelegramConversation.php:76-82`

**confirm() bypasses send(), leaving the typing keep-alive running during a prompt**


confirm() calls sendMessage directly and skips cancelTyping(), so the 'typing…' keep-alive keeps firing while the approval buttons wait for the user.


```php
$this->client->sendMessage($this->chatId, $prompt, [
            'inline_keyboard' => [[
```


**Почему проблема:** send() deliberately calls cancelTyping() before writing ('the reply itself ends the typing indicator'), but confirm() calls $this->client->sendMessage directly and skips that step. If updateStatus() has started the keep-alive coroutine, it keeps re-sending 'typing…' while the approval buttons are displayed, contradicting the invariant send() establishes for permanent output.


**Предложение (необязательное):** Cancel typing in confirm() before sending the prompt (or route the prompt through a shared internal send helper), so both permanent-output paths behave the same.


### 50. 🟡 LOW · `src/Tool/DefineWorkflowTool.php:74-76`

**DefineWorkflowTool swallows the original exception instead of chaining**


catch re-wraps WorkflowException into ToolException using only getMessage(), dropping the cause chain


```php
} catch (WorkflowException $e) {
            throw new ToolException($e->getMessage());
        }
```


**Почему проблема:** Re-wrapping with only getMessage() discards the cause chain and the original stack trace, making validator/store failures harder to diagnose. The codebase has a typed exception hierarchy precisely so causes can be followed.


**Предложение (необязательное):** Pass the original as previous: `throw new ToolException($e->getMessage(), 0, $e);`


### 51. 🟡 LOW · `src/Tool/DateTool.php:13`

**Inconsistent final readonly vs final across the tool classes**


DateTool is `final class` while sibling stateless tools (BashTool, ReadFileTool, etc.) are `final readonly class`, an inconsistent immutability style across the same package.


```php
final class DateTool implements ToolInterface
```


**Почему проблема:** BashTool, ReadFileTool, WriteFileTool, ListFilesTool, ScheduleTool are `final readonly class`, while DateTool, PhpEvalTool, FinishTool, HandoffTool, DefineWorkflowTool, RecallTool are plain `final class` (the latter group instead marking individual ctor props readonly, or having no state at all). All are stateless/immutable tools, so the split is arbitrary and makes the immutability contract read inconsistently across the same package.


**Предложение (необязательное):** Pick one convention for stateless tools (e.g. `final readonly class` everywhere) and apply it uniformly.


### 52. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:251-301`

**ai() is a long method mixing scope-building, routing, prompt assembly and loop wiring**


ai() (lines 251-301, ~50 lines) bundles palette narrowing, scope/agent routing, prompt+tool-briefing assembly and 9-arg loop wiring.


```php
protected function ai(string $prompt, ?array $tools = null, ?string $agent = null): string
    {
        $this->enforceBudget();
```


**Почему проблема:** This single method does palette narrowing, child-scope creation, agent->model routing, tool-name extraction for tracing, system-prompt augmentation, ask-channel resolution, DefaultTurnLoop construction with nine constructor args, budget re-checks, and span management. The density makes the actual model call (one line, 294) hard to find and each concern hard to change in isolation.


**Предложение (необязательное):** Pull palette/scope construction and the turn-loop construction into small private helpers (e.g. buildPalette(), buildLoop($scope, $system)) so ai() reads as a short orchestration.


### 53. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:216-221`

**artifact() silently prefers $file over $text with no enforcement of the 'not both' rule**


artifact() silently prefers $file over $text and accepts neither, despite the doc's 'not both' rule, producing an empty artifact when called with no args.


```php
$entry = $file !== null ? Artifact::file($label, $file) : Artifact::text($label, $text ?? '');
```


**Почему проблема:** The doc states 'Pass either $text or $file ... not both', but the code enforces nothing: pass both and $text is silently discarded; pass neither and you get a meaningless empty text artifact under whatever $currentStep happens to be (possibly '' when called outside a step). Two optional, mutually-exclusive primitives with implicit precedence is an error-prone flag-pair API for AI-generated callers.


**Предложение (необязательное):** Either accept an Artifact/ArtifactKind value object, or throw when both or neither is supplied so the contract is enforced rather than documented.


### 54. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:129-181`

**step() is a long method mixing skip-logic, critic loop, supervision and snapshotting**


step() (129-181, ~52 lines) mixes skip-check, budget gate, span bookkeeping, an unbounded while(true) critic/supervise loop with four break conditions, finally-reset and durable save.


```php
while (true) {
                $this->artifacts[$name] = [];
                $raw = $this->{$name}();
                $result = \is_string($raw) ? $raw : '';
                if ($rubric === null) { break; }
                $findings = $this->critic(...);
```


**Почему проблема:** One method handles done-skip, budget gate, span bookkeeping, the unbounded critic/supervise rework loop, transient-field reset in finally, and the durable save. The nested while(true) with four distinct break conditions is the kind of control flow that's hard to reason about and is exactly the cohesion problem of the god class.


**Предложение (необязательное):** Pull the critic rework loop into the proposed CriticSupervisor so step() reduces to: skip-check, run-with-review, record-done+save.


### 55. 🟡 LOW · `src/Workflow/Artifact.php:18-39`

**Artifact kind is a stringly-typed 'text'|'file' with a switch in render()**


Artifact.kind is a stringly-typed 'text'|'file' value branched on in render(), where an enum is the codebase convention


```php
public readonly string $kind,   // 'text' | 'file'
```


**Почему проблема:** The kind is a primitive string whose only two legal values are documented in a comment, then branched on with `$this->kind === 'file'` in render(). This is primitive obsession / a magic string in a codebase that otherwise models closed value sets as enums (BudgetPolicy, EnvKey). Adding a third artifact kind means editing the render() conditional rather than the type.


**Предложение (необязательное):** Introduce an ArtifactKind enum (Text, File) and switch render() on it; the named factories stay.


### 56. 🟡 LOW · `src/Workflow/GenerateIssueWorkflow.php:153`

**Success of define_workflow detected by fragile substring match on tool output**


Save success is detected by str_contains($result,'saved as') over the tool's raw human-readable output, with no shared constant.


```php
if (str_contains($result, 'saved as')) {
```


**Почему проблема:** Save success/failure is decided by sniffing the literal phrase 'saved as' inside the tool's raw string output (same pattern in SuperviseWorkflow:43,56). This couples control flow to an unstructured human-readable message: any rewording of define_workflow's confirmation silently flips every saved workflow into a 'rejected' branch, triggering a needless repair pass and possibly a thrown WorkflowException on success. A validator complaint that happens to contain the words 'saved as' would be read as success.


**Предложение (необязательное):** Have the tool/door return a structured result (e.g. a typed value or a sentinel) the workflow can test, rather than parsing a magic phrase out of a prose string.


### 57. 🟡 LOW · `src/Workflow/GenerateIssueWorkflow.php:152-169`

**Workflow control flow keyed on human-readable tool-output substrings**


Workflow branching is keyed on human-readable tool-output substrings ('saved as', 'tool ... failed:') rather than a typed outcome.


```php
$result = $this->tool('define_workflow', ['name' => $name, 'code' => $this->code, 'shared' => true]);
        if (str_contains($result, 'saved as')) {
            return;
        }
```


**Почему проблема:** Success/failure of a tool is decided by str_contains($result, 'saved as') (and elsewhere the draft prompt documents detecting failures via the literal "tool '<name>' failed:" prefix). The workflow's branching is coupled to the exact prose DefineWorkflowTool happens to emit; reword the tool's confirmation message and the generator silently treats every save as a failure (and loops a repair pass / throws). This is control flow over a stringly-typed result that should be a typed outcome.


**Предложение (необязательное):** Have tools return a structured result (or have define_workflow raise a typed ToolException on rejection) so the workflow checks a flag/exception rather than matching display text; if the string contract must stay, centralize the sentinel as a shared constant.


### 58. 🟡 LOW · `src/Workflow/SqliteStateStore.php:63-68`

**nextId() inserts a never-deleted row per id into state_seq**


nextId() mints ids by inserting a row into state_seq that is never deleted, so the table grows unboundedly forever.


```php
public function nextId(): string
    {
        $this->pdo->exec('INSERT INTO state_seq DEFAULT VALUES');

        return (string) $this->pdo->lastInsertId();
```


**Почему проблема:** Each id is minted by inserting a row that is never read or removed, so state_seq grows by one row for every leaf call forever — an unbounded table whose only purpose is to advance an AUTOINCREMENT counter. Over a long-lived project DB this is wasted storage with no reclamation path.


**Предложение (необязательное):** Use a single-row counter updated in place (UPDATE ... RETURNING / a max+1 in a small table), or SQLite's sqlite_sequence, instead of accumulating one throwaway row per id.


### 59. 🟡 LOW · `src/Trace/TraceReader.php:147`

**describe() uses `?: []` decode fallback, diverging from the is_array guard used everywhere else**


describe() uses a `?: []` decode fallback while every other decode site in the class uses the is_array() guard


```php
$data = $row === false ? [] : (json_decode($this->str($row, 'data'), true) ?: []);
```


**Почему проблема:** Every other decode site in this class guards with `\is_array($decoded) ? $decoded : []` (lines 118, 136, 207-208), but here a truthiness `?:` is used instead. The two idioms behave differently (a decoded `0`, `''` or `false` would be coerced to `[]` here) and the inconsistency forces a reader to check why one site differs. Right after, line 148 re-checks `\is_array($data)`, which is now redundant because `?: []` already guarantees an array unless decode returned a non-array truthy value.


**Предложение (необязательное):** Use the same `\is_array(json_decode(...)) ? ... : []` pattern as the sibling methods for a single decode idiom across the file.


### 60. 🟡 LOW · `src/Trace/TraceRecordInterface.php:26`

**`phase` is a magic-string triple repeated as a match in three files**


`phase()` is a bare string for a closed set {enter,exit,event} that could be a PHP 8.1 enum, with the literals re-typed as match arms in two sinks and the Tracer.


```php
/** 'enter' (span opened), 'exit' (span closed), or 'event' (a point under the current span). */
    public function phase(): string;
```


**Почему проблема:** phase is a closed set of three values ('enter','exit','event') but modelled as a bare string. The literals are re-typed in Tracer (open/event/exit emit calls), ConsoleTraceSink:35-39 and TraceReader:210-214 as match arms, with no compiler check that producers and consumers agree. A typo in any producer silently degrades to the '·' default glyph. This is primitive obsession over what is naturally an enum.


**Предложение (необязательное):** Introduce a Phase enum (Enter/Exit/Event) used by the record and both renderers, mirroring how Level already replaced an ad-hoc int.


### 61. 🟡 LOW · `src/Http/HttpResponse.php:30-41`

**HttpResponse::json() type-hints object JSON but accepts any array**


json() declares @return array<string, mixed> but is_array() also admits JSON lists, so a top-level array body is returned despite the string-keyed contract.


```php
* @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new HttpException("Invalid JSON response (HTTP {$this->status})");
```


**Почему проблема:** The @return is array<string, mixed> (string-keyed object), but the guard only checks is_array(): a top-level JSON array body (e.g. '[1,2,3]') passes and is returned as a list, violating the documented string-keyed shape. Callers relying on the static type for associative access get a type the runtime does not actually guarantee.


**Предложение (необязательное):** Either widen the return type to array<int|string, mixed> to reflect that lists also pass, or tighten the guard to reject non-associative results so the array<string, mixed> annotation holds.


### 62. 🟡 LOW · `src/Cli/WorkflowMode.php:328-339`

**repairSolver carries a 10-parameter Issue/Project/Tracer data clump threaded through the run**


repairSolver() takes 10 positional parameters, a data clump (env/tracer/store/runId/issue/project) threaded from runIssue


```php
private function repairSolver(
    Environment $env,
    Tracer $tracer,
    WorkflowStore $workflowStore,
    string $brokenClass,
    string $baseName,
    string $error,
    string $runId,
    int $attempt,
    Issue $issue,
    Project $project,
): ?string {
```


**Почему проблема:** Ten positional parameters, several of which (env, tracer, workflowStore, runId, issue, project) are the same context threaded through runIssue and into every workflow constructor `new ...($env, $runId, [...], $issue, $project)`. This data clump signals a missing run-context object and makes call sites error-prone (positional Issue vs Project, two adjacent string ids).


**Предложение (необязательное):** Bundle the stable run context (env, tracer, runId, issue, project, workflowStore) into a small value object passed once.


### 63. 🟡 LOW · `src/Project/ProjectStore.php:153-160`

**Inconsistent error wrapping between addIssue and loadIssue**


loadIssue documents @throws ClawException but lets raw PDOExceptions escape unwrapped, unlike addIssue/init.


```php
$stmt = $this->pdo->prepare('SELECT title, description, status FROM issues WHERE id = :id'); $stmt->execute(['id' => $issueId]);
```


**Почему проблема:** addIssue() wraps PDOExceptions in ClawException, but loadIssue() (documented '@throws ClawException') leaves raw PDOExceptions to escape — only the not-found case throws ClawException. Callers in WorkflowMode catch ClawException expecting a clean message; a driver error would slip past as an unhandled \PDOException.


**Предложение (необязательное):** Wrap the query/execute in loadIssue (and recordRun/resumableRun/status setters) in the same ClawException translation used by addIssue, or drop the misleading @throws.


### 64. 🟡 LOW · `src/Store/SessionStore.php:61-63,75-77,91-93,103-105,115-117,130-132`

**Defensive `=== false` branches after query/prepare are unreachable under ERRMODE_EXCEPTION**


`=== false` guards after query()/prepare() are unreachable under ERRMODE_EXCEPTION and inconsistent (some return [], some throw)


```php
$stmt = $this->pdo->query('SELECT role, content FROM messages ORDER BY seq');
if ($stmt === false) {
    return [];
}
```


**Почему проблема:** The constructor sets PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION, so query()/prepare() throw PDOException on failure and never return false. Every `if ($stmt === false)` branch here is dead code, and they are inconsistent to boot — load()/auditTrail() silently return [] while append()/allowTool() throw ClawException for the same impossible condition. This noise obscures the real control flow.


**Предложение (необязательное):** Drop the false-checks (or, if kept for static analysis, make them consistently throw); rely on the exception mode already configured.


### 65. 🟡 LOW · `src/Project/IssueStatus.php:8-26`

**IssueStatus reimplements backed-enum behavior by hand and swallows bad data**


IssueStatus is a hand-rolled pure enum with a linear fromName scan that swallows bad data, inconsistent with the codebase's backed-string enums


```php
enum IssueStatus ... public static function fromName(string $name): self { foreach (self::cases() as $case) { if ($case->name === $name) { return $case; } } return self::Open; }
```


**Почему проблема:** Every other enum in the codebase (Role, SpeakerRole, EnvKey) is a backed string enum that persists via ->value and resolves via the built-in ::from()/::tryFrom(). IssueStatus is a pure enum that persists via ->name and hand-rolls a linear fromName() scan — an inconsistent pattern for the same job. Worse, on an unknown stored value it silently returns self::Open, masking db corruption or a renamed case instead of failing loudly.


**Предложение (необязательное):** Make it `enum IssueStatus: string` with explicit case values and use ::from() (which throws on unknown input), dropping the bespoke fromName() loop and the silent Open fallback.


## Плохие комментарии


### 66. 🟡 LOW · `src/Chat/Status.php:49-52`

**Misleading comment: Status::label() refers to a 'terminal thread' that does not exist**


label() docblock says animation runs in a 'terminal thread' but it is actually a coroutine, contradicting the codebase's own concurrency model


```php
* Text for the terminal thread: animated statuses skip the SpinnerBlock
     * (the thread animates its own spinner), static statuses return the full render.
```


**Почему проблема:** The async implementation's own class docblock states 'Everything runs in the caller's thread as coroutines … no worker thread is needed' (AsyncConsoleConversation.php:23-26). This comment describes a non-existent 'terminal thread' animating its own spinner, a leftover from an earlier threaded design. It misleads readers about the concurrency model.


**Предложение (необязательное):** Reword to 'the renderer animates its own spinner coroutine' and drop the thread terminology.


### 67. 🟡 LOW · `src/Tool/RecallTool.php:86`

**recall's fallback error lists the wrong set of valid options**


recall's default-branch error lists only 4 of 6 valid 'what' values, omitting 'task' and 'handoff'.


```php
default => throw new ToolException("recall: unknown what '{$what}' (use workflow|step|tool|artifacts)"),
```


**Почему проблема:** The enum and the match support six values — 'task', 'handoff', 'workflow', 'step', 'tool', 'artifacts' (lines 53, 75-85) — but the error message offered to the model omits 'task' and 'handoff'. A model that hit this branch is steered away from two valid options.


**Предложение (необязательное):** List all six valid `what` values in the message (or interpolate them from the enum/schema to keep them in sync).


### 68. 🟡 LOW · `src/Tool/RecallTool.php:86`

**recall error message lists a stale, incomplete set of valid `what` values**


Same line-86 error message omits 'task' and 'handoff' from the listed valid values.


```php
default => throw new ToolException("recall: unknown what '{$what}' (use workflow|step|tool|artifacts)"),
```


**Почему проблема:** The enum (line 53), inputSchema, and description all accept six values: task, handoff, workflow, step, tool, artifacts. The fallback error message only names four (workflow|step|tool|artifacts), omitting 'task' and 'handoff'. A model that mistypes 'what' is told a list that lies about what is actually accepted — exactly the kind of guidance that mis-steers the agent. The list was evidently not updated when 'task' and 'handoff' were added.


**Предложение (необязательное):** List all six accepted values (or, to avoid future drift, derive the message from the same enum used in inputSchema): "... (use task|handoff|workflow|step|tool|artifacts)".


### 69. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:557`

**Doc @see points at a constant that does not exist**


Docblock {@see MAX_CRITIC_ROUNDS} (line 557) references a constant that does not exist; the real cap is DEFAULT_MAX_ROUNDS / the $maxRounds param.


```php
* Below {@see MAX_CRITIC_ROUNDS} this self-corrects on the critic's findings when no one is on the
```


**Почему проблема:** There is no MAX_CRITIC_ROUNDS constant; the cap is DEFAULT_MAX_ROUNDS (line 48) and the per-step override $maxRounds. The {@see} reference is dead and the prose names a symbol the reader cannot find, so the doc misleads about how the threshold is sourced.


**Предложение (необязательное):** Replace {@see MAX_CRITIC_ROUNDS} with a reference to the $maxRounds parameter / DEFAULT_MAX_ROUNDS.


### 70. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:559`

**Docblock references a constant name that does not exist**


Dead {@see MAX_CRITIC_ROUNDS} doc link (text on line 557, finding cites 559) — same defect as [0].


```php
Below {@see MAX_CRITIC_ROUNDS} this self-corrects on the critic's findings when no one is on the channel
```


**Почему проблема:** There is no MAX_CRITIC_ROUNDS constant; the cap is DEFAULT_MAX_ROUNDS (line 48) and the effective value is the per-step maxRounds parameter. The {@see} is a dead link and misnames the mechanism it documents.


**Предложение (необязательное):** Reword to reference the $maxRounds parameter / DEFAULT_MAX_ROUNDS.


### 71. 🟡 LOW · `src/Http/HttpClientInterface.php:7-26`

**HttpClientInterface contract lies about retries**


HttpClientInterface docblock promises transport-level retries (and '@throws after retries') that the sole implementation, CurlHttpClient, deliberately does not provide — retries live in AbstractAgent.


```php
Implementations handle retries on transport-level
 * conditions (network errors, 429, 5xx); application-level errors are left to
 * the caller via the returned status. ... @throws \Claw\Exceptions\HttpException on transport failure after retries
```


**Почему проблема:** The interface contract promises implementations retry on network errors/429/5xx and only throw 'after retries'. The sole implementation (CurlHttpClient) does NO retries — its own docblock states 'No retries here — retries are cause-aware and live in the agent's send() (AbstractAgent)', confirmed by BackoffAgentRetryPolicy/AbstractAgent owning retry. The interface documents behaviour no implementation provides and that the design deliberately places elsewhere; a future implementer would wrongly assume retries are their responsibility, and the @throws 'after retries' note is simply false.


**Предложение (необязательное):** Rewrite the interface docblock to match reality: a thin single-shot transport that throws HttpException on transport failure (no retries; retries are the agent layer's concern). Drop 'after retries' from both @throws lines.


### 72. 🟡 LOW · `src/Store/SessionStore.php:15-47`

**SessionStore stores permission rules and audit, contradicting its own "one conversation's history" docblock**


Class docblock describes only 'conversation history' but the class also owns the rules and audit tables plus their methods


```php
/**
 * One SQLite file = one conversation's history. ... Each Message is a row ...
 */
final class SessionStore
{ ... $this->pdo->exec('CREATE TABLE IF NOT EXISTS rules ...'); ... CREATE TABLE IF NOT EXISTS audit ...
```


**Почему проблема:** The class comment claims the store is purely conversation history, but the constructor also owns the permission "always allow" rules table and the tool-call audit log, and the class exposes isToolAllowed/allowTool/logToolCall/auditTrail. The comment misleads readers about responsibility, and the type carries three unrelated concerns (history, permissions, audit).


**Предложение (необязательное):** Either update the docblock to state all three responsibilities or, better, separate the rules/audit tables into their own store types and keep SessionStore to message history.


## Конвенции / нейминг


### 73. 🟠 MEDIUM · `src/Workflow/Environment.php:108-116`

**Interface lacks the mandated *Interface suffix**


WorkflowStateStore is declared as an interface but lacks the project-mandated *Interface suffix, used here as findStore(): WorkflowStateStore.


```php
public function findStore(): WorkflowStateStore
    {
        $store = $this->find(EnvKey::Store);
        if (!$store instanceof WorkflowStateStore) {
```


**Почему проблема:** WorkflowStateStore is declared `interface WorkflowStateStore` (src/Workflow/WorkflowStateStore.php:21) yet has a bare name. The project convention is that every interface MUST carry the *Interface suffix (cf. ToolInterface, SpeakerInterface, AgentInterface, ExecutorInterface used right next to it in this same file). This is a direct, codebase-wide convention break and is inconsistent with every other abstraction the workflow depends on.


**Предложение (необязательное):** Rename the interface to WorkflowStateStoreInterface and update its implementors (InMemoryStateStore, SqliteStateStore), the EnvKey comment, and the Environment finder. Advisory only.


### 74. 🟠 MEDIUM · `src/Workflow/WorkflowStateStore.php:21`

**Interface WorkflowStateStore violates the mandatory *Interface suffix convention**


Interface WorkflowStateStore lacks the mandatory *Interface suffix


```php
interface WorkflowStateStore
```


**Почему проблема:** Project convention requires every interface name to end in `Interface`. This is the ONLY interface in src/ that breaks it — all 18 others (ToolInterface, AgentInterface, and notably its own sibling WorkflowInterface) comply. Concrete classes InMemoryStateStore/SqliteStateStore implement it, so a reader cannot tell from the name that WorkflowStateStore is the abstraction.


**Предложение (необязательное):** Rename to WorkflowStateStoreInterface (and update the `implements` clauses in InMemoryStateStore and SqliteStateStore plus any wiring).


### 75. 🟠 MEDIUM · `src/Workflow/WorkflowStateStore.php:21`

**WorkflowStateStore is a bare interface name, violating the *Interface convention**


Bare interface name WorkflowStateStore violates the *Interface convention (duplicate of [0])


```php
interface WorkflowStateStore
```


**Почему проблема:** Project convention (and every other interface in the tree: ToolInterface, ExecutorInterface, TurnLoopInterface, TraceSinkInterface, ...) requires the *Interface suffix. WorkflowStateStore is an interface (implemented by InMemoryStateStore and SqliteStateStore) but is named like a concrete class. It is the only interface in src/ that breaks the rule, and the bare name actively reads as a class at its use sites (e.g. WorkflowAbstract).


**Предложение (необязательное):** Rename to WorkflowStateStoreInterface (and update implementors/usages) to match the enforced convention.


### 76. 🟠 MEDIUM · `src/Workflow/WorkflowStateStore.php:21`

**Interface missing mandatory *Interface suffix**


Interface missing *Interface suffix, confusable with concrete WorkflowStore class (duplicate of [0]/[2])


```php
interface WorkflowStateStore
```


**Почему проблема:** Project convention requires every interface to carry the *Interface suffix (ToolInterface, WorkflowInterface, TraceSinkInterface all comply). WorkflowStateStore is a bare-named interface — the only violation among the 19 interfaces in src. It is also confusingly indistinguishable from the concrete WorkflowStore class.


**Предложение (необязательное):** Rename to WorkflowStateStoreInterface. While renaming, consider giving the implementations the matching prefix (InMemoryWorkflowStateStore / SqliteWorkflowStateStore) so the interface+implementation family reads as a unit; currently InMemoryStateStore/SqliteStateStore drop the 'Workflow' the interface carries.


### 77. 🟡 LOW · `src/Agent/DefaultTurnLoop.php:38, 90`

**MAX_TURNS is a recurring checkpoint, not a maximum — the name lies**


MAX_TURNS is used as a recurring checkpoint interval (modulo), so the name overstates it as a hard cap


```php
private const int MAX_TURNS = 50;  ...  if ($turnNo > 0 && $turnNo % self::MAX_TURNS === 0 && !$this->keepGoing($turnNo)) {
```


**Почему проблема:** The constant is named MAX_TURNS and the docblock calls it a 'cap', but the code uses it as a modulo interval. With an ask channel that replies 'continue', the loop sails past turn 50 to 100, 150, ... unbounded — there is no maximum at all. A reader trusting the name will believe the loop is hard-capped at 50 turns when it is not. The only real hard bound is the optional Budget.


**Предложение (необязательное):** Rename to something like TURN_CHECKPOINT_INTERVAL and adjust the docblock to say it is a periodic keep-going checkpoint, not a ceiling — or add an actual absolute turn ceiling if a hard cap is intended.


### 78. 🟡 LOW · `src/Workflow/WorkflowAbstract.php:269`

**Agent-role names are scattered magic strings instead of a typed catalog**


Agent-role names ('worker' L269, 'reviewer' L533, plus supervisor/planner in docs) are bare string literals; an unknown role silently falls back to the default model.


```php
$span = $tracer?->enterAi($agent ?? 'worker', $scope->findModelId());
```


**Почему проблема:** The well-known agent roles ('worker' here, 'reviewer' at line 533, plus 'supervisor'/'planner' named in the docs) are loose string literals threaded through ai(), critic(), and the EnvKey::Agents map lookups. The project already models its other closed key sets as enums (EnvKey, BudgetPolicy); leaving roles as bare strings means a typo silently falls back to the default model (agentModel() returns null on an unknown role) with no error, and the valid set is undiscoverable.


**Предложение (необязательное):** Define an AgentRole enum (Worker, Reviewer, Supervisor, Planner) and use it for the default in ai(), the critic call, and the Agents map keys.


### 79. 🟡 LOW · `src/Exec/ChainExecutor.php:16`

**ChainExecutor not declared readonly unlike its sibling classes**


ChainExecutor is `final class` while sibling middlewares are `final readonly class` and both its properties are already readonly — a minor convention inconsistency.


```php
final class ChainExecutor implements ExecutorInterface
```


**Почему проблема:** Every other class in this subsystem (AuditMiddleware, PermissionMiddleware, TimeoutMiddleware) is 'final readonly class', and ChainExecutor's only two properties are both declared readonly individually. Declaring per-property readonly while leaving the class non-readonly is an inconsistent convention break versus the rest of the package for a type that is fully immutable.


**Предложение (необязательное):** Declare 'final readonly class ChainExecutor' and drop the now-redundant per-property readonly modifiers, matching the other middleware classes.


### 80. 🟡 LOW · `src/Session.php:271`

**Inline fully-qualified Usage while every other Agent type is imported**


Usage is referenced by inline FQN at line 271 while all other Claw\Agent types are imported at the top


```php
$this->conversation->updateStatus(Status::done(new \Claw\Agent\Usage($totalInput, $totalOutput)));
```


**Почему проблема:** Message, Role, ToolResultBlock, ToolUseBlock, ToolSpec and the rest of Claw\Agent are imported at the top, but Usage is referenced by inline FQN here. The inconsistency is a small readability snag and an outlier against the file's own convention.


**Предложение (необязательное):** Add `use Claw\Agent\Usage;` and write `new Usage(...)`.


### 81. 🟡 LOW · `src/Exceptions/WorkflowFinished.php:14`

**Control-signal class breaks the *Exception naming convention of its siblings**


WorkflowFinished lives in Exceptions/ and extends ClawException but omits the *Exception suffix that every sibling uses.


```php
final class WorkflowFinished extends ClawException
```


**Почему проблема:** Every other type in src/Exceptions/ ends in `Exception` (AgentException, WorkflowException, ToolException, …). WorkflowFinished lives in the Exceptions namespace and extends ClawException but is named like a past-tense event, making it inconsistent with the rest of the hierarchy and easy to overlook as a throwable when scanning catch blocks.


**Предложение (необязательное):** Either rename to a convention-following name (e.g. WorkflowFinishedException / WorkflowDoneSignalException) or, if it is truly a signal rather than an exception, move it out of the Exceptions namespace and base it on a non-error marker.


---


## Отсеяно при верификации


Эти находки финдеры предложили, но независимый верификатор отклонил как ложные срабатывания / придирки / непонимание идиом. Приведены для прозрачности.


- **`src/Agent/ClaudeAgent.php`** — Comment pins behavior to specific model versions that will age
  
  _Причина отклонения:_ The quoted comment exists verbatim at line 79. However this is a nitpick, not a genuine defect. The comment documents the real reason the code omits temperature when null (a 400 rejection observed for certain models), which is useful context for a future reader who might otherwise 'simplify' by always sending temperature. Version-specific notes aging slightly is not something a careful senior reviewer would genuinely flag as needing change; the explanatory why is valuable. False positive.

- **`src/Agent/OpenAiCompatibleAgent.php`** — Only DeepSeek gets a named constructor on a class advertised for seven providers
  
  _Причина отклонения:_ Quote matches lines 26-29 and the docblock does list seven providers. But the public constructor already accepts an arbitrary baseUrl, so every other provider is fully constructible without a factory; deepSeek() is just convenience for the apparent default. Providing a named constructor for the primary provider while the generic constructor covers the rest is idiomatic, not an asymmetry bug. This is a stylistic nitpick a senior reviewer would not genuinely flag.

- **`src/Agent/Budget.php`** — Budget repeats the exhaustion threshold checks in two methods
  
  _Причина отклонения:_ Evidence confirmed: the predicate pair appears verbatim in isExhausted() (lines 70,73) and reason() (lines 83,86). But the duplication is trivial and idiomatic — the two methods return different things (bool vs human-readable string) for different purposes, and the repeated checks are two tiny self-evident comparisons. A senior reviewer would treat a tokenExhausted()/timeExhausted() extraction as an optional micro-cleanup, not a genuine defect; it's a nitpick, not a real maintenance hazard.

- **`src/Agent/AgentErrors.php`** — AgentErrors.classify hardcodes both providers' error.type vocabularies in one switch
  
  _Причина отклонения:_ The quoted code exists verbatim at lines 54-57. However the concern is an over-application of OCP. This class is explicitly designed as a provider-neutral normalizer (see docstring lines 19-23) whose whole job is to centralize the mapping from provider wire vocabularies to typed exceptions. A single, flat, easily-extended switch/match table is the idiomatic and arguably correct design for this; adding a provider means adding a one-line case, not a fragile change. A per-provider strategy abstraction here would be premature indirection for a tiny stable vocabulary. A careful senior reviewer would not flag this — it is a nitpick, not a defect.

- **`src/Agent/DefaultTurnLoop.php`** — DefaultTurnLoop depends on the concrete Tracer, not an interface
  
  _Причина отклонения:_ Quote exists at line 63. It is true Tracer is concrete while peers use interfaces. But the tracer is an optional, nullable cross-cutting recorder invoked only via null-safe calls (?->); coupling to a concrete recorder (with null acting as no-op) is idiomatic and trivially testable by passing null. A careful senior would not genuinely flag this as a defect — it is a low-confidence design opinion.

- **`src/Agent/BackoffAgentRetryPolicy.php`** — Retry policy's attempt contract is undocumented and breaks under strict_types for attempt < 1
  
  _Причина отклонения:_ The quoted code exists verbatim at lines 41-43. The strict_types TypeError chain (2**-1 -> float delay -> intdiv(float) / returning float to ?int) is technically correct, but it is unreachable: the only caller, AbstractAgent::send, starts `for ($attempt = 1; ; $attempt++)`, so attempt is always >= 1. The implicit 1-based contract is honored everywhere it is used. The undocumented 0-vs-1 contract on the interface is a minor doc nit at most, not a genuine bug a senior reviewer would block on. Theoretical/defensive concern, false positive as a defect.

- **`src/Agent/Dialogue.php`** — Dialogue shares a mutable $transcript by reference across two coroutines
  
  _Причина отклонения:_ Evidence is accurate (the `use (&$transcript)` closure with both spawns appending exists at lines 44-53). But PHP Async coroutines are cooperative/single-threaded, so there is no data race; the append happens synchronously before each send, and the size-1 channel handshake serializes turns by design — exactly what the class docblock describes. Collecting results into a shared captured list is idiomatic here, and the 'a future refactor might corrupt ordering' concern is speculative. Not something a careful senior reviewer would flag as a real issue.

- **`src/Chat/ConsoleConversation.php`** — ConsoleConversation is dead code — a divergent parallel implementation never instantiated
  
  _Причина отклонения:_ The quoted class and resource constructor exist, and ConsoleChat::accept() does unconditionally return AsyncConsoleConversation, so ConsoleConversation is unused in production. But the finding's key claim — 'referenced only by its own definition' / 'a maintenance trap nobody exercises' — is false: tests/Chat/ConsoleChatTest.php instantiates it twice (lines 20, 32) and exercises receive(), blank-line skipping, EOF, and send() via the resource constructor. So it is not dead and the drift/maintenance-trap risk is mitigated by dedicated tests. Keeping a simple synchronous reference implementation is defensible. The grep was mis-scoped to src/ only, undermining the finding; at most a low-severity nitpick, not a medium smell.

- **`src/Chat/AsyncConsoleConversation.php`** — Windows handle magic constant documented on the wrong line
  
  _Причина отклонения:_ The text exists exactly as quoted, but this is a pure nitpick. The comment is a trailing annotation on the same statement that uses the magic value and is clear enough in context; a senior reviewer would not flag comment placement of a self-evident constant. False positive as an actionable finding.

- **`src/Chat/Status.php`** — Status reaches into a concrete block type — leaky abstraction / OCP
  
  _Причина отклонения:_ The quoted instanceof exists exactly. But this is an over-application of OCP to small, idiomatic code. The strip is intentional and tightly coupled to the spin() coroutine which prepends its own animated frame (AsyncConsoleConversation:466-468) — without stripping you'd get a double spinner. There is exactly one animated/decorative block type in the whole system, and a single contained instanceof in a tiny rendering helper is reasonable. A careful senior reviewer would not genuinely flag this, and 'medium' is overblown.

- **`src/Chat/ConversationInterface.php`** — close() lifecycle is absent from ConversationInterface; cleanup relies on __destruct/GC
  
  _Причина отклонения:_ Evidence confirmed: the interface ends at updateStatus() with no close(), and AsyncConsoleConversation::close() (cancel coroutines, restore_error_handler, restoreTerminal) is reached only from receive() EOF (line 121) and __destruct (line 267); Session never calls it externally. However the central concern is overstated and largely a nitpick. The Session loop exits only when receive() returns null, and on that EOF path close() has already been invoked deterministically, so normal teardown is not GC-dependent. __destruct provides a guaranteed shutdown-time backstop for abnormal exits, and restoreTerminal() merely resets the scroll region/color rather than leaving the tty in raw mode, so there is no realistic 'left-over altered terminal'. Adding close() to the shared interface would also impose meaningless noise on Telegram/Console implementations that need no teardown. The internal, EOF-triggered close() with a __destruct guard is idiomatic, not a genuine defect a senior reviewer would flag.

- **`src/Chat/TelegramClient.php`** — allowed_updates passed as a hand-built JSON string literal
  
  _Причина отклонения:_ Quote exists at line 38. But this is idiomatic: Telegram's getUpdates expects allowed_updates as a JSON-serialized array even inside the form/query encoding, so it cannot simply be a PHP array passed to http_build_query — it must be a JSON string. Writing the static 2-element literal vs json_encode(['message','callback_query']) is functionally identical and extremely common for Telegram clients. The 'brittle/easy to desync' framing is overstated for a constant string. A nitpick, not something a careful senior reviewer would genuinely flag.

- **`src/Chat/TelegramChat.php`** — Authorized conversations are never evicted — unbounded growth in a forever-running bot
  
  _Причина отклонения:_ The code quote at lines 104-110 is accurate. But the leak framing is wrong: line 92's isAllowed() gate drops every unauthorized sender before this code runs, so $conversations only ever holds entries for authorized users — bounded by the allowlist (typically the owner / a tiny set), not 'the number of distinct users who ever messaged the bot.' That makes it a small, bounded set rather than an unbounded leak. The finding also misattributes a 'A chat never closes' note to the class docblock, which does not appear in this file. A careful senior reviewer would not flag this as a memory leak given the authorization gating; at most it is a minor, theoretical concern if a large allowlist were used.

- **`src/Tool/BashTool.php`** — bash description promises an exit code it omits on success
  
  _Причина отклонения:_ Evidence at line 29 matches; handle() only prefixes [exit N] when $exit !== 0 (lines 87-91). However omitting the exit code on success is a defensible, idiomatic choice — a successful command implies exit 0, and surfacing '[exit 0]' on every call would be noise. The mismatch between docstring and behavior is trivial and unlikely to genuinely mislead the model's success/failure reasoning. This is a nitpick, not something a careful senior reviewer would flag as a defect; recalibrated to low and isReal=false.

- **`src/Tool/PhpEvalTool.php`** — php_eval claims a 'single expression' it never enforces
  
  _Причина отклонения:_ The quote exists verbatim at line 51. However the concern is not a genuine vulnerability: the class is explicitly documented as 'Runs arbitrary PHP', is marked Risk::Dangerous, and is gated by the permission layer — multi-statement execution adds no new capability beyond the tool's stated purpose. The 'single expression' text is best-effort convenience framing, not a security boundary. The cited exploit is also technically incorrect: in eval'd code `return 1; system("x");` returns at the first statement, so the trailing call is unreachable; any genuinely runnable second call (e.g. `return system("x")`) is already a single-expression form. This is at most a minor doc/behavior wording mismatch, not something a senior reviewer would flag as a real issue.

- **`src/Tool/ToolCall.php`** — ToolCall chatId uses a 0 sentinel for 'no chat'
  
  _Причина отклонения:_ The quoted code exists exactly. However, a default of 0 for an integer routing id is idiomatic PHP and a benign convention, not a genuine defect: Telegram chat ids are positive, so 0 is a safe non-colliding sentinel, and the class docblock already explains chatId is for routing. Downstream approval routing treating 0 as 'no chat' is a normal optional-context pattern. This is a nitpick a senior reviewer would not meaningfully flag; no bug or risk follows from it.

- **`src/Tool/ScheduleTool.php`** — Hardcoded emoji prefix buried in ScheduleTool logic
  
  _Причина отклонения:_ The quoted line `$deliver('⏰ ' . $message);` exists verbatim at line 76. However, this is a trivial cosmetic nitpick, not something a senior reviewer would genuinely flag. The emoji is a small, self-explanatory UX affordance marking a reminder; it is not an 'unexplained magic string' since its meaning is obvious. The tool already owns the semantics of being a reminder, so prefixing the delivered text is reasonable. Extracting it to a constant or pushing it into the presentation layer would be over-engineering for a one-shot in-memory reminder helper. False positive / idiomatic.

- **`src/Workflow/Environment.php`** — Environment is a mixed-bag service locator with a second, unrelated responsibility (executor factory)
  
  _Причина отклонения:_ Evidence confirmed: find(): mixed plus instanceof-guarded typed finders and executor() building a ChainExecutor all exist. But this is a dogmatic SOLID critique of code that is deliberately and clearly documented as a scoped key->value environment with a parent-chain (the class docblock explicitly explains why keys are strings, why the typed finders narrow the mixed bag, and why executor() is built 'from THIS scope' so visibility and execution cannot disagree). The 'second responsibility' is a cohesive 10-line factory bound to the very scope/registry it reads — placing it here is the documented design rationale, not an accidental mixed bag. A careful senior reviewer would recognize this as an intentional, well-justified scope object rather than flag it as a defect; the service-locator label is a misreading of idiomatic, purpose-built code. Severity low.

- **`src/Workflow/WorkflowAbstract.php`** — WorkflowAbstract is an execution engine masquerading as a 'helper'
  
  _Причина отклонения:_ Quoted doc text exists, but this is a misreading. The docblock is explicitly self-aware: it states the base does not write the step's WORK (the hand-authored body) while immediately carving out 'A critic, though, IS machinery here' and describing the snapshot/skip durability. The 'helper not engine' framing is about the step body being hand-written, which is accurate. Calling it 'masquerading' overstates a nuanced, internally-consistent doc. False positive.

- **`src/Workflow/WorkflowValidator.php`** — Validator mixes tokenizer-based checks with regex/substring parsing
  
  _Причина отклонения:_ The quoted regex (lines 79-80) and the mixed-strategy observation are factually accurate. But the 'security gatekeeper correctness hazard' framing is overstated: all security-relevant checks (eval/shell/fs/network/dynamic-call) are tokenizer-based and robust. The two regex methods are auxiliary convention/structure checks with benign, fail-safe failure modes (a #[Step] in a comment causes a harmless false rejection; the class/namespace existence checks being lenient grants no capability the tokenizer hasn't already blocked). Regex over already-syntactically-validated source is an idiomatic, pragmatic PHP choice. A senior reviewer might note 'you could reuse the tokens,' but this is a low-severity stylistic nit, not the medium correctness/security hazard claimed.

- **`src/Workflow/WorkflowStateStore.php`** — WorkflowStateStore mixes snapshot persistence with leaf-call id generation (ISP/SRP)
  
  _Причина отклонения:_ Evidence is accurate (save/load + nextId, docblock acknowledges dual role, SqliteStateStore uses a state_seq table). But this is a documented, deliberate design: every run has exactly one store and needs both ids and durability, so they are co-located on purpose. Splitting out a one-method id interface directly contradicts the user's recorded working-style preference against one-method/premature interface proliferation. Not something a senior reviewer aware of this project would genuinely flag; an idiomatic, intentional cohesion choice.

- **`src/Workflow/GenerateIssueWorkflow.php`** — "tool() no longer throws" comments document history rather than current behavior
  
  _Причина отклонения:_ The quoted comment exists verbatim (lines 150-151, mirrored in SuperviseWorkflow:40). But this is a pure comment-wording nitpick: the comment usefully explains WHY there is no try/catch and states the current contract (success contains 'saved as', else the complaint). A careful senior reviewer would not genuinely flag this; rejected as a nitpick.

- **`src/Workflow/SqliteStateStore.php`** — SqliteStateStore runs schema DDL (CREATE TABLE) as a constructor side effect
  
  _Причина отклонения:_ The quoted code exists exactly. However, this is an idiomatic, deliberately-documented pattern for a lightweight production store in a CLI tool: the class docblock explicitly states this is the production store that auto-bootstraps schema, and the InMemory store is the cheap test drop-in, so the 'cannot build cheaply in tests' worry does not bite. CREATE TABLE IF NOT EXISTS is idempotent and cheap, and forcing ERRMODE_EXCEPTION on the injected PDO is a defensive normalization, not a surprising mutation of caller intent. A careful senior reviewer would accept this as pragmatic rather than flag it; it is a stylistic preference (migrations vs. self-bootstrap), not a defect. Recalibrated down from medium.

- **`src/Workflow/WorkflowStore.php`** — Boolean `$shared` flag parameter threaded through WorkflowStore's public API
  
  _Причина отклонения:_ The quoted signatures and ternaries exist verbatim. But the class is documented as having exactly Two scopes (common vs session); a defaulted boolean for a fixed binary distinction is idiomatic, the class is small and call sites are clear, and the 'third scope' concern is speculative YAGNI. A senior reviewer would not genuinely flag this — it is a nitpick on idiomatic code.

- **`src/Exec/TimeoutMiddleware.php`** — Timeout class-equality check cannot distinguish a /stop cancellation from a timeout
  
  _Причина отклонения:_ Misreads TrueAsync semantics. The stubs (vendor/true-async/ide-helper/src/Async) show OperationCanceledException is thrown specifically when an await's cancellation TOKEN fires (the `timeout(...)` here), and its docstring states its purpose is exactly to let callers distinguish token-triggered cancellation. An external `/stop` (Session.php:138 `currentTurn?->cancel()` with no reason) cancels the coroutine itself, injecting the base `Async\AsyncCancellation`, not OperationCanceledException. Since OperationCanceledException extends AsyncCancellation, the exact-class check `$e::class !== OperationCanceledException::class` is True for the base AsyncCancellation, so `/stop` propagates via `throw $e` and only the timeout becomes a 'timed out' result. The code matches its comment; the two causes ARE separable by class, contradicting the finding's premise.

- **`src/Exec/PermissionMiddleware.php`** — remember() returns true as a side-channel for the match arm
  
  _Причина отклонения:_ The quoted code exists verbatim (lines 60-72). However this is idiomatic, intentional PHP: the method persists the 'always' rule and returns the approval result in one concise, documented step (see comment line 53). The return can only be true because an Always choice always approves the current call; the store?->allowTool null-safe path correctly approves-this-call-but-skip-persistence when no store is configured, which is the desired semantics, not a silent bug. A careful senior reviewer would treat this as fine, not flag it.

- **`src/Cli/Cli.php`** — Cli::makeAgent leaks null for unwired agents; both call sites duplicate the null-check and error message
  
  _Причина отклонения:_ Evidence is accurate: match() returns null in default, and both SessionMode:65-66 and WorkflowMode:185-186 guard with slightly different messages. But the nullable-return-plus-guard is the consistent house style across this codebase (SessionMode also does it for $chat/channel, WorkflowMode uses === null guards everywhere). For a CLI front door, exit-code + STDERR is idiomatic and arguably clearer than threading a ClawException for a config-not-wired case. The 'duplication' is two short, intentionally distinct (one prefixes 'claw run:') guards — a low-value nitpick, not a genuine finding.

- **`src/Project/ProjectStore.php`** — ProjectStore mixes registry, connection handle, and three repositories
  
  _Причина отклонения:_ The quoted methods all exist. But this is a borderline architectural-preference observation at a small scale: the class is cohesive around 'one project's persisted state', the docblock deliberately justifies the single shared connection, and it is ~290 lines. A senior reviewer might note 'split if it grows' but would not block. It is also fully redundant with [2], which raises the identical concern — over-flagging. Downgraded to low and treated as a nitpick.

- **`src/Project/ProjectStore.php`** — SQLite open / ERRMODE / CREATE-TABLE boilerplate scattered across five store classes
  
  _Причина отклонения:_ The quoted open() body exists in ProjectStore. However the cross-file claim is overstated: only ProjectStore and SessionStore actually `new \PDO('sqlite:...')`; SqliteStateStore, TraceStore and TraceReader receive an injected \PDO and only re-set ERRMODE. The genuinely shared 'open a sqlite db' ritual is two lines in two places, and the rest is per-store schema DDL that cannot be unified. Minimal, idiomatic duplication; the finding mischaracterizes the scope.

- **`src/Project/ProjectStore.php`** — ProjectStore conflates a connection/registry factory with issue+run repositories
  
  _Причина отклонения:_ Evidence (the listed methods) exists, but this is a near-verbatim restatement of [0] — same dimension, same concern, same class. Redundant findings are themselves a sign of over-flagging. Same merits/verdict as [0]: idiomatic, cohesive handle pattern at small scale, justified by the docblock. Not a genuine flag.

- **`src/Project/ProjectStore.php`** — Inconsistent table naming: singular 'project' vs plural 'issues'/'runs'
  
  _Причина отклонения:_ All three CREATE TABLE statements exist as quoted. But the naming is defensible, not a bug: the `project` table holds exactly one row (queried `... project LIMIT 1`) — a singleton describing this db's own project — while `issues` and `runs` are collections. Singular-for-singleton vs plural-for-collection is a deliberate, sensible convention, not an inconsistency a senior reviewer would flag.

- **`src/Session.php`** — Quota/rate-limit message formatting lives in Session (presentation in orchestrator)
  
  _Причина отклонения:_ The quoted code exists (183-199). However this is a subjective altitude opinion, not a genuine defect, and it is internally inconsistent: Session already composes many user-facing strings inline ('Stopped.', 'Error: '.$e->getMessage(), 'The conversation got too long...'), so these two helpers are consistent with the file's established approach, not feature envy. Flagging only these two while the surrounding send() calls do the same is a nitpick a careful senior reviewer would not act on.

- **`src/Config.php`** — Five budget scalars threaded as a data clump through Config and callers
  
  _Причина отклонения:_ The quoted fields exist exactly at lines 62-66. But this is a stylistic nitpick, not something a senior reviewer would genuinely flag. Config is an immutable DTO whose entire job is to expose flat, individually-defaulted primitives parsed from discrete env vars (CLAW_BUDGET_TOKENS, CLAW_BUDGET_SECONDS, etc.); holding them as readonly promoted properties is idiomatic PHP. Extracting a Budget value object is a matter of taste and would add indirection/a class for marginal benefit — exactly the kind of premature-class proliferation the project's own working-style guidance discourages. The 'mis-pair' risk is hypothetical since each field is named and set by keyword argument.

- **`src/Exceptions/RateLimitException.php`** — Identical retryAfterMs constructor copy-pasted across two exceptions
  
  _Причина отклонения:_ The quote is accurate — both constructors are byte-for-byte identical (promoted readonly int retryAfterMs = 0, parent::__construct($message, 0, $previous)). But this is trivial exception-class boilerplate, not load-bearing logic. The two classes are deliberately separate types with different semantics (RateLimitException implements TransientErrorInterface and is retryable; QuotaExceededException is explicitly non-transient), so they cannot collapse into one. De-duplicating would require introducing an intermediate abstract base class purely to host a 6-line constructor — exactly the premature class proliferation the project's own working-style memory warns against. The 'silently diverge' risk is negligible for a trivial parent-call constructor. A careful senior reviewer would treat this as an acceptable idiomatic pattern, not a defect; medium severity is overstated.

- **`src/Exceptions/QuotaExceededException.php`** — Field named retryAfterMs on a non-retryable error contradicts its own docblock
  
  _Причина отклонения:_ The quoted docblock and field both exist exactly as claimed. However the concern is a nitpick/misreading: `retryAfterMs` mirrors the standard HTTP `Retry-After` header, which carries the reset/availability time on 402 quota responses just as it does on 429. The docblock reconciles this — don't retry *soon*, but the value tells when the quota resets — so the name and docs are consistent, not contradictory. The name also plausibly matches a sibling rate-limit exception for uniform handling. The docblock already disambiguates, defusing the speculative misuse scenario. Not something a careful senior would genuinely flag.

- **`src/Exceptions/WorkflowFinished.php`** — Exception type used as non-error control flow
  
  _Причина отклонения:_ The docblock quote and the `extends ClawException` are real. However this is a deliberate, thoroughly documented design: throwing to unwind a deeply-nested turn loop for an expected early-exit is an idiomatic pattern (StopIteration-style), and the docblock explicitly explains the executor's ToolException-only conversion contract. The speculative 'a future catch-all on ClawException would break it' is hypothetical. A careful senior reviewer would not genuinely require a change here; it is an accepted intentional pattern, not a defect.

- **`src/Exceptions/WorkflowFinished.php`** — Exception used as control-flow signal (WorkflowFinished)
  
  _Причина отклонения:_ Evidence is accurate, but this is the same observation as [0] with inflated medium severity. The pattern is intentional and fully documented (early-exit via exception to unwind the turn loop), and is idiomatic rather than a bug. Medium is unjustified; downgraded to low and not something a careful reviewer would require changing.

- **`src/Tool/HandoffTool.php`** — Tool layer depends on concrete Trace classes with no interface seam
  
  _Причина отклонения:_ The quoted code exists, but the concern is a false positive. Injecting concrete collaborators is the consistent idiom across the whole Tool layer (WriteFileTool->Workspace, BashTool->string cwd, ScheduleTool->Closure, RecallTool->TraceReader). Tracer is deliberately 'the one recorder for a run', a final concrete type that already exposes seams where they matter (TraceSinkInterface sinks). The project convention only mandates the *Interface suffix WHEN an interface exists; it does not require an interface per injected dependency, and the user's working-style memory explicitly warns against premature one-method interfaces. The DIP/ISP framing is architectural purism that contradicts the codebase's established pattern, so a careful senior reviewer would not flag it.

- **`src/Trace/TraceFormat.php`** — Docblocks reference removed classes/methods (Summarize, brief, old summary())
  
  _Причина отклонения:_ The quoted text exists verbatim and grep confirms those symbols are gone (remaining ->summary() hits are unrelated $call->summary()). But the comments' core purpose is to explain the current design rationale (one render point so live/replayed never drift), which is accurate and valuable; the historical parentheticals are idiomatic, harmless seasoning. A senior reviewer would not flag this — it's a nitpick, not a defect.
