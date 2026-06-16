<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Agent\ToolSpec;

/**
 * A named slice of the tool palette: the subset of tools a given agent/step is
 * allowed to see. Different agents get different sets. This is the "availability"
 * layer (least privilege), distinct from the permission layer (risk-based
 * confirm/deny) that gates each actual call.
 */
final readonly class ToolSet
{
    /** @param list<string> $tools tool names allowed in this set */
    public function __construct(
        public string $name,
        public array $tools,
    ) {
    }

    /**
     * The specs to advertise to the model for this set: the registry's tools
     * filtered down to the allowed names.
     *
     * @return list<ToolSpec>
     */
    public function resolve(Registry $registry): array
    {
        $specs = [];
        foreach ($registry->all() as $tool) {
            if (\in_array($tool->name(), $this->tools, true)) {
                $specs[] = new ToolSpec($tool->name(), $tool->description(), $tool->inputSchema());
            }
        }

        return $specs;
    }
}
