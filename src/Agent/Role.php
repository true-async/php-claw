<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Conversation role. Tool results are carried inside a User message's content,
 * so there is no separate "tool" role on the wire.
 */
enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
