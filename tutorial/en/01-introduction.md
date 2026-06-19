I was thinking about which learning project to build for TrueAsync, and in the end I picked an OpenClaw analogue. Why this one? First, because it is a new area of programming (new for me as well). Usually we PHP programmers write back end code and rarely build console applications. Second, because the task is a perfect fit for showing the asynchrony built into the PHP core. And finally, because it is fun.

A note on how these articles are structured. They appear as the project grows, in the same order the code is designed. That means the first stage is not coding but studying what competitors did, the task itself, and the possible approaches.

What problem does OpenClaw solve?

A large language model in a browser tab can talk, but it cannot do anything with the things on your computer. It will not run a command on a server, fix a file, query a database, or write you a report. You are tied to someone else's web interface and someone else's cloud. OpenClaw is a personal AI agent that you run on your own hardware and talk to right from a messenger, for example Telegram. You write to it like a contractor, and it autonomously performs real actions: it runs shell commands, reads and edits files, goes online, schedules tasks, and sends the result back into the chat. In essence it is Claude Code with a messenger instead of a terminal.

What does the application's work look like?

The application receives text from the user and hands it to the AI agent. In response the agent can either return a text answer or ask to run tools. If the AI returned text, we simply forward it to the user, and that is all. If the AI requested tools (for example, running a shell command or reading a file), the application executes them, returns the result back to the agent, and the agent continues. The AI agent can again reply either with text or with a new tool request. This repeats until a final answer appears. This workflow is called the agentic loop.

Following from this, let us define three key layers of the application:

- Agent: the code related to the agent's work
- Tool: an instrument, the code that lets the agent perform real actions
- Chat: the layer that handles interaction between the agent and the human

The agent can use a Tool. The agent must know that the Tool exists and what capabilities it provides. The Tool, in turn, must not know about the agent.

Let us build the application architecture on these layers as the foundation.

```php
    // Main loop
    while (true) {
        // 1. Get text from the user
        $text = $this->chat->receive();
        
        // 2. Save it in the history
        $this->history[] = $text;

        // 3. The agent work loop
        while (true) {
            
            // 4. Send a request to the agent. Pass it the whole history!          
            $response = $this->agent->send(new AgentRequest(
                model: $this->model,
                messages: $this->history,
                system: $this->system,
                tools: $this->specs,
            ));

            $this->history[] = $response->content;

            // 5. If the agent sent a finished answer for the user,
            // send the answer and end the agent loop.
            if ($response->isCompleted()) {
                $this->chat->send($response->text ?? '');
                break;
            }

            // 6. Run the tools the agent requested and save their results in the history.
            $results = [];
            foreach ($response->toolCalls as $call) {
                $results[] = $this->execute($call);
            }

            array_push($this->history, ...$results);
        }               
    }
```

Notice that the agent always receives the full message history. The reason is that the modern agent API is stateless, yet it can cache the context and does not re-charge for identical strings sent recently. But sending the whole history is required!

We also send the agent information about the Tools, so the agent immediately knows which tools it can use.

We split the whole project into three folders, `Agent`, `Tool`, and `Chat`, matching the layers we identified. Each folder holds an interface and its implementation, plus extra DTO structures that let us store messages of different types.

`ContentBlockInterface` is needed to express different message types:
- Text
- ToolCall
- ToolResult

The Message container then ties a list of ContentBlockInterface to a specific sender (user, agent, system) and the time it was sent.

Since the `Chat` component may have several real chats (for example with `Telegram`, different users can open different chats), we separate out a `Conversation` component. `Conversation` is responsible for accepting new messages from the user and represents the direct link with the user, while `Chat` accepts new `Conversation` objects.

The last design question: create a class that encapsulates the application's main loop. This class logically connects all the others and represents one session between the user and the agent, bound by a single context. So we call it `Session`.

So, we have defined the application architecture by identifying the key layers and their interactions. We also add a simple HttpClient implementation that the agent uses for network access, and an OpenAIAgent implementation that uses the OpenAI API to generate answers.

Now, if we define an `env` file like this:
```php
CLAW_CHANNEL=console

CLAW_AGENT=openai-compatible
CLAW_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=sk-proj-***
CLAW_MODEL=gpt-4o-mini

CLAW_WORKSPACE=./workspace
CLAW_MAX_HISTORY=0
```

we can open the application with `php bin/claw.php` and start talking to the agent right in the console:

```php
User: Hi, what time is it?
Claw: Hi! I can't tell the exact time, since I have no access to the system clock. 
Claw: But you can find it out by running the `date` command in the terminal. I would be glad to help you with that!
```

We can see the agent has almost no tools. Let us add a few. One of them is the ability to call PHP functions. There is a small trick here: the agent already knows PHP functions, and it is very easy for us to give access to them through `eval`. This approach is not safe, but we use it for learning.

```php
User: Hi, what time is it?
Claw: Hi! It is 15:30 your time.
```

The whole application loop works correctly. We can see the AI interacting with tools. We have reached the goal. But wait, where is the asynchrony here?
