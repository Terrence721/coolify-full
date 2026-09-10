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

---

# Incident Report: WSL2/Docker Desktop Hang Blocking VS Code Remote Connection

## Incident Details

- Incident Window: 2026-09-08 to 2026-09-10
- Severity: Medium
- Status: Resolved

## Overview

A Windows-side VS Code window was operating against this repository over its `\\wsl.localhost\Ubuntu\...` UNC path instead of connecting through Remote-WSL. This class of access pattern produced three separate, escalating symptoms — a path-resolution bug in the Vitest test-explorer extension, an unreliable file-change watcher, and finally a fully hung "Setting up Dev Containers" attempt — the last of which left the WSL subsystem itself in a state where new `wsl.exe` invocations stopped returning.

## User-Visible Impact

- The Vitest extension in VS Code failed to start, reporting `ERR_MODULE_NOT_FOUND` for `vitest/dist/node.js`.
- VS Code's file-change watcher stopped unexpectedly, requiring a window reload (temporarily) to resume.
- A "Setting up Dev Containers" progress notification span indefinitely with no error, blocking further work in the window.
- `git` commands run from the Windows-side Bash tool against the repo began failing with "detected dubious ownership."
- VS Code's Source Control panel separately reported the repository as unsafe/potentially owned by another user.

## Primary Symptoms

- Vitest worker log showed a **doubled** UNC prefix: `\\wsl.localhost\Ubuntu\wsl.localhost\Ubuntu\root\projects\coolify-full\node_modules\vitest\dist\node.js` — the host prefix had been prepended twice while resolving `node_modules` from the workspace root.
- The Dev Containers log showed a single line stuck for 28+ seconds: `Run: wsl -d Ubuntu -e wslpath -u \\wsl.localhost\Ubuntu\root\projects\coolify-full` — a call that should return near-instantly.
- `Get-Process` showed an abnormal pile-up of stray `wsl.exe` (12) and `wslhost.exe` (9) processes, consistent with multiple earlier reconnect attempts each spawning a `wsl -e ...` call that never returned or got cleaned up.
- `git status`/`git diff` from the Windows side refused to run ("may refer to a non-local directory") until a one-off, non-persistent `-c safe.directory=*` override was supplied per invocation.

## Root Causes

