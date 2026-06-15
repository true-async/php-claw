# php-claw

[![CI](https://github.com/true-async/php-claw/actions/workflows/ci.yml/badge.svg)](https://github.com/true-async/php-claw/actions/workflows/ci.yml)
[![Built on PHP TrueAsync](https://img.shields.io/badge/built%20on-PHP%20TrueAsync-8892BF?logo=php&logoColor=white)](https://github.com/true-async/)
[![Tested with Testo](https://img.shields.io/badge/tested%20with-Testo-2ea44f)](https://github.com/php-testo/testo)

A minimal personal AI agent in the spirit of [OpenClaw](https://github.com/openclaw/openclaw) /
[NanoClaw](https://github.com/nanocoai/nanoclaw), built **entirely on PHP
[TrueAsync](https://github.com/true-async/)**. You chat with it; it runs a Claude (or
DeepSeek / any OpenAI-compatible) agent loop that can take real actions on the host (run
shell commands, read and write files) and replies in the same conversation. "Claude Code
whose terminal is your chat."

> **This is a learning project.** Its purpose is to teach, and to show off, asynchronous
> PHP built into the engine (TrueAsync): a whole agent (concurrent HTTP, subprocesses, timers)
> in one process, with plain-looking code that never blocks. It is intentionally small and
> readable, not a production product. Not affiliated with Anthropic, OpenClaw or NanoClaw.

The design is documented step by step in [`ARCHITECTURE.md`](ARCHITECTURE.md) and, as a
narrative tutorial, in [`tutorial/ru/`](tutorial/ru) (Russian).

## How it works

The core is the **agentic loop (ReAct)**: a user message goes to the agent; the agent either
replies with text or asks to run tools; tools run, their results go back to the agent, and it
continues until a final answer. Everything the agent does is I/O-bound (HTTP to the model,
HTTP to the chat, `bash` subprocesses), so under TrueAsync it all `await`s and costs no CPU
while suspended: hundreds of conversations run concurrently in a single thread, no callbacks.

Layers: **Chat** (the messenger; a console gateway today), **Agent** (decides the next move;
pluggable backend with cause-aware retries), **Tool** (runs real actions, gated later by a
security layer), and the **Session** that glues them with the loop.

## Requirements

- A PHP **TrueAsync** build (PHP 8.6+) with the `true_async`, `curl` and `pdo` extensions.
- Composer (for the dev tooling and autoloader).

## Quick start (console)

```bash
composer install
cp .env.example .env      # then set an API key (e.g. DeepSeek or Anthropic) and CLAW_ALLOWED_CHATS
php bin/claw              # type a message, Ctrl+D to exit
```

`.env` configures the backend (`CLAW_AGENT` = `claude` | `openai-compatible` | `gemini`), the
model, the API key, and the sandbox working directory. Secrets stay in memory and are never
exposed to the `bash` tool's environment.

## Run with Docker

The image builds on the official [TrueAsync PHP image](https://hub.docker.com/r/trueasync/php-true-async),
so you do not need a local TrueAsync build.

```bash
docker build -t php-claw .
docker run --rm -it \
  -v "$PWD/.env:/app/.env" \
  -v "$PWD/workspace:/app/workspace" \
  php-claw
```

The `.env` holds your secrets and is never baked into the image, so mount it at run time.
Mounting `workspace` is optional; it lets the file and `bash` tools act on a directory you
can see on the host.

## Development

Run everything under the TrueAsync PHP binary:

```bash
php vendor/bin/testo            # tests (Testo)
php vendor/bin/phpstan analyse  # static analysis (level 8)
php vendor/bin/php-cs-fixer fix # coding style
```

Composer shortcuts: `composer test`, `composer analyse`, `composer cs`, `composer cs-fix`,
`composer qa` (all three).

## Status

Console + Telegram agent that runs end to end: Config, async HTTP with cause-aware retry,
Claude & DeepSeek backends, the tool set (`bash`, `read_file`, `write_file`, `list_files`,
`date`, `php_eval`, `schedule`), the session loop, a tool-execution middleware chain
(permission gatekeeper with confirm + persisted "always" rules, audit log, per-tool
timeout), per-conversation SQLite persistence (history survives restarts), `/stop` to cancel
a running turn, and a Telegram channel (chat-id allowlist, long-poll, "typing…" indicator,
inline approval buttons). Next: skills, and OS-level sandboxing of the `bash` tool.
