# Smoke Test

A manual, browser-based checklist for verifying the app actually works end-to-end — the thing every phase of `docs/livewire-to-react-migration.md` explicitly skipped in favor of automated checks (Pint/Pest/`yarn build`). Run this after any batch of migration work, and definitely before considering the whole migration complete. See `docs/command.md` for the commands to start the dev stack.

Check items off as `[x]` as you go, or just read top to bottom and confirm each still works. If something fails, note the page and the exact error (console + Laravel Debugbar) before fixing — that detail is what makes a bug report actionable.

## 0. Setup

- [ ] `spin up` (or `docker compose -f docker-compose.dev.yml up -d`), wait for all containers healthy (`docker compose -f docker-compose.dev.yml ps`).
- [ ] Visit `http://localhost:8000`. Confirm no blank page / no console errors on first load.
- [ ] Log in. Root user credentials come from `.env`'s `ROOT_USER_EMAIL`/`ROOT_USER_PASSWORD` (seeded by `RootUserSeeder`); alternatively register a normal account at `/register` if instance registration is enabled.
- [ ] Toggle dark/light mode (if a toggle exists on the page you land on) — confirm no flash-of-unstyled-content and the choice persists across a reload.

## 1. Cross-cutting checks (do these first — they affect every page below)

These verify the Livewire↔Inertia coexistence boundary itself, not any one page.

- [ ] From a Livewire page (e.g. Dashboard), click a sidebar link to a **converted** Inertia page (e.g. "Shared Variables"). Confirm it actually navigates and renders — this is the exact failure mode Phase 1 caught (`wire:navigate` fetching HTML but never booting React).
- [ ] From a converted Inertia page, click a sidebar link to a **still-Livewire** page (e.g. "Servers"). Confirm it loads correctly (full page load is expected/fine here, not a bug).
- [ ] Trigger a flash message on an Inertia page (e.g. submit a form successfully) — confirm the toast appears (`AppLayout.jsx` routes flash props through the same `window.toast()` used by Livewire).
- [ ] Trigger a flash message on a still-Livewire page — confirm its toast still works too (regression check — nothing about this migration should break the old path).
- [ ] Trigger a validation error on a converted form (e.g. leave a required field blank) — confirm inline error text renders under the field, not just a generic failure.

## 2. Easy bucket (5 pages) — static/read-only

- [ ] `/shared-variables` — 4 linked cards render, each link works.
- [ ] `/shared-variables/environment`, `/shared-variables/project`, `/shared-variables/server` — each lists its scoped variables.
- [ ] `/profile/appearance` — theme/width/zoom controls change the UI live.

## 3. Medium bucket (20 pages) — forms, no real-time dependency

Notifications (`/notifications/{channel}` for discord, email, slack, telegram, pushover, webhook):
- [ ] Each channel's toggle/URL form saves successfully and shows a success toast.
- [ ] Email channel specifically: SMTP block, Resend block, and the test-send action all work independently.

Profile & Security:
- [ ] `/profile` — name/email change (with verification-code flow) and password change both work.
- [ ] `/security/api-tokens` — create a token (copy the plaintext value shown once), revoke it.
- [ ] `/security/private-key/{uuid}` — edit name/description, reveal-to-edit the private key field, delete.
- [ ] `/security/cloud-tokens` — add a token (needs a real or intentionally-invalid Hetzner/DigitalOcean token to see both accept/reject paths), validate it, delete it.
- [ ] `/security/cloud-init-scripts` — create a script via the modal, edit it, delete it.

Team & Admin:
- [ ] `/team` — rename the team; confirm the delete-team section shows the correct blocking reason if resources exist.
- [ ] `/team/admin-view` — search users, delete-user flow (password-confirmed).
- [ ] `/admin` (root only) — user search + "switch to user" impersonation, then switch back.

Tags & Destinations:
- [ ] `/tags` — pick a tag, confirm applications/deployments list, trigger a redeploy.
- [ ] `/destination/{uuid}` and its Resources tab — edit name, view the resource list, delete (on a non-`coolify`-network destination).

Settings (instance-wide, root/admin only):
- [ ] `/settings/updates` — check-for-updates action, auto-update toggle.
- [ ] `/settings/advanced` — toggle each setting; specifically confirm the two password-confirmed one-way toggles (enable registration, disable two-step confirmation) require the password.
- [ ] `/settings/oauth` — edit a provider's fields, save.
- [ ] `/settings/scheduled-jobs` — all 3 tabs (Failures/Scheduler Runs/Skipped Jobs) load and filter correctly.
- [ ] `/settings/email` — same SMTP/Resend/test-send pattern as Notifications Email, at instance scope.

Auth:
- [ ] Force-password-reset flow — log in as a user with `force_password_reset` set, confirm you're routed to the bare (no sidebar) reset page and can't navigate away until it's done.

## 4. Hard bucket (4 pages so far) — real-time and non-trivial

These need the most attention — they're the pages automated checks can't fully exercise.

**`/project/.../deployment` (Application Deployment Index)**
- [ ] Trigger a deploy. Confirm the Heading's status badge updates **without a manual refresh** (Echo-driven via `ServiceStatusChanged`/`ServiceChecked`).
- [ ] Confirm the deployment list updates live as the deployment progresses (5s poll + Echo).
- [ ] Change an environment variable, confirm the "configuration changed" banner appears and the diff modal shows the right redaction (non-admins see masked env values, admins see real ones).
- [ ] Test Stop/Restart/Force-deploy buttons.
- [ ] Filter by PR ID, confirm pagination (prev/next) works.

**`/terminal` (live SSH terminal)** — the highest-risk page in the whole migration so far:
- [ ] Select a server, click Connect. Confirm a real shell prompt appears (not just a blank black box).
- [ ] Type a command, confirm output streams back.
- [ ] Test fullscreen toggle, and on a narrow/mobile viewport confirm the mobile key toolbar (arrows/tab/esc) works.
- [ ] Leave the tab in the background for a minute, bring it back — confirm it reconnects instead of silently dying (visibility-change handling).
- [ ] Deliberately kill connectivity (stop the `coolify-realtime` container briefly) — confirm the reconnect/backoff logic kicks in and recovers when it comes back, rather than requiring a full page reload.
- [ ] Confirm the session-expiry countdown badge appears and counts down.
- [ ] Try connecting to a container with no shell available — confirm the "Terminal Not Available" message shows instead of a silent failure.

**`/security/cloud-tokens`, `/security/cloud-init-scripts`** — no live/real-time surface, but re-confirm here since they're Hard-bucket:
- [ ] Already covered above in Section 3's list — no additional real-time behavior to check.

## 5. Regression spot-check: still-Livewire areas

Not part of this migration, but worth a quick sanity check that nothing else broke:
- [ ] Dashboard loads, project list renders.
- [ ] Open a project → environment → application, confirm the (still-Livewire) Configuration tabs work.
- [ ] `Server\Navbar`-nested pages (Server show/proxy/sentinel/resources/security/etc.) — still fully Livewire for now, confirm proxy start/stop/restart and status badges still update live via Livewire's own Echo wiring.
- [ ] Global search still finds things across both stacks.

## 6. Sign-off

Record here (or in a linked issue) once a full pass has been done: date, commit hash, who ran it, and any items that failed. An automated test suite passing is not the same claim as "confirmed working in a browser" — this file is what closes that gap.
