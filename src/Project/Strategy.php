<?php

declare(strict_types=1);

namespace Claw\Project;

use Claw\Exceptions\ClawException;

/**
 * How an issue is to be solved — the ProjectManager's verdict, and the reason it is an enum rather
 * than a sentence.
 *
 * The system already had a prose verdict: a step that rated a task `simple`/`moderate`/`complex` and
 * handed that rating on inside a prompt. It was ignored — a task rated `complex` still got a
 * one-step solver, twice. A verdict only counts when CODE branches on it, so this is the type the
 * run pipeline matches against.
 *
 * The cases are ordered by how much machinery they spend, which is what {@see rank()} exposes: a
 * strategy that failed is retried by ESCALATING to a higher rank, never by picking the same one
 * again — that would fail identically — and never by dropping to a lower one.
 */
enum Strategy: string
{
    /** One agent turn loop with the project's tools. No solver class, no critic — a localized change. */
    case Direct = 'direct';

    /** A ready-made, hand-written and tested workflow from the library fits this task. */
    case Library = 'library';

    /** Nothing off the shelf fits: generate a bespoke solver workflow for this issue. */
    case Generate = 'generate';

    /** Too big for one run — split into sub-issues, each triaged in turn. The costliest verdict. */
    case Decompose = 'decompose';

    /**
     * Parse a strategy a model supplied. Unknown input is an error rather than a silent default: the
     * whole point of the enum is that the routing decision is explicit, and quietly resolving a typo
     * to some fallback would hide exactly the decision this type exists to make visible. The thrower
     * turns it into a tool result the model reads and corrects.
     *
     * @throws ClawException
     */
    public static function parse(string $value): self
    {
        $strategy = self::tryFrom(strtolower(trim($value)));

        if ($strategy === null) {
            throw new ClawException(
                "unknown strategy '{$value}', expected one of: " . implode(', ', array_column(self::cases(), 'value')),
            );
        }

        return $strategy;
    }

    /**
     * Resolve a persisted value. Separate from {@see parse()} because the two read different things:
     * parse() reads what a MODEL supplied and its error is a message the model corrects, while this
     * reads our own rows, where an unknown value is corrupt data and coercing it would silently
     * re-route an issue.
     *
     * @throws ClawException
     */
    public static function stored(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new ClawException("corrupt strategy '{$value}' in the issue ledger");
    }

    /** How much machinery this strategy spends — the order a failed attempt escalates along. */
    public function rank(): int
    {
        return match ($this) {
            self::Direct => 0,
            self::Library => 1,
            self::Generate => 2,
            self::Decompose => 3,
        };
    }
}
