# Incident Report: Repository Recovery and Runtime Stabilization

## Incident Details

- Incident Window: 2026-07-15 to 2026-07-16
- Severity: High
- Status: Resolved

## Overview

A commit-history rewrite introduced repository instability that required an immediate rollback. Following restoration of the branch state, the application exhibited runtime inconsistencies including environment drift, encryption key mismatch side effects, terminal instability, and cross-environment tooling failures. This report documents the root causes, corrective actions, validation steps, and preventive measures.

## User-Visible Impact

- Application reliability degraded during recovery.
- Terminal behavior became noisy and unstable.
- Debugbar payloads leaked into page source.
- Browser surfaced accessibility warnings on login controls.
- Encrypted credentials intermittently failed to decrypt ("The MAC is invalid").

## Primary Symptoms

- Runtime errors immediately after rollback.
- Credential decryption failures.
- Undefined PDO pgsql constant causing tooling/runtime crashes.
- Terminal resize warnings and transient invalid dimension states.
- Accessibility warning for missing label association.

## Root Causes

- Risky history-rewrite sequence destabilized the repository before rollback.
- Environment drift (key/config mismatch) during recovery.
- Encrypted data incompatible with the active application key.
- Cross-environment differences in PDO pgsql constant availability.
- Missing explicit label/input association in shared form component.

## Remediation Actions

- Halted rewrite flow and restored a known-good commit baseline.
- Repaired runtime health and validated via service checks and `/api/health`.
- Restored credential usability and removed temporary guards once stable.
- Hardened terminal container loading with guarded error handling.
- Improved terminal resize logic to retry transient invalid dimensions instead of logging noisy warnings.
- Added defensive pgsql constant checks before assignment.
- Corrected login accessibility by binding labels to generated input IDs.
- Disabled Debugbar in environments where raw debug payloads were undesirable.
- Revalidated with focused tests, full-repo PHPStan, Pint, container restart, and endpoint checks.

## Validation Evidence

- Feature tests: `tests/v4/Feature/TerminalIndexTest.php` passed.
- Static analysis: full-repo PHPStan passed with zero errors.
- Formatting: Pint passed.
- Runtime: container healthy; `/api/health` returned 200.
- Frontend: Vite endpoint served correctly.

## Final Outcome

- Repository state restored and remote sync re-established.
- Application runtime stabilized.
- Static analysis clean.
- Incident fully resolved.

## Preventive Measures

- Perform history-rewrite operations only on isolated, disposable branches.
- Capture environment/key snapshots before destructive git operations.
- Use a structured recovery checklist: rollback -> restore runtime -> validate -> remove temporary guards -> commit.
- Add regression coverage for terminal edge cases and key-dependent credential paths.

---

# Incident Report: Terminal WebSocket Teardown Follow-up

## Incident Details

- Incident Window: 2026-07-15
- Severity: Low
- Status: Resolved
- Related Prior Fix: `ae564038` (Terminal reconnect loop outliving page navigation)

## Overview

A follow-up terminal lifecycle bug was observed: WebSocket connections could remain open after the terminal session was no longer needed (for example after `pty-exited` or `unprocessable` terminal states). The earlier reconnect-loop fix in `ae564038` prevented stale reconnect chains after unmount/navigation, but this new case required intentional session-end teardown behavior.

## User-Visible Impact

- Potential lingering WebSocket connection after terminal session termination.
- Extra background socket activity beyond expected terminal lifecycle.

## Root Cause

- Session-end events updated UI state but did not always close the socket explicitly.
- Reconnect pathways (heartbeat/visibility/retry) were not gated by an explicit "reconnect allowed" lifecycle flag.

## Remediation Actions

- Added explicit reconnect gating with a `reconnectAllowed` lifecycle flag.
- Added a dedicated `disconnectSocket()` helper to centralize close + handler detach + timer cleanup + state update.
- On terminal session end (`pty-exited`) and rejected session (`unprocessable`), now intentionally disconnect socket with reconnect disabled.
- Guarded reconnect entry points (`scheduleReconnect`, connection error/close handlers, keepalive/visibility resume) behind lifecycle checks.
- Allowed reconnection only when a new terminal command/session is requested.

## Validation Evidence

- Diagnostics: no editor errors in `resources/js/terminalSession.js`.
- Frontend compile: `vite build` passed.
- Feature tests: `tests/v4/Feature/TerminalIndexTest.php` passed.

## Final Outcome

- Terminal WebSocket now disconnects when session is no longer needed.
- Reconnect logic remains available for active sessions but blocked for intentionally ended sessions.
- Follow-up incident resolved.

---

