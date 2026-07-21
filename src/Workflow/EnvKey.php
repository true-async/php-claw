<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The KNOWN keys an {@see Environment} resolves — a catalog of well-known strings, not a
 * closed universe. The environment is keyed by plain strings (anything can be stored under
 * any key), and this backed enum just names the keys a run always has, so the common lookups
 * are typo-proof and discoverable while ad-hoc keys stay possible. `find()`/`set()` accept
 * either an EnvKey or a raw string; the enum's value IS the string key.
 */
enum EnvKey: string
{
    case Worker = 'worker';                  // AgentInterface — the model an ai() call uses
    case Registry = 'registry';              // Registry — the tool palette; a scope's executor is built from it
    case ModelId = 'modelId';                // string — the worker model's id
    case SystemPrompt = 'systemPrompt';      // string — the worker's system prompt
    case MaxHistory = 'maxHistory';          // int — soft cap on an ai() call's turn history
    case Store = 'store';                    // WorkflowStateStoreInterface — state snapshot for resume + leaf-call ids
    case Agents = 'agents';                  // array<string,string> — named agent roles -> model id; ai(agent:) routes by name
    case Tracer = 'tracer';                  // Tracer — hierarchical run trace (step/prompt/turn/tool); unset = no tracing
    case Ask = 'ask';                        // SpeakerInterface — who an ask() question goes to (human/agent); unset = no channel
    case Budget = 'budget';                  // Budget — the run's total token+time limit (parent of per-turn budgets); unset = unlimited
    case TurnTokenLimit = 'turnTokenLimit';  // int — per-turn (one ai() exchange) token cap (0 = unlimited)
    case TurnTimeLimit = 'turnTimeLimit';    // float — per-turn seconds cap (0 = unlimited)
    case BudgetPolicy = 'budgetPolicy';      // BudgetPolicy — what to do when the run total is spent; unset = Stop
    case ToolTimeoutMs = 'toolTimeoutMs';    // int — cap on ONE tool run in ms (kills a hung bash); 0/unset = uncapped
}
