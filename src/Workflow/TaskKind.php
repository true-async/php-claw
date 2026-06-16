<?php

declare(strict_types=1);

namespace Claw\Workflow;

/** What a task inside a step actually is. Tasks may run in parallel. */
enum TaskKind
{
    case Prompt;       // a prompt to an agent (LLM)
    case Tool;         // a tool call
    case Subworkflow;  // a call into another workflow
    case Code;         // plain deterministic PHP, no LLM
}
