# WORKFLOW — how work is done in this project

Written from what the repository already does (branch names, `git log`, the CI workflow),
not invented. Change it deliberately, not in passing.

## Branches

- Branch off `main`. `main` is the main branch.
- Name: `<type>/<short-kebab-summary>` — `feat/issue-tree`, `fix/critic-defers-to-rubric`,
  `test/drop-fabricated-concurrency-test`, `chore/bump-deps`.
- Delete the branch after the merge (`gh pr merge --delete-branch`).

## Commits

- Conventional Commits: `<type>(<scope>): <summary>`, e.g. `feat(workflow): …`,
  `fix(project): …`. Scope is the module, roughly the `src/` subdirectory.
- Message short. One line of substance; a body only where **why** is not obvious without
  it — never a retelling of the diff.
- English, always.
- No ticket numbers in code comments. Point at a document under `dev/` instead.

## PRs and merging

- Every change lands through a PR. **Squash merge**, so exactly one commit reaches `main`.
- Both CI checks must be green before merging: *Static analysis & code style* and
  *Tests on TrueAsync PHP* (`.github/workflows/ci.yml`).
- The PR description says what broke and why the fix is shaped this way — the commit is
  terse, the PR is where the reasoning goes.

## Local build and test

Everything runs under the TrueAsync PHP binary:

```
composer qa        # style + static analysis + tests, in that order
composer cs-fix    # apply coding style
composer analyse   # PHPStan, level 8
composer test      # Testo
```

Tests are written with [Testo](https://php-testo.github.io/) (`#[Test]` methods,
`Testo\Assert`), which is what this project uses — do not introduce a second runner.

PHPStan runs without the `true_async` extension in CI, so `Async\*` stub types differ from
the local build; do not catch Async exceptions by type.

## Versions and releases

No versioning or tags yet. `CHANGELOG.md` does not exist; add one when the project has
users who need to read it.
