<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\Message;

/**
 * A node in the context tree. Each step owns a node; a subworkflow is a subtree.
 * Navigation is structural (parent / children / root), not semantic — that keeps
 * it cheap and deterministic. Semantic search belongs to the knowledge base, not
 * here.
 */
final class ContextNode
{
    /**
     * @param list<Message>     $messages the turn history at this node
     * @param list<ContextNode> $children
     */
    public function __construct(
        public readonly ?ContextNode $parent = null,
        public array $messages = [],
        public array $children = [],
    ) {
    }

    public function root(): ContextNode
    {
        $node = $this;
        while ($node->parent !== null) {
            $node = $node->parent;
        }

        return $node;
    }
}
