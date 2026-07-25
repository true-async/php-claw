<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;

/**
 * The gatekeeper that reads generated workflow code before it is ever required.
 *
 * It parses the source with the tokenizer (which also rejects syntax errors) and
 * refuses anything that could reach outside the sanctioned palette: eval, include,
 * the backtick shell operator, dynamic `$fn(...)` calls, and a blocklist of
 * dangerous builtins (shell, filesystem, network, env). A workflow is expected to
 * touch the world only through the helpers its base ({@see WorkflowAbstract}) provides
 * ($this->tool() / $this->step()), never directly.
 *
 * This is a deliberately conservative first line of defence; the optional
 * security agent can review on top of it.
 */
final class WorkflowValidator
{
    /** @var list<string> Builtins a workflow must never call directly (lower-cased). */
    private const array FORBIDDEN_FUNCTIONS = [
        // code execution
        'eval', 'assert', 'create_function', 'call_user_func', 'call_user_func_array',
        // shell
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        // filesystem
        'fopen', 'fwrite', 'fputs', 'fread', 'file', 'readfile', 'file_get_contents',
        'file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod',
        'chown', 'symlink', 'link', 'scandir', 'glob', 'opendir',
        // network
        'fsockopen', 'stream_socket_client', 'curl_init', 'curl_exec',
        // process / environment / loading
        'putenv', 'getenv', 'dl', 'ini_set', 'set_error_handler', 'register_shutdown_function',
    ];

    /**
     * @param string|null $expectedClass the fully-qualified class the code must declare,
     *                                    e.g. ClawWorkflow\Common\ProjectStats
     *
     * @throws WorkflowException on a syntax error or a forbidden construct
     */
    public function validate(string $code, ?string $expectedClass = null): void
    {
        try {
            $tokens = \PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new WorkflowException('workflow has a PHP syntax error: ' . $e->getMessage());
        }

        $significant = array_values(array_filter($tokens, static fn (\PhpToken $t): bool => !$t->isIgnorable()));

        foreach ($significant as $i => $token) {
            $this->assertNotForbiddenConstruct($token);
            $this->assertNotDynamicCall($token, $significant[$i + 1] ?? null);
            $this->assertNotForbiddenFunction($token, $significant[$i - 1] ?? null, $significant[$i + 1] ?? null);
        }

        $this->assertStepsAreProtected($code);
        $this->assertStrictTypes($code);
        $this->assertExtendsWorkflowAbstract($code);
        $this->assertHasAtLeastOneStep($code);
        $this->assertCriticNamesResolve($code);
        $this->assertRulesHaveConsumers($code);

        if ($expectedClass !== null) {
            $this->assertDeclaresExpectedClass($code, $expectedClass);
            $this->assertImplementsName($code);
        }
    }

