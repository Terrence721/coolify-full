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

