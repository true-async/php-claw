<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Marks a workflow method as a LOCAL tool — a capability the model may call during this workflow's
 * {@see WorkflowAbstract::ai()} exchanges, and only this workflow's. Unlike a {@see \Claw\Tool\ToolInterface}
 * registered at the composition root (global, shared by every run), a `#[Tool]` method lives on the
 * workflow instance: it is discovered by reflection, wrapped as a tool for the run, and gone when the
 * run ends. This lets a generated solver give itself a bespoke capability — validate its own output,
 * a domain check, a custom critic — by writing a plain method instead of a whole new tool class.
 *
 * The method's typed parameters become the tool's input schema (so the model knows what to pass) and
 * its return value (a string, or anything stringable) is the tool result. The method reaches the
 * outside world only through {@see WorkflowAbstract::tool()} — the same gated path as any step — so a
 * local tool cannot do more than the workflow already can.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Tool
{
    /**
     * @param string  $description what the tool does — shown to the model, so be concrete
     * @param ?string $name        the tool name the model calls; defaults to the method name in snake_case
     */
    public function __construct(
        public string $description,
        public ?string $name = null,
    ) {
    }
}
