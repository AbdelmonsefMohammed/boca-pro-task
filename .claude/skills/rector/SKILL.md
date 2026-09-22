---
name: rector
description: "Automates PHP/Laravel refactors and upgrades with Rector. Use when running or fixing `composer test:rector`/`composer rector`, reviewing a Rector diff, deciding which rule set to add, or when the user mentions Rector, rector.php, dead code removal, type declarations, or automated refactoring."
license: MIT
compatibility: Requires Rector installed in project. Written against rector/rector 2.6.2 (this project's config, see rector.php).
metadata:
  version: "1.0.0"
---

# Rector

Rector rewrites PHP code by AST transformation — upgrading language/framework versions,
enforcing type declarations, removing dead code, and applying style rules — as an automated,
repeatable alternative to doing the same refactor by hand across many files.

## This Project's Configuration

`rector.php` (root of `src/`) currently applies, over `app`, `config`, `Modules`, `public`,
`resources`, `routes`, `tests`:

- `withPhpSets()` — rules for the PHP version(s) this project targets
- `withPreparedSets()`: `deadCode`, `codeQuality`, `codingStyle`, `typeDeclarations`,
  `privatization`, `earlyReturn`
- `withSkip([SafeDeclareStrictTypesRector::class])` — this one rule is disabled project-wide

**Not currently configured** (available, but not wired in — don't add without asking):
- `rector/rector-laravel` (`LaravelSetList`) — Laravel-specific rules (Eloquent magic-method →
  query builder, facade → DI, collection method simplification, `abort_if()`/`report_if()`
  helpers, version-upgrade sets like `UP_TO_LARAVEL_130`). Not in `composer.json`.
- `pestphp/pest-plugin-rector`'s `PestSetList::CODING_STYLE` — modernizes test assertions into
  Pest matchers. The plugin *is* installed (see the `pest-testing` skill) but its set isn't
  added to `rector.php`.

## Commands

**Always run these inside Docker, never on the host:** prefix every command with
`docker exec -w /var/www/html atlas-octane-app`. Host PHP is the wrong version/missing
extensions for this app, which produces misleading results. No exceptions.

| Command | Effect |
|---|---|
| `composer test:rector` | `vendor/bin/rector process --dry-run --xdebug` — preview only, this project's CI gate |
| `vendor/bin/rector process --dry-run` | Preview diff without `--xdebug` (faster, less verbose on failure) |
| `vendor/bin/rector process` | Apply the changes in place |
| `vendor/bin/rector process path/to/file.php` | Scope to one file/dir while iterating |
| `vendor/bin/rector process --clear-cache` | Force a clean re-analysis if results look stale |

`--xdebug` in the dry-run script just enables the Xdebug extension so a crash inside a rule
prints a real stack trace — it has nothing to do with test coverage.

## Workflow for Fixing a Failing `test:rector`

1. Run `composer test:rector` (or the plain `--dry-run`) and read the proposed diff per file —
   it shows exactly what would change, not just that something would.
2. Decide if the diff is correct:
   - **Correct** → run `vendor/bin/rector process`, then run the project's test suite. Rector
     rules are individually behavior-preserving, but stacked rules occasionally interact —
     never skip the test run after applying.
   - **Wrong / unwanted for this codebase** → add the specific rule (not the whole prepared
     set) to `withSkip([...])` in `rector.php`, and say why in the PR/commit, don't just
     silence the CI step.
3. Never hand-edit files to "match" what Rector would have done instead of running it — that
   drifts from what the config actually enforces and the next run just proposes the diff again.

## Writing Custom Rules (rare in application code)

If you ever add a project-local Rector rule: Rector 2.6 deprecated
`Rector\PhpParser\Node\FileWithoutNamespace` in favor of `FileNode` (handles both namespaced
and non-namespaced files); `beforeTraverse()` is `@final` now — use `getNodeTypes()` with
`FileNode::class` instead. `AbstractScopeAwareRector` was removed in the 2.0 line — extend
`AbstractRector` and fetch `Scope` via `Rector\PHPStan\ScopeFetcher::fetch($node)` when
needed. `getRuleDefinition()` is no longer required on custom rules.

## Common Pitfalls

- Applying Rector to `vendor/` or generated files — it's not in `withPaths()` here, keep it
  that way.
- Treating a Rector diff as automatically safe — read it, especially for `deadCode` and
  `earlyReturn` rules touching control flow.
- Forgetting `--dry-run` and being surprised by a large in-place rewrite.
- Adding a whole new `LaravelSetList`/`PestSetList` wholesale instead of reviewing what it
  would change first — these prepared sets can be broad; introduce them deliberately.