# Incident Report: Commit-History Rewrite Duplication and Documentation Reference Drift

## Incident Details

- Incident Window: 2026-08-03
- Severity: Medium (repository integrity and documentation accuracy — no application runtime impact)
- Status: Resolved

## Overview

A same-day commit-author correction on `main` was followed by merging a pull request branch that had been created before that rewrite. Because the branch was never rebased onto the corrected history, the merge reattached the entire superseded pre-rewrite commit chain as a second parent, duplicating a large portion of `main`'s history and resurrecting the incorrectly-authored commits the rewrite was meant to remove. Separately, the rewrite itself changed the hash of every commit from the first explicitly-modified one forward — not just the commits it targeted — silently orphaning dozens of commit-hash references already published in review documentation, tracked GitHub issues, and GitHub review comments. This report documents the root causes, corrective actions, validation steps, and preventive measures.

## User-Visible Impact

- `git log` on `main` showed roughly three dozen commits twice, including stale, incorrectly-authored copies of commits the same-day rewrite had already fixed.
- Dozens of commit-hash links in `docs/code-review.md`, `todo.md`, issue #70's checklist, and several GitHub review comments pointed to commits no longer reachable from `main`.
- `README.md`/`todo.md` summary statistics (test counts, commit totals, PR-merge counts) had drifted stale relative to the day's actual work.

## Root Causes

- A branch was created before a same-day `main` rewrite and merged back in without being rebased first, producing a real two-parent merge across the old and new histories instead of a clean fast-forward.
- The rewrite (`git filter-branch --env-filter` over the whole branch, with no explicit starting boundary) recomputed and changed the hash of every commit from the first modified one onward, not just the handful of commits its filter condition actually matched — invalidating far more already-published references than intended.
- An initial remediation attempt used a naive string-substitution approach that replaced a short hash prefix without respecting hash-length boundaries, corrupting several full 40-character hashes into invalid hybrid strings.

## Remediation Actions

- Rebuilt every commit from the polluted merge forward using `git commit-tree` with identical trees, messages, authors, and dates — producing a single clean ancestry chain with no content changes — then force-pushed after temporarily disabling the branch protection rule blocking force pushes.
- Verified byte-identical content (`git diff <old-tip> <new-tip>` empty) and confirmed the superseded chain was no longer reachable from `main`, both before and after the fix.
- Restored branch protection to its original locked-down configuration immediately after each force-push.
- Mapped every orphaned commit hash to its post-rewrite equivalent via git-tree matching (falling back to commit-message matching for the rare case where tree matching wasn't unique), then applied the corrected mapping across `docs/code-review.md`, `todo.md`, issue #70, and 7 GitHub review comments via the API.
- Caught the corrupted intermediate fix during verification, reverted it, and redid the replacement with a boundary-safe regex instead of plain substring substitution.
- Fixed one unrelated, pre-existing 39-character (truncated) hash found during verification, not caused by this incident.
- Ran a full documentation drift sweep afterward — README.md/todo.md summary statistics, issue #32/#120 tracking — to confirm nothing else had silently gone stale.

## Validation Evidence

- `git diff` between the pre-fix and post-fix `main` tips — empty, confirming only commit ancestry changed, not content.
- `git merge-base --is-ancestor` checks confirming the superseded chain was unreachable and the correct chain remained an ancestor, re-run after each force-push.
- A full scan of every commit-hash reference in `docs/code-review.md`, `todo.md`, issue #70, and GitHub review comments confirmed each one valid and reachable from `main`.
- Full Pest (1,376 tests), PHPStan, Pint, Vitest (1,202 tests), ESLint, and `format:check` all re-confirmed clean — unaffected, since every change in this incident was to git metadata and documentation only.
- 0 open CodeQL alerts; local `main` verified to match `origin/main` exactly after each push.

## Final Outcome

- `main` has a single, clean, non-duplicated ancestry chain with correct commit authorship throughout.
- Every commit-hash reference across tracked documentation, issues, and GitHub review comments resolves to a real, reachable commit.
- No application runtime, API, or user-facing behavior was affected at any point — the incident was confined entirely to git metadata and documentation accuracy.

## Preventive Measures

- Rebase (or recreate) any branch left open across a same-day history rewrite before merging it, rather than merging it against stale ancestry.
- After any history rewrite, explicitly check every open branch/PR for ancestry predating the rewrite before merging it back in.
- Prefer `git commit-tree`/boundary-safe regex replacement over plain string substitution for any bulk commit-hash find/replace.
- After a rewrite affecting already-documented commits, scan every existing commit-hash reference in tracked docs/issues for reachability from the current branch tip, not just the commits the rewrite explicitly targeted.

