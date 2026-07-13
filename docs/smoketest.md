# Smoke Test

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 13, 2026**

A manual, browser-based checklist for verifying the app actually works end-to-end — the thing every phase of `docs/livewire-to-react-migration.md` explicitly skipped in favor of automated checks (Pint/Pest/`yarn build`). Run this after any batch of migration work, and definitely before considering the whole migration complete. See `docs/command.md` for the commands to start the dev stack.

Check items off as `[x]` as you go, or just read top to bottom and confirm each still works. If something fails, note the page and the exact error (console + Laravel Debugbar) before fixing — that detail is what makes a bug report actionable.

## 0. Setup

- [ ] `spin up` (or `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`), wait for all containers healthy (`docker compose -f docker-compose.yml -f docker-compose.dev.yml ps`). Run from a WSL2 terminal in the repo's WSL2 path — see `docs/command.md`'s "WSL2 migration" section if you're on Windows.
- [ ] Visit `http://localhost:8000`. Confirm no blank page / no console errors on first load.
- [ ] Log in. Dev-seeded credentials are `test@example.com` / `password` (`UserSeeder`, run by first-boot `dev --init`); `ROOT_USER_EMAIL`/`ROOT_USER_PASSWORD` only apply if the production `RootUserSeeder` path was used. Alternatively register at `/register` if instance registration is enabled.
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

## 3. Medium bucket (22 pages) — forms, no real-time dependency

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

"+ New" resource wizard (`/project/{uuid}/environment/{uuid}/new`, Phase 51):

- [ ] Type step renders all tiles (git/docker/databases/one-click services); search + category filter narrow the service grid; switching environment via the dropdown reloads the page for that environment.
- [ ] Multi-server and multi-destination steps appear only when there's actually a choice; with one server + one destination they're skipped entirely.
- [ ] PostgreSQL shows the version-picker step before creating; picking a version creates the database and lands on its configuration page.
- [ ] Redis (or any other database) creates directly and redirects to its configuration page.
- [ ] A one-click service (e.g. cloudflare-ddns) creates and redirects to the service configuration page.
- [ ] Dockerfile / Docker Image / Docker Compose forms each create an application/service and redirect (Monaco editor renders on a cold load for Dockerfile/Compose).

Sources (`/sources`, Phase 53):

