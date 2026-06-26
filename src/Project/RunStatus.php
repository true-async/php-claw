<?php

declare(strict_types=1);

namespace Claw\Project;

/**
 * A run's lifecycle state, persisted in the `runs` ledger. Mirrors {@see IssueStatus} so the run
 * lifecycle is typed too — the resume logic hinges on the exact 'running' sentinel, and a stray
 * string there silently breaks resume. Backed by the literals already stored, so existing dbs read
 * back unchanged.
 */
enum RunStatus: string
{
    case Running = 'running';       // started, not yet finished — the sentinel resumableRun() looks for
    case Generated = 'generated';   // the solver was generated and approved; the run itself is pending
    case Done = 'done';             // finished successfully
    case Failed = 'failed';         // gave up (repair budget exhausted, or a hard error)
}
