# Asynchrony

In the code at https://github.com/true-async/php-claw/releases/tag/sync there is no asynchrony at all.
For example, if the AI agent starts some long running Tool, then until it finishes the agent cannot do
anything, not even reply to the user. That is not quite what we want from our agent. We want the agent
to run several tools at once without blocking itself, and to keep talking to the user.

To do that we need to make the code **asynchronous**.
Right now the application's main loop looks like this:
```php
public function run(): void
{
    while (($text = $this->conversation->receive()) !== null) {
        // A failure ends this task, not the conversation. React by cause.
        try {
            $this->handle($text);
        } catch (ContextLengthException $e) {
            
        }
    }
}
```

And inside `handle()` there are two more loops: a small loop over all the Tools, and the big loop.
```php
$results = [];
foreach ($response->toolCalls as $call) {
    $results[] = $this->execute($call);
}
```

The simplest way to add asynchrony is to call the `run` method in a separate coroutine. Then the
application can handle many different chats.

You can also run `$this->execute($call)` in a coroutine:

```php
$results = [];
foreach ($response->toolCalls as $call) {
    $results[] = spawn($this->execute(...), $call);
}

$results = await_all($results);
```

Then each Tool becomes asynchronous, and if it launches something through `shell` or uses input/output
functions, the operations run concurrently and take less time in total.

Notice that we did not have to rewrite the Tool code. Only the way it is called and its result is
collected changed. This is the convenience of the transparent asynchrony model, where any function can
be called asynchronously at any moment.

The `spawn` API runs the `execute` method in a separate coroutine with the `$call` argument and returns
a coroutine object. The `await_all` API lets you wait for a list of coroutines. `await_all` returns the
matching array of results in the same order the coroutines had in the array.

## Asynchronous console

To keep adding asynchrony, we need something that shows the result visually.
Even if the whole application becomes 100% asynchronous, you will not see it if the console is
synchronous. So we need a completely different console mode, a special mode that lets us print text and
characters in different places on the screen. To show the input field, the history field, and the
status line separately.

For example, like this:
```bash
User: Hello, AI!
---------------------------------------
User: ...
---------------------------------------
Status: processing...
```

To get the effect of separate zone components (banner, status, input, token panel, history), we use
control sequences and the ordinary cooked mode of the terminal.

Control codes let us fully govern how the console behaves.

| Code                      | Action                                                                    |
|---------------------------|---------------------------------------------------------------------------|
| `\033[{r};{c}H`           | move the cursor to row `r`, column `c`                                    |
| `\033[2K` / `\033[2J`     | clear the line / the screen                                               |
| `\033[s` / `\033[u`       | save / restore the cursor position (write to the status, input untouched) |
| `\033[?25l` / `\033[?25h` | hide / show the cursor during a redraw                                    |
| `\033[…m`                 | colors (SGR)                                                              |

You do not need to enable the handling of these codes (VT mode) on purpose; in modern terminals it is
already active. What remains is to use `fwrite(STDOUT)` and `fgets(STDIN)` for reading and writing.

The resulting interface looks roughly like this:
![Example interface](img/console-async.jpg)

The synchronous nature of the interface stands out at once: the input field is blocked while the agent
processes a request. To solve this, we need to read the input stream independently of how the other
components are rendered. How do we achieve that?
We create a separate function, `readLoop`, whose job is to read the user's input.

```php
    private function readLoop(): void
    {
        while (true) {
            fwrite(STDOUT, "\033[{$this->inputRow};1H\033[2K");
            fflush(STDOUT);
            $line = fgets(STDIN);

            if ($line === false) {
                $this->eof = true;   // EOF (Ctrl-D / closed stdin)
                return;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Re-assert the scroll region + separators (the Enter newline may
            // nudge them), record the line, then re-home the cursor.

            fwrite(
                STDOUT,
                "\033[{$this->chatStart};{$this->chatRows}r"
                . "\033[{$this->sepTopRow};1H\033[2K" . self::C_SEP . $this->sep . self::C_RESET
                . "\033[{$this->sepBotRow};1H\033[2K" . self::C_SEP . $this->sep . self::C_RESET
                . "\033[{$this->inputRow};1H\033[2K"
            );
            
            $this->appendChat(self::C_USER . 'User: ' . self::C_RESET . $line . "\n");
            $this->inbox[] = $line;
        }
    }
```

So that this function does not block the application's main thread, we run it in a separate coroutine,
which we initialize in the console driver's constructor:

```php
$this->reader = spawn($this->readLoop(...));
```

The `readLoop` algorithm:
1. Sets the cursor position to the computed input row
2. Waits for input with `fgets(STDIN)`
3. Writes the result into the `inbox` array
4. Updates the interface, redrawing the separator lines and scrolling the chat.

Now the interface areas work independently of each other.
The effect is achieved because `TrueAsync` affects the whole input/output subsystem.
A call to `fgets(STDIN)` suspends the `readLoop` coroutine instead of blocking the entire PHP process.
When the user enters new data, the coroutine resumes.

Appending lines to the array is completely safe, because everything happens in a single process and no
data races occur.
```php
$this->inbox[] = $line;
```

Let us ask the agent to add window resize handling to `AsyncConsoleConversation`, plus a small
adaptation for `Windows`, and the console is ready!