- [ ] List renders team GitHub Apps; unregistered ones show "Configuration is not finished."
- [ ] "+ Add" opens the create modal; `/sources?create=1` opens it automatically (this is how GlobalSearch's "GitHub App" quick action arrives).
- [ ] Creating an app (default name pre-filled, optional organization, self-hosted accordion, system-wide warning callout) redirects to the new app's configuration page.

## 4. Hard bucket (45 pages so far) — real-time and non-trivial

These need the most attention — they're the pages automated checks can't fully exercise.

**`/project/.../deployment` (Application Deployment Index)**

- [ ] Trigger a deploy. Confirm the Heading's status badge updates **without a manual refresh** (Echo-driven via `ServiceStatusChanged`/`ServiceChecked`).
- [ ] Confirm the deployment list updates live as the deployment progresses (5s poll + Echo).
- [ ] Change an environment variable, confirm the "configuration changed" banner appears and the diff modal shows the right redaction (non-admins see masked env values, admins see real ones).
- [ ] Test Stop/Restart/Force-deploy buttons.
- [ ] Filter by PR ID, confirm pagination (prev/next) works.

**`/project/{uuid}/environment/{uuid}/database/{uuid}/backups/{backup_uuid}` (Database Backup Execution)**

- [ ] Trigger a real backup run (Backup Now or wait for the schedule). Confirm a new execution card appears **without a manual refresh** (Echo-driven via `BackupCreated` — this listener was broken in the original Livewire code, listening on the wrong channel; confirm it's actually correct now, not just relying on the 5s poll fallback).
- [ ] Edit and Save the schedule form (frequency, retention settings, S3 toggle); confirm the per-engine "Databases To Backup"/"Backup All Databases" fields show correctly for each database engine.
- [ ] "Delete Backups and Schedule" — confirm the typed-database-name + password confirmation both gate the button, and a wrong password shows an error without deleting anything.
- [ ] Delete a single execution — confirm the S3 checkbox only appears when that execution was actually uploaded to S3, and the delete redirects back to the same page (not the Index).
- [ ] "Cleanup Failed Backups" and "Cleanup Deleted" (typed-confirmation + password) both actually remove the expected rows and nothing else.
- [ ] Download a successful execution's backup file.

**`/terminal` (live SSH terminal)** — the highest-risk page in the whole migration so far:

- [ ] Select a server, click Connect. Confirm a real shell prompt appears (not just a blank black box).
- [ ] Type a command, confirm output streams back.
- [ ] Test fullscreen toggle, and on a narrow/mobile viewport confirm the mobile key toolbar (arrows/tab/esc) works.
- [ ] Leave the tab in the background for a minute, bring it back — confirm it reconnects instead of silently dying (visibility-change handling).
- [ ] Deliberately kill connectivity (stop the `coolify-realtime` container briefly) — confirm the reconnect/backoff logic kicks in and recovers when it comes back, rather than requiring a full page reload.
- [ ] Confirm the session-expiry countdown badge appears and counts down.
- [ ] Try connecting to a container with no shell available — confirm the "Terminal Not Available" message shows instead of a silent failure.
- [ ] **Known issue observed during Phase 24 manual QA (dev environment, 2026-07-10)**: the client repeatedly logged `[Terminal] Connection timeout after 10000ms` / `WebSocket error` / `Max reconnection attempts reached` in an endless reconnect loop. The `coolify-realtime` container was up/healthy and its logs showed the WebSocket handshake actually succeeding ("Websocket client authentication succeeded") — the connection then closed abnormally (code 1006) right after auth, suggesting the terminal session itself (PTY/SSH to the target server) failed to establish rather than a WebSocket infrastructure problem. Likely cause: no genuinely reachable SSH target server in this dev environment. Needs real validation once Terminal is converted — confirm whether this reproduces against a real, reachable server before treating it as a bug.

**`/security/cloud-tokens`, `/security/cloud-init-scripts`, `/security/private-key`, `/destinations`, `/project/{uuid}`, `/project/{uuid}/edit`, `/storages`, `/projects`, `/team/members`, `/servers`, `/project/{uuid}/environment/{uuid}/clone`, `/project/{uuid}/environment/{uuid}`, `/` (Dashboard), `/source/github/{uuid}`, `/shared-variables/team`, `/shared-variables/project/{uuid}`, `/shared-variables/environments/project/{uuid}/environment/{uuid}`, `/shared-variables/server/{uuid}`, `/project/{uuid}/environment/{uuid}/database/{uuid}/backups`** — no live/real-time surface, but re-confirm here since they're Hard-bucket:

- [ ] `/security/private-key` — as an Admin/Owner, confirm every key is clickable; as a Member, confirm keys still render but are view-only (not clickable, tooltip explains why); "+ Add" modal (Generate RSA/ED25519 buttons + manual paste), confirm the created key appears in the grid immediately; "Delete unused SSH Keys" confirmation modal, confirm only genuinely-unused keys (the "Unused" badge) disappear.
- [ ] `/destinations` — confirm the grid lists destinations across all usable servers, with a "Deprecated" badge on swarm ones; "+ Add" modal, confirm picking a different server updates the auto-generated name, and submitting redirects into the new destination's Show page.
- [ ] `/project/{uuid}` — confirm the auto-created "production" environment shows; "+ Add Environment" modal creates a new one; "Delete Project" is blocked with an explanation if any environment has resources, and works (typed name confirmation) when genuinely empty. From the Dashboard/Projects list, confirm clicking a project card navigates correctly regardless of whether it has exactly one environment (goes straight to Resources, now also Inertia — see below) or zero/multiple (lands here).
- [ ] `/project/{uuid}/edit` — rename + change description, confirm "Delete Project" here matches the same behavior as the Show page.
- [ ] `/project/{uuid}/environment/{uuid}/edit` — rename + change description; "Delete Environment" is blocked with an explanation if the environment has resources, and works (typed name confirmation) when genuinely empty; confirm the breadcrumb links (Project → Environment) navigate correctly.
- [ ] `/storages` — "+ Add" modal against a real S3-compatible endpoint (both valid credentials and intentionally-wrong ones, to see the connection-failure error surface); confirm a storage with a failed last connection check shows the "Not Usable" badge.
- [ ] `/storages/{uuid}` (General tab) — edit credentials and Save (confirm it actually re-tests the connection and rolls back on failure); "Validate Connection" against a real endpoint; "Delete" typed-name confirmation, confirm the message changes when the storage has backup schedules attached.
- [ ] `/storages/{uuid}/resources` — confirm backup schedules using this storage list correctly grouped by database; search filter narrows the table; move a backup to a different storage via the picker + Save; "Disable S3" on a schedule and confirm it reverts to local-only backups.
- [ ] `/projects` — confirm each card's whole-card click-through works (both for projects with exactly one environment, landing on the Resources page, and projects with zero/multiple, landing on `/project/{uuid}`); "+ Add Resource" shortcut only appears when the project has an environment; "Settings" link only appears if you can update; "+ Add" modal creates a new project and redirects into its auto-created production environment.
- [ ] `/team/members` — as Owner, promote a member to Admin and to Owner, demote back down; as Admin, confirm you can't promote another Admin to Owner (error toast, no change); remove a member; generate an invitation link and confirm "Copy Invitation Link" actually copies it; send an invitation by email (only when transactional email is enabled) and confirm it arrives; as Admin, confirm you can't invite an Owner; revoke a pending invitation.
- [ ] `/servers` — confirm the grid lists every server owned by the team, with the red-border/"Not reachable"/"Not usable"/"Disabled by the system" states showing correctly; "+ Add" modal creates a server via the IP flow and redirects into its Show page; confirm submitting a duplicate IP shows the right error; confirm the "Connect a Hetzner Server" option is **not** present here (known gap, Phase 33) — it's still reachable via the global "+" search menu's own Add Server modal, unconverted.
- [ ] `/project/{uuid}/environment/{uuid}/clone` — select a destination server/network from the table, confirm the resources list matches the source environment; "Clone to new Project" with a name that already exists shows the right error without creating anything; a genuinely new name creates the project + clones every application/database/service (tags, scheduled backups/tasks, env vars all carried over); "Clone to new Environment" does the same within the same project; toggle "Clone volume data too" and confirm it doesn't error even without real volume data to copy (the underlying `VolumeCloneJob`/start-stop dispatches are SSH-adjacent and thus part of the standing untested-happy-path gap noted in Section 4's intro above).
- [ ] `/project/{uuid}/environment/{uuid}` — hover the Project breadcrumb, confirm the dropdown lists every project; hover the Environment breadcrumb, confirm sibling environments list and each expands into its own resources flyout on hover; search box filters applications/databases/services live by name/fqdn/description/tag; status badges (running/exited/starting/restarting/degraded) render correctly on each resource card; "Delete Environment" is blocked with an explanation if the environment has resources, and works (typed name confirmation) when genuinely empty; "+ New" and "Clone" links only appear when you can create resources.
- [ ] `/` (Dashboard) — Projects section: "+ Add" modal (only when you have projects and can create) creates a project and appears immediately; empty state's inline "Add" opens the same modal. Servers section: "+ Add" modal (only when you have servers, private keys, and can create) creates a server via the IP flow; "no private keys found" empty state's inline "add" opens the shared `PrivateKeyCreateModal` and the newly-created key becomes usable without a page reload; "no servers found" empty state's inline "Add" opens the Add Server modal. Confirm the logo and sidebar "Dashboard" link both navigate here via a real Inertia transition (not a full page reload).
- [ ] `/source/github/{uuid}` — pre-registration state: "Register Now" against a real GitHub account (confirm the manifest-flow form-post actually lands on GitHub's app-creation page with fields pre-filled); "Manual Installation" creates a stub app with placeholder IDs and lands on the tabbed config. Post-registration: General tab "Save" persists changes, "Sync Name" against a real GitHub App (calls GitHub's API), "System Wide?" toggle instant-saves (non-cloud only); Permissions tab "Refetch" against a real installation (calls `GithubAppPermissionJob` for real); Resources tab search filters live, resource links navigate correctly; "Delete" typed-name confirmation, confirm it's blocked with an explanation if any application still uses this source.
- [ ] `/shared-variables/team`, `/shared-variables/project/{uuid}`, `/shared-variables/environments/project/{uuid}/environment/{uuid}`, `/shared-variables/server/{uuid}` — "+ Add" modal creates a variable (test both single-line and "Is Multiline?" toggled); confirm a locked variable (after clicking "Lock") shows a masked key with only the comment editable, and its delete confirmation requires typing the exact key; toggle "Is Multiline?" on an unlocked variable and confirm it instant-saves without a page reload; switch to Developer view, edit the raw `KEY=value` text, "Save All Environment Variables", and confirm removed lines actually delete those variables while edited lines update in Normal view; on the Server page specifically, confirm `COOLIFY_SERVER_UUID`/`COOLIFY_SERVER_NAME` never appear in the list and can't be created manually (typing that key into "+ Add" should show an error, not silently succeed).
- [ ] `/project/{uuid}/environment/{uuid}/database/{uuid}/backups` (standalone database only — the service-database equivalent stays Livewire) — confirm the nav tabs (Configuration/Logs/Terminal/Backups) all navigate correctly; Start/Restart/Stop buttons against a real, reachable server, confirming the activity-monitor slide-over shows real streaming output on Start/Restart (not just "Waiting for the process to start..."); "+ Add" modal creates a scheduled backup (test both a plain cron expression and an S3-enabled one against a real S3 storage); confirm each backup card's status badge/timing text updates after a real backup execution completes, and that clicking a card navigates to its (still-Livewire) Execution page.
- [ ] Otherwise already covered above in Section 3's list — no additional real-time behavior to check.

**`Server\Navbar`-dependent pages (17 of 21, `/server/{server_uuid}/...`)** — grab a real server UUID from `/servers` first. These carry the heaviest concentration of untested-happy-path gaps in the whole migration: every SSH-touching action below was verified only via safe/validation-rejection paths in Pest, never a real end-to-end run, specifically because doing so would need real SSH mocking infrastructure this migration didn't build. This section is where that gap actually gets closed.

- [ ] **Chrome itself** (any page below): proxy status badge + Sentinel badge render correctly; Start/Restart/Stop Proxy buttons work and open the live log slide-over; confirm the slide-over shows real streaming output, not just "Waiting for the process to start...".
- [ ] `/server/{uuid}/swarm` — toggle Swarm Manager/Worker (mutually exclusive), confirm instant-save.
- [ ] `/server/{uuid}/security/terminal-access` — toggle terminal access on/off (typed confirmation + password), confirm the status badge flips.
- [ ] `/server/{uuid}/advanced` — change concurrent builds/timeout/queue limit/disk-usage settings, save; try an invalid cron expression for disk-usage check frequency and confirm a clean error (not a 500).
- [ ] `/server/{uuid}/ca-certificate` — show/hide the certificate textarea, save a real certificate, regenerate, confirm the "Valid until" date and expiry-warning coloring.
- [ ] `/server/{uuid}/log-drains` — enable each of the 3 providers (New Relic/Axiom/Custom) one at a time (mutually exclusive), confirm the log-drain service actually starts/stops on the server, not just the DB flag.
- [ ] `/server/{uuid}/resources` — confirm Managed table populates immediately and Unmanaged table populates a moment later (deferred prop); start/restart/stop an unmanaged container; confirm the list refreshes live when an application's status changes elsewhere (Echo).
- [ ] `/server/{uuid}/security/patches` — "Check for Updates" against a real server, confirm the update table renders; update a single package and confirm the log slide-over streams real output and the page's update list refreshes after it finishes (cross-tab broadcast — open the page in two tabs to confirm both refresh).
- [ ] `/server/{uuid}/cloudflare-tunnel` — manual-config toggle (typed confirmation), automated config form + its own log slide-over, disable flow (typed confirmation, confirm IP reverts if a previous IP exists).
- [ ] `/server/{uuid}/private-key` — "Use this key" on a different key, confirm it associates and "Currently used" moves to the new card; "Check connection" against a real server; the "+ Add" modal's Generate RSA/ED25519 buttons populate the form and public-key preview, then submit to create a new key without leaving the page.
- [ ] `/server/{uuid}/danger` — delete flow: blocked-by-resources message, the dynamic checkboxes (force-delete-resources/delete-from-Hetzner) appear only when applicable, typed confirmation + password, redirect to `/servers` after deletion.
- [ ] `/server/{uuid}/destinations` — "Scan for Destinations" against a real server, confirm found networks appear as "Add {name}" buttons; add one and confirm it moves into "Available Destinations"; the "+ Add" modal creates a destination directly (try picking a different server in the dropdown too).
- [ ] `/server/{uuid}/docker-cleanup` — save settings, toggle the instant-save checkboxes; "Trigger Manual Cleanup" against a real server, confirm a new execution appears in "Recent executions" (open in two tabs to confirm the Echo-driven refresh works in both — note there's no fallback poll anymore, so if Soketi is down the list won't self-update); click a running execution and confirm the log view polls every 2s until it finishes; download a log file; confirm the "Docker Cleanup May Be Stalled" callout appears when appropriate.
- [ ] `/server/{uuid}/cloud-provider-token` (only visible for servers provisioned through Hetzner) — "Use this token" on a different token; "Validate token" against a real Hetzner API token (both valid and invalid); the "+ Add" modal creates a token and validates it against Hetzner's API before saving.
- [ ] `/server/{uuid}/metrics` — **cold page load** (hard-refresh so the dynamically-loaded ApexCharts script isn't already cached) confirm both CPU and Memory charts actually render, not just a blank div; switch between "5 minutes (live)" and a longer static range and confirm live polling stops once you pick a static range; "Enable/Disable Metrics" against a real server with Sentinel running.
- [ ] `/server/{uuid}/proxy` — **cold page load** (hard-refresh so the dynamically-loaded Monaco editor script isn't already cached) confirm the YAML editor actually renders, not a blank div, and its theme matches the current dark/light mode (toggle dark mode with the editor open and confirm it flips live); select a proxy type on a server with none set; "Switch Proxy" while the proxy container is running (confirm the blocked toast) and while stopped (confirm it resets to the selection screen); save a configuration change; "Reset Configuration" (typed server-name confirmation); dismiss a Traefik outdated-version warning and confirm it stays dismissed on reload (`localStorage`).
- [ ] `/server/{uuid}/sentinel` — Enable Sentinel on a build server (confirm the "cannot be enabled on build servers" error, no restart triggered) and on a normal server (confirm it actually starts); Save the Coolify URL/token/metrics fields against a real server and confirm Sentinel actually restarts afterward (the settings-save cascade described in Section 53 of the migration doc); Regenerate token and confirm the token field updates and Sentinel restarts; Restart/Sync button against a live server; dev-only debug checkbox and custom Docker image override (only if `APP_ENV=local`).
- [ ] `/server/{uuid}/proxy/dynamic` — "Reload" against a real server, confirm the file list refreshes; "+ Add" modal creates a new dynamic configuration file (Monaco editor for the content) and it appears in the list; "Edit" on an existing file opens the same modal pre-filled, saves correctly; "Delete" removes a file (confirm `Caddyfile` itself can't be deleted when using Caddy); confirm the fixed/reserved filenames (`coolify.yaml`, `Caddyfile`, etc.) render as plain read-only textareas without Edit/Delete controls; open the page in two tabs, change the proxy elsewhere, confirm both tabs' file list auto-refreshes via the Echo `ProxyStatusChangedUI` listener without a manual reload.

Git-based creation flows (`/project/.../new/git?type=...`, Phase 52 — need real GitHub connectivity):

- [ ] `?type=public` — "Check repository" against a real public GitHub repo: rate-limit info renders, branch auto-detected (try a `/tree/<branch>` URL too), branch input stays disabled for github.com; a non-GitHub https URL gets `.git` appended and leaves the branch editable; submit creates the application and redirects to its configuration page.
- [ ] `?type=private-gh-app` — select a real GitHub App: repository list loads (check an account with >100 repos to exercise pagination), "Load Repository" fetches branches sorted main-first, submit creates the application; "Refresh Repository List" and the "Change Repositories on GitHub" installation link both work; "+ Add GitHub App" opens the in-page modal (Phase 53) and redirects to the new app's config on create.
- [ ] `?type=private-deploy-key` — key picker (empty state links to private-key creation), form submit converts a non-GitHub https URL to `git@host:owner/repo.git` and attaches the selected key.

## 5. Regression spot-check: still-Livewire areas

Not part of this migration, but worth a quick sanity check that nothing else broke:

- [ ] Dashboard loads, project list renders.
- [ ] Open a project → environment → application, confirm the (still-Livewire) Configuration tabs work.
- [ ] The remaining 4 of 21 `Server\Navbar`-dependent pages (Terminal command, `Server\Show`, plus Logs within Proxy and Logs within Sentinel) are still fully Livewire — confirm they still render and proxy status still updates live via Livewire's own Echo wiring (not the React `ServerNavbar`).
- [ ] Global search still finds things across both stacks.

## 6. Sign-off

Record here (or in a linked issue) once a full pass has been done: date, commit hash, who ran it, and any items that failed. An automated test suite passing is not the same claim as "confirmed working in a browser" — this file is what closes that gap.
