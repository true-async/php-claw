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

        if ($expectedClass !== null) {
            $this->assertDeclaresExpectedClass($code, $expectedClass);
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
        $pattern = '/#\[\s*\\\\?(?:[\w\\\\]+\\\\)?Step\b[^\]]*\]\s*'
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