    /**
     * The generator's prompt heads its requirements with "the code is validated before it is saved, and
     * rejected if any are missed". The four checks below are the ones that sentence was promising and
     * nothing performed — a header that trains both the model and the maintainer to trust a gate that is
     * mostly absent. Each is mechanical, so it belongs here rather than in prose a model may or may not
     * follow, and a rejection here comes back to the generating workflow's save step, which re-drafts the
     * source once (via back()) before the run gives up on it.
     *
     * `final` is deliberately NOT among them. The prompt asks for it and it is good practice, but a
     * non-final workflow runs correctly, and a gate that fails a working class costs a revision round to
     * buy nothing.
     *
     * @throws WorkflowException
     */
    private function assertStrictTypes(string $code): void
    {
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $code) !== 1) {
            throw new WorkflowException('workflow must open with `declare(strict_types=1);`');
        }
    }

    /**
     * Without the base class none of the machinery exists — `ai()`, `tool()`, `step()`, the snapshot, the
     * critic. A class that omits it parses cleanly, saves, loads, and then fatals on its first step call.
     *
     * @throws WorkflowException
     */
    private function assertExtendsWorkflowAbstract(string $code): void
    {
        if (preg_match('/\bextends\s+(?:\\\\?Claw\\\\Workflow\\\\)?WorkflowAbstract\b/', $code) !== 1) {
            throw new WorkflowException('workflow must extend WorkflowAbstract');
        }
    }

    /**
     * A workflow that does nothing is not a workflow: the default run() drives step methods, finds none,
     * and the run "succeeds" having done nothing — which the run path records as a finished ticket.
     *
     * "Does nothing" means no `#[Step]` AND no run() of its own. Overriding run() and orchestrating by
     * hand is a first-class shape here ({@see WorkflowAbstract}'s own docblock offers it for flow that
     * loops or branches), so demanding a `#[Step]` outright would reject working code.
     *
     * @throws WorkflowException
     */
    private function assertHasAtLeastOneStep(string $code): void
    {
        if (preg_match('/#\[\s*\\\\?(?:[\w\\\\]+\\\\)?Step(?:AI)?\b/', $code) === 1) {
            return;
        }

        if (preg_match('/\bfunction\s+run\s*\(/', $code) === 1) {
            return;
        }

        throw new WorkflowException('workflow must declare at least one #[Step] or #[StepAI] method, or drive the work from its own run()');
    }

    /**
     * Every `#[Step(critic: 'x')]` must have an 'x' key in criticRules().
     *
     * This was checked only at RUN time, by a LogicException in {@see WorkflowAbstract::criticRubric()} —
     * which fires mid-run on the real project, after a human approved the solver, discarding everything
     * done before the offending step. The same thing is decidable by reading the source, so it is decided
     * here, where the cost is one revision round.
     *
     * Best-effort by design: the scan for rule keys starts at criticRules() and does not track brace
     * depth, so a quoted key in a later method could satisfy a name it has nothing to do with. That errs
     * towards ACCEPTING, which is the right direction for a save-time gate whose runtime counterpart
     * stays in place — and it must stay, because a library workflow is loaded by the shelf's autoloader
     * and never passes through here at all.
     *
     * @throws WorkflowException
     */
    private function assertCriticNamesResolve(string $code): void
    {
        if (preg_match_all('/#\[\s*\\\\?(?:[\w\\\\]+\\\\)?Step(?:AI)?\b[^\]]*\bcritic\s*:\s*[\'"]([^\'"]+)[\'"]/', $code, $used) === false) {
            return;
        }

        if ($used[1] === []) {
            return;
        }

        $start = strpos($code, 'function criticRules');

        if ($start === false) {
            throw new WorkflowException(
                "workflow uses critic '{$used[1][0]}' but declares no criticRules() — a critic name with no rules stops the run",
            );
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>/', substr($code, $start), $declared);

        foreach ($used[1] as $name) {
            if (!\in_array($name, $declared[1], true)) {
                throw new WorkflowException("workflow uses critic '{$name}' but criticRules() has no rules for it");
            }
        }
    }

    /**
     * The mirror image of {@see assertCriticNamesResolve}: every criticRules() entry must be USED by
     * some `#[Step(critic: 'x')]`.
     *
     * Orphaned rules are how a generated solver LOOKS reviewed while nothing reviews it: the first
     * live run under the cycle recipe produced exactly that — rules written for all three steps, zero
     * `critic:` markers, so no review ran and no evidence was recorded. Whoever writes a rule means a
     * gate; a rule nobody consumes is a review that silently never happens, and that is decidable
     * right here at save time.
     *
     * @throws WorkflowException
     */
    private function assertRulesHaveConsumers(string $code): void
    {
        $start = strpos($code, 'function criticRules');

        if ($start === false) {
            return;
        }

        // Bound the key scan to this method's body — cut at the next `function` — so a later
        // method's quoted keys cannot read as rules and manufacture a false orphan.
        $end = strpos($code, 'function ', $start + 1);
        $body = $end === false ? substr($code, $start) : substr($code, $start, $end - $start);
        preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>/', $body, $declared);

        preg_match_all('/#\[\s*\\\\?(?:[\w\\\\]+\\\\)?Step(?:AI)?\b[^\]]*\bcritic\s*:\s*[\'"]([^\'"]+)[\'"]/', $code, $used);

        foreach ($declared[1] as $name) {
            if (!\in_array($name, $used[1], true)) {
                throw new WorkflowException(
                    "criticRules() declares '{$name}' but no step is marked #[Step(critic: '{$name}')] — "
                    . 'a review you wrote that never runs; mark the step that produces what these rules judge, or drop the rule',
                );
            }
        }
    }

    /**
     * `WorkflowAbstract::name()` is abstract — a workflow that omits it is not "invalid code" to the
     * tokenizer, but it FATALS (uncatchable) the moment PHP loads the class, killing the run before any
     * step. Catch it here, at save, so the generator gets a clear error and can fix it instead.
     *
     * @throws WorkflowException
     */
    private function assertImplementsName(string $code): void
    {
        if (preg_match('/\bfunction\s+name\s*\(/', $code) !== 1) {
            throw new WorkflowException('workflow must implement `public function name(): string`');
        }
    }

    /**
     * A #[Step] method is framework-driven — the base run() calls it — not part of the workflow's
     * public surface, and it must be reachable from the base scope. So it must be declared `protected`:
     * `public` leaks it, `private` (or no modifier) is wrong and a private one cannot even be called
     * from the base. Reject anything else so the generator is forced to write steps correctly.
     *
     * @throws WorkflowException
     */
    private function assertStepsAreProtected(string $code): void
    {
        $pattern = '/#\[\s*\\\\?(?:[\w\\\\]+\\\\)?Step(?:AI)?\b[^\]]*\]\s*'
            . '((?:(?:public|private|protected|static|final|abstract)\s+)*)function\s+(\w+)/';

        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($matches as $match) {
            if (!preg_match('/\bprotected\b/', $match[1])) {
                $found = trim($match[1]) === '' ? 'public (no modifier)' : trim($match[1]);

                throw new WorkflowException("step method '{$match[2]}' must be declared protected, found {$found}");
            }
        }
    }

    /** @throws WorkflowException */
    private function assertNotForbiddenConstruct(\PhpToken $token): void
    {
        if ($token->is([T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
            throw new WorkflowException('workflow uses a forbidden construct: ' . trim($token->text));
        }

        if ($token->text === '`') {
            throw new WorkflowException('workflow uses the backtick shell operator');
        }
    }

    /** @throws WorkflowException */
    private function assertNotDynamicCall(\PhpToken $token, ?\PhpToken $next): void
    {
        // $fn(...) hides a call behind a variable and would bypass the blocklist.
        if ($token->is(T_VARIABLE) && $next !== null && $next->text === '(') {
            throw new WorkflowException('workflow uses a dynamic function call ($var(...))');
        }
    }

    /** @throws WorkflowException */
    private function assertNotForbiddenFunction(\PhpToken $token, ?\PhpToken $prev, ?\PhpToken $next): void
    {
        if (!$token->is(T_STRING) || !\in_array(strtolower($token->text), self::FORBIDDEN_FUNCTIONS, true)) {
            return;
        }

        $isCall = $next !== null && $next->text === '(';
        $isMemberOrDeclaration = $prev !== null && $prev->is([
            T_OBJECT_OPERATOR,           // $ctx->fopen() is a method, not the builtin
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,              // Foo::eval()
            T_FUNCTION,                  // function eval() {} declaration
            T_NEW,
            T_CONST,
        ]);

        if ($isCall && !$isMemberOrDeclaration) {
            throw new WorkflowException("workflow calls a forbidden function: {$token->text}");
        }
    }

    /** @throws WorkflowException */
    private function assertDeclaresExpectedClass(string $code, string $expectedClass): void
    {
        $pos = strrpos($expectedClass, '\\');
        $namespace = $pos === false ? '' : substr($expectedClass, 0, $pos);
        $class = $pos === false ? $expectedClass : substr($expectedClass, $pos + 1);

        if ($namespace !== '' && !str_contains($code, "namespace {$namespace};")) {
            throw new WorkflowException("workflow must declare namespace {$namespace}");
        }

        if (!preg_match('/\bclass\s+' . preg_quote($class, '/') . '\b/', $code)) {
            throw new WorkflowException("workflow must declare class {$class}");
        }
    }
}
