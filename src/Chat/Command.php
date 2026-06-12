<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * Worker-thread command protocol.
 *
 * These are the messages the conversation (main thread) sends to the rendering
 * worker over the output ThreadChannel; the worker dispatches on them in
 * {@see AsyncConsoleConversation::drawer()}.
 *
 * Only the backing string crosses the channel — channels carry scalars/arrays,
 * not enum instances (see ext/async thread_channel/010-data_types). Both sides
 * reference the cases (`Command::Send->value`) so the wire literal lives here
 * and nowhere else.
 *
 * @internal
 */
enum Command: string
{
    case Send   = 'send';
    case Status = 'status';
    case Clear  = 'clear';
    case Prompt = 'prompt';
    case Resize = 'resize';
}