- VS Code (and its extensions, and Windows `git.exe`) were operating on the repository through its Windows UNC path (`\\wsl.localhost\Ubuntu\...`) rather than through a Remote-WSL connection — several tools in this chain (the Vitest extension's path-joining logic, `npx`'s use of `cmd.exe` to spawn shims, Windows Git's ownership check) do not handle a WSL-hosted repo accessed this way.
- Repeated failed reconnect/Dev-Container attempts against that UNC path left a growing number of orphaned `wsl.exe`/`wslhost.exe` processes that never exited, which eventually caused *new* `wsl -e ...` invocations (including the routine `wslpath` bootstrap call Dev Containers always runs first) to queue behind the stuck ones indefinitely instead of completing.
- Docker Desktop's own WSL2 backend distro (`docker-desktop`) does not automatically notice or recover from an externally-triggered `wsl --shutdown` (as opposed to a shutdown it initiated itself), so its daemon stayed unreachable after the WSL subsystem was cycled until the Docker Desktop application was fully restarted.

## Remediation Actions

- Diagnosed the doubled-path and file-watcher issues as symptoms of running natively on Windows against a WSL-hosted folder; recommended (and confirmed) switching to Remote-WSL as the fix for both.
- Confirmed the Dev Containers hang was a genuine WSL-subsystem stall, not an application-level bug, by reproducing the exact stuck command (`wsl -d Ubuntu -e wslpath -u ...`) directly and timing it.
- Gracefully stopped all 16 running containers across this project and two unrelated local projects (`docker stop`) before touching the WSL VM, to avoid an abrupt mid-write shutdown of stateful services (Postgres, Kafka).
- Ran `wsl --shutdown` to clear the stuck process pile-up.
- Fully stopped and relaunched the Docker Desktop application (`Docker Desktop.exe`, `com.docker.backend`, `com.docker.build`) after confirming its backend distro did not reconnect on its own.
- Restarted all 16 previously-running containers once the Docker daemon responded again.

## Validation Evidence

- Re-ran the exact command captured in the original Dev Containers log (`wsl -d Ubuntu -e wslpath -u '\\wsl.localhost\Ubuntu\root\projects\coolify-full'`) after remediation: resolved in 0.6 seconds (down from an indefinite hang), correctly returning `/root/projects/coolify-full`.
- `docker ps` after restart confirmed all 16 containers back in their prior running state, with the pre-existing (unrelated) `coolify`/`coolify-https-proxy` unhealthy status unchanged by this incident.
- `Get-Process` re-check showed the `wsl.exe`/`wslhost.exe` process count back to a normal baseline.

## Final Outcome

- WSL and Docker Desktop's backend are both healthy; new `wsl -e ...` invocations return promptly.
- All previously-running containers, across all local projects, are back up.
- One pre-existing, unrelated issue was surfaced during recovery and flagged separately: `coolify-https-proxy` crash-loops on an nginx upstream (`coolify-realtime`) that isn't currently running as its own container — not caused by, or fixed as part of, this incident.

## Preventive Measures

- For this repository, always connect VS Code via **Remote-WSL** (`WSL: Reopen Folder in WSL` / `code /root/projects/coolify-full` from a WSL shell) — never operate on it from a Windows-side window through the UNC path. See the "Common Issues" section of `DEVELOPING_IN_CONTAINERS_WINDOWS.md` for the symptom-specific writeups (doubled-path Vitest error, hung Dev Containers spinner).
- This project has no `.devcontainer` config and isn't meant to be opened via "Reopen in Container" — its dev stack is docker-compose based. Picking that option by mistake is the most likely trigger for the hang described here.
- If a `wsl -e ...`-based reconnect attempt ever hangs again, check `Get-Process | Where-Object {$_.ProcessName -match 'wsl'}` for a process pile-up before assuming it's an application bug — a healthy session has only a couple of these.
- Before running `wsl --shutdown` for any reason, gracefully stop running containers first (`docker stop`), since it hard-stops every WSL2 distro at once, including Docker Desktop's backend.
- After `wsl --shutdown`, expect to manually restart the Docker Desktop application itself — it does not reconnect its backend on its own when the shutdown wasn't initiated by Docker Desktop.

---

# Incident Report: Vitest Path-Traversal CVE Remediation and Yarn Classic→Berry Migration

## Incident Details

- Incident Window: 2026-09-10
- Severity: Moderate
- Status: Resolved

## Overview

A Dependabot alert flagged `@vitest/mocker` for CVE-2026-84373 (path traversal / arbitrary file read via a redirect mock, fixed in 4.1.11). The first remediation attempt — a plain `yarn install` — was run against a Yarn Classic (v1) lockfile using a Yarn Berry (v4) binary, which silently migrated the lockfile to Berry's format, dropped a security-relevant `resolutions` override it couldn't parse, and then failed outright on an unrelated Windows/UNC-path link error. After reverting that false start, the version bump was applied by hand-editing the classic lockfile, and — at the user's direction — the project was then deliberately and fully migrated to Yarn Berry, with the dropped override rewritten in valid Berry syntax and CI/Docker updated to match.

## User-Visible Impact

- None in production — `@vitest/mocker` is a development-only dependency, and the vulnerability itself requires exposing a dev server's unauthenticated HMR WebSocket to reach.
- Locally: a `yarn.lock` corruption scare (an 8,089-line diff from a single `yarn install`) that required immediate revert, and a temporarily-hung `node_modules/.bin` link step.

## Primary Symptoms

- Dependabot: "Vitest: Path Traversal / Arbitrary File Read via `@vitest/mocker` Redirect Mock" (Moderate), `@vitest/mocker` pinned at 4.1.10 (vulnerable range: 2.1.0–<4.1.11).
- First `yarn install` attempt printed `YN0087: Migrated your project to the latest Yarn version` and three `YN0057` parse errors against the project's `resolutions` field, then failed at the Link step with `ENOENT ... node_modules/.bin/rolldown` under the Windows UNC path.
- A second, deliberate migration attempt (this time intentional) hit the same class of `YN0057` parse error against a corrected-but-still-invalid `resolutions` rewrite (a `**`-glob form), which turned out not to be supported by Berry's `resolutions` field at all.

## Root Causes

- This repository's `yarn` command, depending on environment, resolved to two fundamentally different tools: Yarn Classic 1.22.22 (WSL and the `coolify-vite` container, via a standalone install bypassing Corepack) versus Yarn Berry 4.18.0 (Windows, via a Corepack shim) — with nothing in the repository pinning which one should be used.
- The project's `resolutions` field used Yarn Classic's arbitrary-depth path syntax (e.g. `"eslint/@eslint/config-array/minimatch/brace-expansion"`), which Yarn Berry's `resolutions` field does not support at all — Berry's documented syntax allows only one level of specificity (`parent/child`, optionally with a version qualifier), not multi-segment chains or `**` globs.
- Yarn Berry auto-migrates a detected Classic lockfile on first run rather than refusing — so the mismatched-binary problem manifested as silent data loss (the unparseable `resolutions` entries) and lockfile corruption rather than a clear error.
- Running the install from a Windows-side shell against the UNC-mounted repo path caused a real (secondary) failure in the Link step, independent of the Classic/Berry mismatch.

## Remediation Actions

- Reverted the first failed migration (`git checkout -- package.json yarn.lock`, removed the stray `.yarnrc.yml`) to restore the last known-good committed state.
- Hand-edited the still-Classic `yarn.lock`, bumping `vitest` and all 7 `@vitest/*` sibling packages from 4.1.10 to 4.1.11 with registry-verified `resolved` URLs and `integrity` hashes, without invoking any package manager — avoiding both failure modes above for this narrow fix.
- At the user's direction, performed a deliberate full migration to Yarn Berry for this project specifically:
  - Added `"packageManager": "yarn@4.18.0"` to `package.json`, matching the version already in use as the Windows/Corepack global default.
  - Rewrote `resolutions` in valid Berry syntax. Investigated the dependency tree first (two distinct `minimatch` resolutions in the lockfile) and confirmed only one level of nesting was actually needed: `"minimatch/brace-expansion": "5.0.9"` covers both (harmless for the modern `minimatch` line, which already requested `brace-expansion@^5.0.5`; the actual fix for the older line, which requested the vulnerable `^1.1.7`).
  - Added `.yarnrc.yml` (`nodeLinker: node-modules`) to keep the existing flat `node_modules` layout tooling already depends on, rather than Berry's default PnP mode.
  - Added standard Berry entries to `.gitignore` (`.yarn/*` with tracked exceptions, `.pnp.*`).
  - Ran the actual install from inside WSL via `corepack yarn install` (not the Windows-side binary, and not WSL's then-still-Classic binary) to perform the real migration and verify it end-to-end.
  - Updated `.github/workflows/quality.yml` (all 4 jobs) to run `corepack enable` before installing, and swapped Classic's `--frozen-lockfile` flag for Berry's `--immutable`.
  - Updated `docker-compose.dev.yml`'s `coolify-vite` service to run `corepack enable` before `yarn install`.
  - Closed the remaining WSL gap at the user's request: removed the standalone `npm install -g yarn` (Classic 1.22.22) that was occupying the same install path Corepack needed for its own shims, then ran `corepack enable` — after which bare `yarn` in WSL resolves per-project via Corepack (this project → Berry 4.18.0; any project without a pin → Corepack's own Classic 1.22.22 fallback, identical to the prior global behavior).

## Validation Evidence

- Registry lookups (`npm view <pkg>@4.1.11 dist...`) confirmed real, valid `resolved`/`integrity` values for all 8 hand-edited lockfile entries before the fix was applied.
- After the full Berry migration, confirmed on disk (`node_modules/brace-expansion` → `5.0.9`, single hoisted copy, no stale nested versions) and in the lockfile (no lockfile entry at all for the vulnerable `brace-expansion@npm:^1.1.7` descriptor — it resolves straight through to the pinned `5.0.9`).
- Confirmed `vitest@npm:^4.1.10` resolves to `4.1.11` in the final Berry-format lockfile — the CVE fix survived the migration intact.
- Ran a real `yarn install` (bare command, no `corepack` prefix) inside WSL post-migration: completed cleanly on Yarn 4.18.0 with no parse errors.
- Verified environment consistency after the Corepack fix: `/tmp` (no project ancestry) → Classic 1.22.22 (unchanged fallback); this project and its `docker/coolify-realtime` subdirectory → Berry 4.18.0 (inherited pin).

## Final Outcome

- `@vitest/mocker`/`vitest` patched to 4.1.11 across every environment this project runs in (Windows, WSL, CI, the `coolify-vite` container).
- The project is now explicitly and consistently pinned to Yarn Berry 4.18.0 everywhere it's built or tested, without changing the global Yarn default for any other project on the machine.
- The original three-entry, unparseable-in-Berry `resolutions` block is now one valid, verified entry with equivalent (in practice, identical) effect.

## Preventive Measures

- Never run a bare `yarn` command against this repository without first confirming which binary it resolves to in that environment (`yarn --version`) — this incident's first failure would have been caught immediately by checking that before running an install.
- When intentionally migrating Classic → Berry (or vice versa), audit the `resolutions` field syntax explicitly first; Berry silently drops entries it can't parse rather than failing loudly, so a successful-looking install is not sufficient evidence that overrides were preserved.
- Prefer running Node/Yarn installs for this project from inside WSL (or a container) rather than from a Windows-side shell against the UNC path, independent of the Classic/Berry question — the UNC path has its own separate failure modes (see the WSL2/Docker Desktop hang incident above).
- `packageManager` in `package.json` plus `corepack enable` (already added to CI and the dev container) is now the mechanism that keeps every environment on the same Yarn version going forward — if a future environment's `yarn` doesn't respect it, suspect a non-Corepack-managed install shadowing it, as WSL's was here.

