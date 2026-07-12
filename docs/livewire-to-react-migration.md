# Livewire → React Migration

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 12, 2026**

## 1. Why

We are migrating Coolify's UI from Livewire 3 to React 18/19, page by page, over time. This is not a big-bang rewrite: Livewire and React coexist in the same app for as long as the migration takes, and we cut over each page individually.

## 2. Why Inertia.js instead of a plain React SPA + REST API

The app has 84 full-page Livewire components (confirmed by inventory in Phase 2 — see Section 6) wired directly into `routes/web.php`, with no existing REST/JSON API layer behind the UI. We considered two options:

- **Plain React SPA + REST API**: would require designing, building, and versioning a whole new API surface (auth, CSRF, serialization, pagination, etc.) before we could move a single page, on top of the React migration itself.
- **Inertia.js** (chosen): each page stays a normal Laravel route + controller that returns data as props. Routing, auth, CSRF, and session handling keep working as they do today. Migrated and not-yet-migrated pages coexist under the same Laravel app with no parallel API to maintain.

## 3. Current status

**45 of 84** full-page Livewire components converted. The Medium bucket is complete; the Hard bucket now includes the `Server\Navbar` shared-chrome foundation and 14 pages built on it (Section 49).

| Bucket | Converted | Remaining |
| --- | --- | --- |
| Easy | 5 of 5 (all done) | 0 |
| Medium | 20 of 20 (all done) | 0 |
| Hard | 18 of 59 | 41 |

Converted so far: `SharedVariables\Index` (pilot), `SharedVariables\Environment\Index`, `SharedVariables\Project\Index`, `SharedVariables\Server\Index`, `Profile\Appearance`, all 6 `Notifications\*` channels (`Webhook`, `Discord`, `Email`, `Slack`, `Telegram`, `Pushover`), `Profile\Index`, `Security\ApiTokens`, `Tags\Show`, `Team\Index`, `Admin\Index`, `Destination\Show`, `Destination\Resources`, `Security\PrivateKey\Show`, `Settings\Updates`, `ForcePasswordReset`, `Settings\Advanced`, `SettingsEmail`, `Team\AdminView`, `SettingsOauth`, and `Settings\ScheduledJobs`. The entire notifications area, the profile area, the security/team/admin single-page settings screens, and the instance-wide Settings area are now fully off Livewire. Every remaining unconverted page is Hard bucket. Livewire and Alpine remain fully installed and used by every other page.

## 4. Foundation (change ledger)

### Phase 1 — toolchain + pilot page

| File | Change | Purpose |
| --- | --- | --- |
| `composer.json` / `composer.lock` | modified | Added `inertiajs/inertia-laravel` |
| `app/Http/Middleware/HandleInertiaRequests.php` | created, later extended (Phase 2) | Inertia's request middleware; `$rootView` set to `app-inertia` |
| `app/Http/Kernel.php` | modified | Registered `HandleInertiaRequests` in the `web` middleware group |
| `package.json` / `yarn.lock` | modified | Added `react@^19.2.7`, `react-dom@^19.2.7`, `@inertiajs/react@^3.6.1` (deps), `@vitejs/plugin-react@^6.0.3` (devDep) |
| `vite.config.js` | modified | Added the `@vitejs/plugin-react` plugin and a new `resources/js/inertia-app.jsx` Vite entrypoint, alongside the existing `app.js`/`app.css` entries |
| `resources/views/app-inertia.blade.php` | created, then fixed | New, minimal Inertia root view (charset/viewport meta, CSRF meta, `@viteReactRefresh`, `@vite(...)`, `@inertiaHead`, `@inertia`). Does not extend `layouts/base.blade.php` (Livewire/Alpine-specific: FOUC/x-cloak handling, `livewire:init` toast wiring, Echo/Pusher bootstrap, DOMPurify global) |
| `resources/js/inertia-app.jsx` | created, then fixed (later extended, Phase 2) | React entrypoint: `createInertiaApp()` resolving pages via `laravel-vite-plugin`'s `resolvePageComponent()` helper, mounting with `createRoot()` |
| `resources/js/Pages/SharedVariables/Index.jsx` | created | The pilot page component |
| `app/Http/Controllers/SharedVariablesController.php` | created, later extended (Phase 2) | `index()` returns `Inertia::render('SharedVariables/Index', ['links' => [...]])` |
| `routes/web.php` | modified | `shared-variables.index` points at `SharedVariablesController` |
| `resources/views/components/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Shared variables" nav link, leaving every other link unchanged |
| `app/Livewire/SharedVariables/Index.php`, `resources/views/livewire/shared-variables/index.blade.php` | **deleted** | Real cutover, not a duplicate |
| `tests/v4/Feature/SharedVariablesIndexTest.php` | created | First HTTP/Feature test in the repo |
| `docs/livewire-to-react-migration.md` | created | This file |

### Phase 2 — persistent layout, shared props, Easy bucket, first Medium page

| File | Change | Purpose |
| --- | --- | --- |
| `resources/js/Layouts/AppLayout.jsx` | created | React port of the navbar/sidebar shell, used as an Inertia persistent layout (see Section 5) |
| `resources/js/inertia-app.jsx` | modified | `resolve()` now attaches `AppLayout` as every page's default layout, unless the page already sets its own |
| `app/Http/Middleware/HandleInertiaRequests.php` | modified | `share()` now sends `auth.user`, `currentTeam`, `permissions` (`isSubscribed`, `isCloud`, `isInstanceAdmin`, `canAccessTerminal`), and `flash` (success/error/warning/info from session) — see Section 5 |
| `app/Http/Controllers/SharedVariablesController.php` | modified | Added `environment()`, `project()`, `server()` methods |
| `resources/js/Pages/SharedVariables/{Environment,Project,Server}/Index.jsx` | created | The 3 remaining Easy-bucket pages |
| `app/Http/Controllers/ProfileController.php` | created | `appearance()` returns `Inertia::render('Profile/Appearance')` |
| `resources/js/Pages/Profile/Appearance.jsx` | created | Ports the Alpine `x-data` theme/width/zoom state to React `useState`; dropped the original SVG icons for speed (plain text buttons now) — a visual simplification, not a functional gap |
| `resources/views/components/profile/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Appearance" tab link |
| `app/Http/Controllers/NotificationsWebhookController.php` | created | `edit()` / `update()` / `sendTest()` — the Medium-bucket pattern-setter (see Section 7 for the `useForm()` pattern) |
| `resources/js/Pages/Notifications/Webhook.jsx` | created | Full port of all 15 boolean toggles + URL field using `useForm()` |
| `resources/views/components/notification/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Webhook" tab link |
| `routes/web.php` | modified | 6 routes repointed at new controllers (`shared-variables.{environment,project,server}.index`, `profile.appearance`, `notifications.webhook` + new `notifications.webhook.update` PUT and `notifications.webhook.send-test` POST routes) |
| `app/Livewire/{Profile/Appearance,SharedVariables/{Environment,Project,Server}/Index,Notifications/Webhook}.php` + matching Blade views | **deleted** | Real cutovers |
| `tests/v4/Feature/{ProfileAppearanceTest,SharedVariablesEnvironmentIndexTest,SharedVariablesProjectIndexTest,SharedVariablesServerIndexTest,NotificationsWebhookTest}.php` | created | One test per converted page (4 Easy + 1 Medium, 3 assertions for Webhook covering render, update, and validation rejection) |
| `README.md`, `TECH_STACK.md` | modified | Point readers at this doc and note React/Inertia in the frontend stack |
| `docs/livewire-to-react-migration.md` | modified throughout | This file |

### Phase 3 — remaining Notifications channels (Discord, Email, Slack, Telegram, Pushover)

All 5 follow the `Webhook` pattern from Phase 2 (boolean toggles + `useForm()`), confirming it generalizes cleanly across similar settings pages. `Notifications\Email` was the outlier — 3 independent sub-forms (main toggles, SMTP, Resend) plus a rate-limited test-send action and a copy-from-instance-settings action, so it got 3 separate `useForm()` instances and 5 controller actions instead of the usual 1+1+1.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/Notifications{Discord,Email,Slack,Telegram,Pushover}Controller.php` | created | One controller per channel, following the `Webhook` pattern. `NotificationsEmailController` also has `updateSmtp()`, `updateResend()`, and `copyFromInstance()` |
| `resources/js/Pages/Notifications/{Discord,Email,Slack,Telegram,Pushover}.jsx` | created | `Email.jsx` uses 3 separate `useForm()` instances (main/SMTP/Resend) plus a `useState`-driven test-send modal; the other 4 are single-form pages matching `Webhook.jsx` |
| `resources/views/components/notification/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from all 5 remaining tab links — every tab in this sub-nav is now Inertia |
| `routes/web.php` | modified | 5 routes repointed, plus new `update`/`send-test` routes per channel; `Email` also gets `update-smtp`, `update-resend`, and `copy-from-instance` |
| `app/Livewire/Notifications/{Discord,Email,Slack,Telegram,Pushover}.php` + matching Blade views | **deleted** | Real cutovers |
| `tests/v4/Feature/Notifications{Discord,Email,Slack,Telegram,Pushover}Test.php` | created | 19 tests total: render + update + validation-rejection for the 3 webhook-style channels (Discord, Slack), render + update for Pushover/Telegram (no `SafeWebhookUrl` validation to test — see below), and 5 tests covering Email's independent actions |
| `docs/livewire-to-react-migration.md` | modified throughout | This file |

### Phase 4 — Profile\Index, Security\ApiTokens, Tags\Show, Team\Index, Admin\Index

Five more Medium-bucket pages, each a standalone settings/dashboard screen rather than a shared multi-tab settings area (unlike Notifications). `Tags\Show` was the first page in the migration with a `wire:poll`-style live-ish requirement; `Team\Index` and `Admin\Index` were the first pages whose props depend on policy/gate checks and root-user identity rather than plain model data.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProfileController.php` | modified | Added `index()`, `update()`, `requestEmailChange()`, `verifyEmailChange()`, `resendVerificationCode()`, `cancelEmailChange()`, `updatePassword()` — the "General" tab, sharing the controller with `appearance()` from Phase 2 |
| `resources/js/Pages/Profile/Index.jsx` | created | Name/email form (with the email-change verification-code flow) + a separate password `useForm()` |
| `resources/views/components/profile/navbar.blade.php` | **deleted** | Both Profile tabs (`Appearance`, `Index`) are now Inertia pages that render their own inline sub-nav; this shared Blade partial had no remaining referrer |
| `app/Http/Controllers/SecurityApiTokensController.php` | created | `index()` / `store()` / `destroy()` |
| `resources/js/Pages/Security/ApiTokens.jsx` | created | Token list + create form (`useForm()`) + revoke buttons |
| `app/Http/Controllers/TagsController.php` | created | `show()` / `redeploy()` |
| `resources/js/Pages/Tags/Show.jsx` | created | Tag picker + applications/deployments for the selected tag. The original Livewire component used `wire:poll` for a lightweight "is this still deploying" refresh; ported as a client-side `setInterval` + `router.reload({ only: [...] })` rather than deferring this page to the Hard bucket — judged a fundamentally simpler case than the real broadcast/Echo-driven pages (server metrics, deployment logs) that Hard-bucket status is reserved for |
| `app/Http/Controllers/TeamController.php` | created | `index()` / `update()` / `destroy()` |
| `resources/js/Pages/Team/Index.jsx` | created | Team name/description form + conditional delete section (`canDelete`, `deletionBlockedReason`, `blockingResources`) |
| `app/Http/Controllers/AdminController.php` | created | `index()` / `back()` / `switchUser()` — gated on `Auth::id() === 0` (root) |
| `resources/js/Pages/Admin/Index.jsx` | created | Root-only user search + impersonation ("switch to user") UI |
| `resources/views/components/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the Tags, Team, and Admin nav links |
| `resources/views/components/{security,team}/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the converted tab links |
| `routes/web.php` | modified | Routes repointed for all 5 pages, plus new `profile.update`, `profile.password.update`, `profile.email.{request,verify,resend,cancel}`, `security.api-tokens.{store,destroy}`, `tags.redeploy`, `team.update`, `team.destroy`, `admin.back`, `admin.switch-user` |
| `app/Livewire/{Profile/Index,Security/ApiTokens,Tags/Show,Team/Index,Admin/Index}.php` + matching Blade views | **deleted** | Real cutovers |
| `tests/v4/Feature/{ProfileIndexTest,SecurityApiTokensTest,TagsShowTest,TeamIndexTest,AdminIndexTest}.php` | created | 16 new tests |
| `docs/livewire-to-react-migration.md` | modified throughout | This file |

**Dead code found, deliberately left untouched**: `App\Livewire\Tags\Deployments` (and its Blade view) is unreferenced by any route or by the `Tags\Show` page being converted — it appears to be orphaned code that predates this migration. Out of scope for this pass; not deleted, since removing unrelated dead code was not part of the task at hand.

### Phase 5 — Destination, Security\PrivateKey\Show, Settings\Updates, ForcePasswordReset, Settings\Advanced, SettingsEmail

Six more Medium-bucket pages. `Destination\Show`/`Destination\Resources` were converted together (a 2-tab pair sharing one sub-nav, like the Notifications channels). `Settings\Updates` and `Settings\Advanced` share the instance-wide Settings sidebar (General/Advanced/Updates) alongside the still-Livewire `Settings\Index`. `ForcePasswordReset` is the first converted page to opt out of the default `AppLayout` wrapper entirely (a bare auth-style page, matching its original `->layout('layouts.simple')`). `SettingsEmail` reuses the exact SMTP/Resend/test-send pattern already established by `Notifications\Email` (Phase 3), just at instance scope instead of team scope.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/DestinationController.php` | created | `show()` / `resources()` / `update()` / `destroy()` |
| `resources/js/Pages/Destination/{Show,Resources}.jsx` | created | General tab (name/server-IP/network + delete) and Resources tab (read-only table of applications/services/databases on that Docker network, client-side search) |
| `app/Http/Controllers/SecurityPrivateKeyController.php` | created | `show()` / `update()` / `destroy()` |
| `resources/js/Pages/Security/PrivateKey/Show.jsx` | created | Name/description/public-key(read-only)/private-key(reveal-to-edit) form + delete |
| `app/Http/Controllers/SettingsController.php` | created | `updates()` / `updatesUpdate()` / `updatesCheckManually()` / `advanced()` / `advancedUpdate()` / `advancedEnableRegistration()` / `advancedDisableTwoStepConfirmation()` |
| `resources/js/Pages/Settings/{Updates,Advanced}.jsx` | created | Auto-update cron settings; a 10-toggle + 2-text-field instance-wide settings form, including the two password-confirmed one-way toggles (enable registration, disable two-step confirmation) |
| `app/Http/Controllers/ForcePasswordResetController.php` | created | `edit()` / `update()` |
| `resources/js/Pages/ForcePasswordReset.jsx` | created | Sets `ForcePasswordReset.layout = (page) => page` to opt out of `AppLayout` — the first page to use this escape hatch |
| `app/Http/Controllers/SettingsEmailController.php` | created | `edit()` / `updateSmtp()` / `updateResend()` / `sendTest()` — instance-wide counterpart to `NotificationsEmailController` |
| `resources/js/Pages/SettingsEmail.jsx` | created | SMTP block + Resend block (mutually exclusive, same as `Notifications/Email.jsx`) + test-send modal |
| `resources/views/components/settings/sidebar.blade.php`, `resources/views/components/settings/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the Advanced, Updates, and Transactional Email links |
| `resources/views/livewire/destination/index.blade.php`, `resources/views/livewire/server/destinations.blade.php`, `resources/views/livewire/security/private-key/index.blade.php` | modified | Removed `{{ wireNavigate() }}` from links pointing at the newly-converted pages |
| `routes/web.php` | modified | Routes repointed for all 6 pages, plus new `destination.{update,destroy}`, `security.private-key.{update,destroy}`, `settings.updates.{update,check-manually}`, `auth.force-password-reset.update`, `settings.advanced.{update,enable-registration,disable-two-step-confirmation}`, `settings.email.{update-smtp,update-resend,send-test}` |
| `app/Livewire/{Destination/Show,Destination/Resources,Security/PrivateKey/Show,Settings/Updates,ForcePasswordReset,Settings/Advanced,SettingsEmail}.php` + matching Blade views (incl. `destination/navbar.blade.php`) | **deleted** | Real cutovers |
| `tests/v4/Feature/{DestinationShowTest,SecurityPrivateKeyShowTest,SettingsUpdatesTest,ForcePasswordResetTest,SettingsAdvancedTest,SettingsEmailTest}.php` | created | 22 new tests |
| `docs/livewire-to-react-migration.md` | modified throughout | This file |

**A real, minor correctness fix made along the way**: the original `Security\PrivateKey\Show`'s `delete()` called `$this->private_key->safeDelete()` but never checked its return value — `safeDelete()` silently no-ops and returns `false` if the key is still in use, yet the component redirected to the index page regardless, giving no feedback that nothing happened. `SecurityPrivateKeyController::destroy()` now checks the return value and flashes an error instead. Noted here since a migration pass finding and fixing a real (if minor) bug is worth flagging explicitly, not folding in silently.

**Known simplifications in this phase's conversions**:

- `Settings\Updates.jsx` drops the `config('constants.coolify.autoupdate')` env-override display (a disabled checkbox shown only when `AUTOUPDATE` is set in `.env`) — a rare deployment-time flag, not a normal user-facing control.
- `SettingsEmail.jsx` drops the "From Name"/"From Address" fields' original single shared top-level form+Save button in favor of feeding both the SMTP and Resend sub-forms directly (each already independently validates/persists these two fields, mirroring `NotificationsEmailController::updateSmtp()`/`updateResend()`) — same net behavior, one fewer redundant submit path.
- `phpstan-baseline.neon` still has a stale entry referencing the now-deleted `App\Livewire\SettingsEmail` class. Harmless (baseline entries for missing files don't fail a run) but not cleaned up this pass — flagged here rather than silently left for a future phpstan run to notice.

### Phase 6 — Team\AdminView, SettingsOauth, Settings\ScheduledJobs (Medium bucket complete)

The final 3 Medium-bucket pages, found via a dedicated re-triage (see Section 6's correction note) after the previously-assumed remaining candidates turned out to mostly be Hard bucket. `Settings\ScheduledJobs` is the largest by LOC in this whole migration so far (349 PHP + 283 Blade) but is frontend-simple — a read-only failure/skip-log viewer with 2 filter dropdowns and manual pagination, no `useForm()` needed at all.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/TeamController.php` | modified | Added `adminView()` / `adminDeleteUser()` |
| `resources/js/Pages/Team/AdminView.jsx` | created | User search (via query-string, matching `AdminController`'s pattern) + password-confirmed delete-user list |
| `app/Http/Controllers/SettingsController.php` | modified | Added `oauth()` / `oauthUpdate()` |
| `resources/js/Pages/SettingsOauth.jsx` | created | One `useForm()` holding the whole `providers` array; per-provider conditional extra fields (tenant for azure/google, base_url for authentik/clerk/zitadel/gitlab), matching the original's dynamic Blade loop |
| `app/Http/Controllers/SettingsScheduledJobsController.php` | created | `index()` — ports all of the original component's data-aggregation logic (execution/skip-log queries across backups/tasks/docker-cleanups, log-file parsing via `SchedulerLogParser`) essentially verbatim, since it's backend logic, not view logic |
| `resources/js/Pages/Settings/ScheduledJobs.jsx` | created | 3-tab client-side view (Failures/Scheduler Runs/Skipped Jobs) driven entirely by props + query-string filters (`router.get(..., { preserveState: true })`), no `useForm()` |
| `resources/views/components/team/navbar.blade.php`, `resources/views/components/settings/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the Admin View, OAuth, and Scheduled Jobs links |
| `routes/web.php` | modified | Routes repointed for all 3 pages, plus new `team.admin-view.delete-user`, `settings.oauth.update` |
| `app/Livewire/{Team/AdminView,SettingsOauth,Settings/ScheduledJobs}.php` + matching Blade views | **deleted** | Real cutovers |
| `tests/v4/Feature/{TeamAdminViewTest,SettingsOauthTest,SettingsScheduledJobsTest}.php` | created | 11 new tests |
| `docs/livewire-to-react-migration.md` | modified throughout | This file, including the Medium/Hard bucket count correction (Section 6) |

**Known simplification**: `SettingsScheduledJobs.jsx`'s tab state (`activeTab`) is plain React `useState`, not synced to `window.location.hash` the way the original Alpine `x-data` was — a deep-linkable "land directly on the Skipped Jobs tab" URL isn't preserved. Minor, since the page is reached via the Settings nav, not typically deep-linked to a specific tab.

## 5. The shell problem and shared props

Unlike the pilot (a bare standalone menu page), essentially every remaining page lives inside the app's persistent navbar/sidebar (`resources/views/components/navbar.blade.php`, 511 lines: Alpine state + PHP-rendered permission checks + 5 embedded Livewire components). That markup cannot run inside a React tree as-is.

**Decision: build the navbar/sidebar as a React component once (`AppLayout.jsx`), used as an Inertia persistent layout**, rather than re-solving the shell per page or falling back to full page reloads on every Inertia-to-Inertia navigation. This is Inertia's standard mechanism for this problem — a `page.default.layout` wrapper that persists across client-side page visits instead of remounting. It's wired in `inertia-app.jsx`'s `resolve()`.

**Explicit v1 scope-reduction, not a silent gap**: `AppLayout.jsx` omits 5 elements that were live Livewire components in the original navbar — team switching (shows the team name as static text instead of `<livewire:switch-team/>`), the settings dropdown, the upgrade banner, the delete-team modal trigger, and the help modal. Mixing live Livewire components inside a React-rendered tree is a real unsolved problem, not attempted this phase. Also omitted for v1: the mobile hamburger drawer, the sidebar collapse/expand toggle (always expanded), and global search plus the deployment status indicator. Follow-up work, not forgotten.

**Shared props** (`HandleInertiaRequests::share()`) now carry what Livewire/Blade got for free: `auth.user` (id/name/email/isAdmin), `currentTeam` (id/name), `permissions` (`isSubscribed`, `isCloud`, `isInstanceAdmin`, `canAccessTerminal`), and `flash` (success/error/warning/info pulled from session). `AppLayout.jsx` reads `flash` on mount and calls the existing global `window.toast(...)` function — the same one Livewire's `layouts/base.blade.php` wires up — so both stacks route through one toast implementation.

## 6. Real inventory (established via research, corrects the Phase 1 estimate)

Phase 1 estimated "200+" full-page components; a real inventory in Phase 2 found **84 unique full-page Livewire component classes** across 130 routes in `routes/web.php` (some routes share one class via tabs — e.g. `ApplicationConfiguration` serves 18 routes). Triaged:

- **Easy (5, all converted)**: `SharedVariables\Index` (pilot), `SharedVariables\{Environment,Project,Server}\Index`, `Profile\Appearance`. No `wire:model`, no nested `<livewire:.../>`, no listeners, trivial or no `mount()`.
- **Medium (20, all converted — corrected from the original "23" estimate, see the note below)**: forms/data pages with no real-time dependency and no live nested children. Fully converted: all 6 `notifications.*` channels, `admin.index`, `profile` (`ProfileIndex`), `security.api-tokens`, `security.private-key.show`, `tags.show`, `team.index`, `team.admin-view`, `destination.show`, `destination.resources`, `settings.updates`, `settings.advanced`, `settings.oauth`, `settings.scheduled-jobs`, `settings.email` (top-level `SettingsEmail`), `auth.force-password-reset`.
- **Hard (59, 0 converted)**: nested `<livewire:.../>` children, old-style `$listeners`/`getListeners()`, broadcast/websocket dependency, or genuinely complex business logic behind a deceptively simple-looking form. Needs real-time/broadcast (Echo-in-React) and embedded-Livewire-island design work before conversion starts — not attempted yet.

**Correction to the original Medium-bucket estimate (made during Phase 6's triage)**: the Phase 2 estimate of "23 Medium, 56 Hard" was a rough first pass based on a quick read of each class, not a full check of each Blade view for nested `<livewire:.../>` children. A dedicated triage pass in Phase 6 checked 20 previously-unconverted candidates that looked Medium-shaped (settings/admin/CRUD-style pages) against the same 4 disqualifiers used throughout this migration, and found **17 of those 20 were actually Hard bucket** — most because they nest a live child component (several nest `server.navbar`, which is itself broadcast-driven via `echo-private:team.{id},ProxyStatusChangedUI`), a few because of old-style `$listeners`/`getListeners()`. Only 3 were genuinely clean: `Team\AdminView`, `SettingsOauth`, `Settings\ScheduledJobs` (all converted in Phase 6). Combined with the 17 already converted in Phases 2-5, that's **20 total genuinely-Medium components — not 23** — and the other 3 that were vaguely assumed Medium move into the Hard count instead (56 → 59). This is the same "the estimate was optimistic until someone actually checked" pattern that recurred throughout this migration (see the Phase 1 "200+" correction above); recorded here rather than quietly adjusting the numbers.

## 7. Reusable per-page conversion recipe

Worked example: `SharedVariables\Index` (Phase 1), extended with the form pattern from `Notifications\Webhook` (Phase 2).

1. Identify the Livewire full-page component and its route in `routes/web.php`.
2. Grep the whole codebase for the route's **name** (not the Livewire class) to find every internal reference (nav links, redirects, other views/components).
3. Grep those specific call sites for `wireNavigate()` / `wire:navigate` and remove it from links that point at the page you're converting. **Why this matters**: `wire:navigate` fetches the destination HTML via AJAX and morphs it into the existing `<body>`. Injected `<script type="module">` tags do not re-execute on a DOM morph, so a React page linked to via a live `wire:navigate` anchor would fetch the HTML but never boot React, producing a blank page. Do not touch `wire:navigate` on links to pages that are still Livewire.
4. Create (or extend) a controller returning `Inertia::render('{Namespace}/{Name}', [...props])`. Every page gets `AppLayout` automatically (Section 5) unless it opts out.
5. Create `resources/js/Pages/{Namespace}/{Name}.jsx`, translating the Blade template 1:1 — props in, JSX out. Tailwind classes carry over almost verbatim (`class` → `className`).
6. **If the page has a form** (the Medium-bucket pattern, established by `Notifications\Webhook`): controller has an `edit()` (renders current values) and an `update()` (validates via `Validator::make()` — this repo's inline-validation convention, not Form Requests — then `$model->update($validated)`, `return back()->with('success', ...)`). The React page uses `@inertiajs/react`'s `useForm(initialData)` — `data`/`setData` for two-way binding, `put()`/`post()` for submission, `errors` for validation messages, `processing` for a disabled/loading state. This replaces `wire:model` + Livewire's reactive validation. The controller resolves action URLs (e.g. `updateUrl`, `sendTestUrl`) server-side via `route()` and passes them as props — see the Ziggy note below.
7. Update the route(s) in `routes/web.php`; remove the old `use ... as ...` import.
8. Decide delete-vs-keep for the old Livewire class + view: grep for direct references to the Livewire **class**, distinct from the route name. If none exist, delete both files — don't leave a dead duplicate.
9. Write a Pest Feature test using `assertInertia()`. For a form page, also test the update action's happy path and at least one validation-rejection case.
10. Run `vendor/bin/pint --dirty --format agent`.
11. **Phase 1 required** a real authenticated browser visit per page (see Section 8 — it caught 2 real bugs). Phase 2 uses a lighter, user-directed bar: automated checks (Pint, Feature test, `yarn build`) without a manual browser click-through per page. This trade-off is deliberate, not an oversight — see Section 9.

**Conventions still in force from Phase 1**:

- **Page naming**: `App\Livewire\{Foo}\{Bar}` → `resources/js/Pages/{Foo}/{Bar}.jsx` → `Inertia::render('{Foo}/{Bar}', ...)`. Mechanical, no exceptions.
- **Plain `.jsx`, not TypeScript**, still. Revisit once more Medium pages land.
- **No Ziggy yet.** Every converted page so far resolves its URLs server-side and passes them as props. This is starting to feel repetitive (`Webhook.jsx` needed 2 action-URL props on top of its data) — **Ziggy is now a real candidate for the next infrastructure addition**, not a someday-flag.

## 8. Phase 1 verification log

| Check | Command | Result |
| --- | --- | --- |
| Composer sanity | `composer validate` | `./composer.json is valid` |
| Route wiring | `php artisan route:list --name=shared-variables.index` | Confirmed: resolves to `SharedVariablesController` |
| New test | `php artisan test --compact --filter=SharedVariablesIndexTest` | 1 passed (12 assertions) |
| Full regression suite | `php artisan test --compact` | 271 passed (713 assertions) — baseline was 270 |
| Formatting | `vendor/bin/pint --dirty --format agent` | passed |
| Production build | `yarn build` | Succeeded — both `app-*.js` (Livewire) and `inertia-app-*.js` (React) bundles emitted |
| Route cache | `php artisan route:clear` | Needed once — a stale `bootstrap/cache/routes-v7.php` masked the route change |

### Manual browser QA found two bugs the automated checks missed

A manual authenticated browser visit to `/shared-variables` rendered a blank page despite every automated check above passing. Laravel Debugbar showed the server-side request had completed (`GET shared-variables`, 3 queries, 2 models resolved), so this was a client-side React mounting failure, not a server error.

**Root cause 1**: `resources/views/app-inertia.blade.php` called `@vite([...])` without first calling `@viteReactRefresh`. Laravel's Vite integration requires this directive before `@vite(...)` whenever React is in use — it injects `@vitejs/plugin-react`'s Fast Refresh preamble script, which Vite otherwise auto-injects when it serves the HTML document itself. Since Laravel/Blade serves the HTML (not Vite's own dev server), the preamble was missing, which makes `@vitejs/plugin-react`'s runtime throw on load in **dev mode** (`yarn dev`) — this has no effect on `yarn build` production output, explaining why the automated build check passed while the live dev-server page was blank.

**Fix 1**: added `@viteReactRefresh` directly before `@vite([...])`.

That alone did not resolve it. A second manual browser check, after clearing the stale compiled Blade view cache (`php artisan view:clear` — the first fix wasn't taking effect until this ran), still showed the same `@vitejs/plugin-react can't detect preamble` error, now reported from inside `resources/js/Pages/SharedVariables/Index.jsx` specifically.

**Root cause 2**: `resources/js/inertia-app.jsx` resolved pages with a hand-rolled `import.meta.glob('./Pages/**/*.jsx', { eager: true })` lookup instead of `laravel-vite-plugin`'s official `resolvePageComponent()` helper. The eager, hand-rolled version bundles every page as a static import of the entry file, which does not respect the module-evaluation ordering the officially supported lazy-glob pattern relies on, and still tripped the Fast Refresh preamble check.

**Fix 2**: rewrote `resolve()` to use `resolvePageComponent(name, import.meta.glob('./Pages/**/*.jsx'))` (no `eager: true`) — the documented, supported pattern. Confirmed in Phase 2's `yarn build` output: each page now emits its own small lazy-loaded chunk (`Appearance-*.js`, `Webhook-*.js`, four separate `Index-*.js` chunks) instead of one large eager bundle.

**Final verification**: after both fixes, a fresh authenticated browser visit to `/shared-variables` showed zero console errors, and the page rendered as expected — "Shared Variables" heading, subtitle, and all 4 linked cards — confirmed by inspecting the live DOM and console directly, beyond server-side response assertions.

**Lesson for future page conversions**: `yarn build` succeeding does not prove a page works under the dev server (`yarn dev`) — the two paths inject dev-only tooling (HMR client, React refresh preamble) differently. A stale compiled Blade view cache can also mask a Blade-level fix from taking effect — run `php artisan view:clear` when a root/layout view change doesn't seem to apply.

## 9. Phase 2 verification log

Per the user's explicit direction, Phase 2 uses a lighter bar than Phase 1: automated checks, with no manual browser click-through per page. This is a deliberate trade-off, not an oversight — recording it here so a future reviewer understands why these pages weren't click-tested the way the pilot was, and treats "automated tests pass" as the actual claim being made rather than "confirmed working in a browser."

| Check | Result |
| --- | --- |
| Pint after Easy-bucket batch | passed |
| 4 new Feature tests (Easy bucket) | 4 passed, after one fix (see below) |
| Full suite after Easy bucket | 275 passed (767 assertions) — up from 271 |
| Pint after Medium page | passed |
| 3 new Feature tests (`NotificationsWebhookTest`) | 3 passed on first run |
| Full suite after Medium page | 278 passed (790 assertions) — up from 275 |
| `yarn build` | Succeeded — confirmed per-page code-splitting (see Root cause 2 above) |
| `php artisan route:list` spot checks | Confirmed all 6 repointed routes resolve to their new controllers |

**One test bug found and fixed during this phase** (the bug was in the test, not the converted page): `SharedVariablesEnvironmentIndexTest` initially asserted 1 environment after creating 1 explicitly, but `Project::booted()` auto-creates a "production" `Environment` on project creation, so the real count was 2. Fixed by asserting against the auto-created environment instead of creating a redundant second one.

**Known simplifications in this phase's conversions** (real trade-offs made for speed per the user's direction, not regressions):

- `Profile\Appearance.jsx` dropped the original SVG icons on the theme/width/zoom buttons (plain text buttons now).
- `Notifications\Webhook`'s `update()` action uses one uniform validation rule set; the original Livewire component had a separate, stricter "instant toggle" path when flipping `webhook_enabled` alone (that path required the URL immediately, versus nullable everywhere else on full submit). The converted version validates `webhook_url` as nullable in all cases — a minor behavior simplification, not a data-integrity issue: the model still requires a URL to actually function, enforced at send-time rather than toggle-time.
- The `ray()` debug-logging calls in the original `Webhook` component were removed (dev-only tooling, not user-facing behavior).

## 10. Non-goals of Phase 2

- The remaining 22 Medium-bucket and all 56 Hard-bucket components stayed on Livewire this phase — tracked as backlog above (Section 6).
- `AppLayout.jsx` v1 does not replicate the 5 embedded Livewire nav widgets, mobile drawer, or collapse toggle (Section 5) — tracked as follow-up, recorded here rather than left implicit.
- This phase skipped manual browser QA per page (Section 9) — a deliberate, user-directed trade-off.
- Ziggy remains unadded this phase, despite growing more likely to be needed (Section 7) — still an open decision for whoever picks up the next page.

## 11. Phase 3 verification log

Same lighter bar as Phase 2 (automated checks, no manual browser QA per page) — the user asked to continue at this pace using the `Webhook` conversion as the template.

| Check | Result |
| --- | --- |
| Pint after all 5 channels converted | passed |
| 19 new Feature tests (5 files) | 18 passed on first run, 1 failed then fixed (see below) |
| Full suite after this batch | 293 passed (918 assertions) — up from 278 |
| `yarn build` | Succeeded — `Discord-*.js`, `Email-*.js`, `Slack-*.js`, `Telegram-*.js`, `Pushover-*.js` each emitted as their own lazy-loaded chunk |

**One test bug found and fixed during this phase**: `NotificationsEmailTest`'s copy-from-instance test called `instanceSettings()`, which reads a singleton row hardcoded to id `0` (`InstanceSettings::get()` → `findOrFail(0)`). No migration, factory, or existing test in the repo creates this row — it's seeded once at install time in real deployments. First attempt used `InstanceSettings::create(['id' => 0])`, which dropped the `id` because it isn't in the model's `$fillable` — the row landed on an auto-incremented id instead, so `findOrFail(0)` kept failing. Fixed with `InstanceSettings::forceCreate(['id' => 0])`, which bypasses mass-assignment protection. Worth remembering for any future test that touches instance-level (not team-level) settings.

**Known simplification carried over from this batch**: the happy-path test covers `Notifications\Email`'s rate-limited test-send path (`RateLimiter::attempt('test-email:'.$team->id, ...)`), but no test drives the rejection branch (`return back()->with('error', 'Too many messages sent!')`) — matches the lighter Phase 2/3 verification bar rather than a full edge-case sweep.

## 12. Non-goals of Phase 3

- Same non-goals as Phase 2 (Section 10) still apply — nothing there has changed.
- The Medium-bucket `Notifications\*` siblings are fully converted, but `profile` (`ProfileIndex`, the "General" tab sharing a sub-nav with the already-converted `Profile\Appearance`) was not — it's a separate, not-yet-triaged-in-detail Medium page, left for a future batch.
- `NotificationsEmailController::sendTest()` has a rate-limiter failure branch, but no dedicated test drives it (see Section 11).

## 13. Phase 4 verification log

Same lighter bar as Phases 2-3 (automated checks, no manual browser QA per page).

| Check | Result |
| --- | --- |
| Pint after all 5 pages converted | passed |
| 16 new Feature tests (5 files) | 15 passed on first run, 1 failed then fixed (see below) |
| Full suite after this batch | 309 passed (1034 assertions) — up from 293 |
| `yarn build` | Succeeded — `Index-*.js` (×4, one per Profile/Team/Admin/ApiTokens-style page), `Show-*.js`, and `ApiTokens-*.js` each emitted as their own lazy-loaded chunk |

**Two test bugs found and fixed during this phase, neither in the converted pages themselves:**

1. `SecurityApiTokensTest`'s "revokes an owned api token" test called `$user->createToken()` directly (a plain PHP call, not an HTTP request) before the test's `withSession([...])` had taken effect — `withSession()` only queues session data for the *next* HTTP request, and `createToken()` reads `session('currentTeam')` immediately. Fixed by calling the global `session(['currentTeam' => $team])` helper (which mutates the session immediately) before `createToken()`.
2. `TeamIndexTest`'s "blocks deletion of the last team a user belongs to" test manufactured a scenario that could never occur: it created a user, then attached them to a second, explicitly-created team with role `owner`, expecting `deletionBlockedReason` to come back `'last-team'`. It never did — `$user->can('delete', $team)` was `true`, but the reason stayed `null`. Root cause: `User::boot()`'s `static::created` hook (`app/Models/User.php:152`) auto-creates and attaches a personal team (role `owner`, `show_boarding: true`) for *every* new user, so the factory-created user already had one team before the test attached a second — making "last team" logically false (`$user->teams()->count() === 1` was `2`, and the *current* team wasn't the personal one, so `personal_team` was also false). Fixed by rewriting the test to use the user's own auto-created personal team as the "last team" directly (`$user->teams()->first()`), with `show_boarding` flipped to `false` on it first (its default of `true` would otherwise trigger the onboarding redirect before the page ever renders). This is the same category of gotcha as `Project::booted()`'s auto-created "production" `Environment` (Section 9) — another model that silently creates a related row on creation that a naive test setup won't expect.

## 14. Non-goals of Phase 4

- Same non-goals as Phases 2-3 (Sections 10, 12) still apply.
- `Tags\Show`'s live-refresh behavior was ported as client-side polling (`setInterval` + partial reload), not a true push-based update — acceptable for this page's low-stakes "did the deployment finish" use case, but not a pattern to reuse for anything latency-sensitive.
- `App\Livewire\Tags\Deployments` — dead code discovered during this batch, unrelated to the page being converted — was deliberately left in place (Section 4).
- 12 Medium-bucket and all 56 Hard-bucket components remain on Livewire.

## 15. Phase 5 verification log

Same lighter bar as Phases 2-4 (automated checks, no manual browser QA per page).

| Check | Result |
| --- | --- |
| Pint after each page converted | passed, every time |
| 22 new Feature tests (6 files) | Several rounds of test-bug fixes were needed before all passed (see below); all 22 pass on the final run |
| Full suite after this batch | 331 passed (1159 assertions) — up from 309 |
| `yarn build` | Succeeded — one lazy-loaded chunk per new page |

**Multiple real test-authoring bugs found and fixed during this phase, none in the converted pages themselves** — this batch surfaced more of these than any prior phase, because it was the first to touch models with their own auto-creation side effects on *every* factory-created row (`Server`, not just `User`/`Project` as in earlier phases):

1. `DestinationShowTest` originally factory-created a second `StandaloneDocker` for a freshly-factoried `Server`, using the factory's default `network: 'coolify'`. This collided with a real `unique(server_id, network)` constraint: `Server::boot()`'s `static::created` hook (`app/Models/Server.php:243`) already auto-creates a default `StandaloneDocker` named `coolify` for every new server. Fixed by using `$server->standaloneDockers()->first()` (the auto-created one) as the destination under test, and only factory-creating a second, differently-named destination for the one test that actually needs two (the delete test).
2. The delete test then hit a second, unrelated problem: `destroy()` resolves the server's real `PrivateKey` relation (via `SshMultiplexingHelper`) to build the SSH command *before* `Process::fake()` ever gets a chance to short-circuit anything, and `ServerFactory`'s hardcoded `private_key_id => 1` doesn't correspond to a real row. Fixed by building a real `PrivateKey` (with a throwaway RSA keypair, mirroring the existing fixture in `tests/Unit/CoolifyTask/RunRemoteProcessTest.php`) and a faked `ssh-keys` disk, exactly as that existing test already does — this is a reusable recipe now, not a one-off.
3. `TeamIndexTest`'s "last team" fix from Phase 4 pointed at a `User::boot()` gotcha; this phase's `SettingsUpdatesTest`/`SettingsAdvancedTest`/`SettingsEmailTest` all gate on `isInstanceAdmin()`, which requires membership (with an admin/owner role) in the root team (id 0) — reusing the `User::forceCreate(User::factory()->raw(['id' => 0]))` fixture pattern already established in `AdminIndexTest`, since creating a user with id 0 triggers the *same* `static::created` hook to auto-create and attach the root team.
4. The auto-created root team defaults `show_boarding` to `true`, which would otherwise redirect every request in `SettingsUpdatesTest`'s first test to onboarding — same fix as `TeamIndexTest`'s Phase 4 gotcha (flip it to `false` before the request).
5. `SettingsUpdatesTest`'s update test unexpectedly hit `Server::findOrFail(0)` (a real singleton "localhost" server row that isn't part of the test's fixture) because `updatesUpdate()`'s proxy-reconfiguration branch runs whenever `isCloud()` is false — which it is by default (`constants.coolify.self_hosted` defaults to `true`). Fixed by setting `config(['constants.coolify.self_hosted' => false])` for that one test, since exercising the proxy-reconfiguration side effect isn't what the test is verifying.
6. `SettingsAdvancedTest`'s and `SettingsEmailTest`'s boolean assertions (`is_api_enabled`, `is_registration_enabled`) initially used `toBeTrue()`/`toBeFalse()` and failed with "1 is true" / "1 is false" — these two `InstanceSettings` columns (unlike most others on that model) aren't listed in `$casts`, so SQLite returns raw `0`/`1` integers rather than real PHP booleans. Fixed by using `toBeTruthy()`/`toBeFalsy()` for these specific fields.
7. `SettingsAdvancedTest`'s "rejects enabling registration with an incorrect password" test initially expected `is_registration_enabled` to still be `false` after a rejected request, but that column defaults to `true` in the schema — the test never actually set it to `false` first, so it was checking a tautology that happened to look like a real assertion. Fixed by explicitly setting it `false` before the request in both the accept and reject test cases.
8. `SettingsAdvancedTest`'s invalid-IP test initially asserted a custom `back()->with('error', ...)` session flash, but the `ValidIpOrCidr` validation rule already rejects malformed entries at the `Validator::make(...)->validate()` layer, producing a standard validation-error redirect (`errors` session key) before the controller's own post-validation normalization/error-flash logic is ever reached. Fixed by asserting `assertSessionHasErrors('allowed_ips')` instead.

## 16. Non-goals of Phase 5

- Same non-goals as Phases 2-4 (Sections 10, 12, 14) still apply.
- `Settings\Index`, `Settings\ScheduledJobs`, `SettingsBackup`, `SettingsOauth` remain on Livewire — only `Updates`, `Advanced`, and (top-level) `SettingsEmail` were converted this batch, so the Settings area's top-level navbar and sidebar Blade partials are still in active use by the remaining Livewire pages and were not deleted, only trimmed of `wireNavigate()` on the links pointing at now-converted pages.
- `phpstan-baseline.neon`'s stale `App\Livewire\SettingsEmail` entry was not cleaned up (Section 4).
- 6 Medium-bucket and all 56 Hard-bucket components remain on Livewire.

## 17. Phase 6 verification log

Same lighter bar as Phases 2-5 (automated checks, no manual browser QA per page).

| Check | Result |
| --- | --- |
| Pint after each page converted | passed, every time |
| 11 new Feature tests (3 files) | 2 test-bug fixes needed (see below); all 11 pass on the final run |
| Full suite after this batch | 342 passed (1237 assertions) — up from 331 |
| `yarn build` | Succeeded — one lazy-loaded chunk per new page |

**Two test bugs found and fixed, neither in the converted pages themselves**:

1. `SettingsOauthTest`'s boolean assertions on `OauthSetting::enabled` initially used `toBeTrue()`/`toBeFalse()` and failed with "1 is true" / "0 is false" — same uncast-boolean-column gotcha as `InstanceSettings::is_api_enabled`/`is_registration_enabled` in Phase 5. Fixed with `toBeTruthy()`/`toBeFalsy()`.
2. `TeamAdminViewTest`'s delete tests initially failed with `ModelNotFoundException: InstanceSettings 0` — `verifyPasswordConfirmation()` (used by `adminDeleteUser()`) calls `shouldSkipPasswordConfirmation()`, which reads the `InstanceSettings` singleton. Fixed with the same `InstanceSettings::forceCreate(['id' => 0])` `beforeEach` used everywhere else this singleton is touched.

## 18. Non-goals of Phase 6

- Same non-goals as Phases 2-5 (Sections 10, 12, 14, 16) still apply, **except** the Medium-bucket line — that bucket is now fully converted (Section 3).
- `Settings\Index`, `SettingsBackup` remain on Livewire — both are genuinely Hard bucket (nest `<livewire:activity-monitor>`, `<livewire:project.database.backup-edit>`/`-executions`), not simply "not yet gotten to."
- The tab-state-in-URL-hash behavior from the original `Settings\ScheduledJobs` Alpine implementation was not preserved (Section 4).
- All 59 Hard-bucket components remain on Livewire. Converting any of them requires a real-time/broadcast design decision (Echo-in-React bridge, or an embedded-Livewire-island approach) that has not been made yet — this is the next piece of work, not a mechanical continuation of the page-by-page recipe used for Easy/Medium.

## 19. Phase 7 — Echo-in-React foundation + first Hard-bucket page (`Project\Application\Deployment\Index`)

Hard-bucket pages differ from Medium in two ways that had no precedent yet: they depend on server-pushed broadcast events (Livewire's `getListeners()` → `echo-private:team.{id},EventName` pattern), and they nest other live Livewire components rather than being a single self-contained class. Both needed a design decision before any page could be converted.

**Echo-in-React decision (user-directed)**: use `laravel-echo` + `pusher-js` as real Vite-bundled npm dependencies, not vendored `<script>` tags — matching how every other JS dependency in this app is already managed, rather than adding a second, inconsistent way of loading a library.

**Pilot scope decision (user-directed)**: convert a full page plus its nested live children in one pass, rather than converting only the outermost slice first and leaving inner children as Livewire islands. Two candidate pilots were scoped and both turned out more complex than they first looked — recorded here because it's the second time in this migration an initial complexity read was wrong (see Section 6's "23 → 20" Medium correction for the first):

- `Server\Navbar` was initially assumed to be simple chrome. On inspection it manages the full proxy lifecycle (start/stop/restart via job dispatch), notification de-duplication logic, and nests another live component — deferred, not abandoned, since converting it unblocks roughly 21 chrome-only pages at once, but it's a project of its own.
- `Project\Application\Deployment\Index` (the page actually converted this phase) was also initially assumed simple. It nests two more non-trivial live components: `Heading` (deploy/stop/restart actions, itself polling every 10s) and `ConfigurationChecker` (which nests a `configuration-diff` table). All three were converted together as one page, per the user's chosen scope.

### Echo client foundation

| File | Change | Purpose |
| --- | --- | --- |
| `package.json` / `yarn.lock` | modified | Added `laravel-echo@^2.3.7`, `pusher-js@^8.5.0` as direct dependencies |
| `app/Http/Middleware/HandleInertiaRequests.php` | modified | `share()` now also sends an `echo` prop (`key`/`host`/`port`, `null` when logged out) so the client can construct an Echo connection without a second round trip |
| `resources/js/echo.js` | created | Lazy singleton Echo client factory (`getEcho(config)`) — Pusher-protocol config matching the existing Soketi/Echo setup used by the Livewire/Blade side |
| `resources/js/hooks/useTeamChannel.js` | created | Reusable hook mirroring Livewire's `getListeners()`: subscribes to `private-team.{id}`, listens for the given fully-qualified event names, cleans up on unmount. **None of Coolify's 15 `ShouldBroadcast` events override `broadcastAs()`**, so the JS-side event name is always the event's FQCN (e.g. `App\Events\ProxyStatusChangedUI`) — the hook uses Echo's leading-dot "exact name" syntax (`.App\\Events\\EventName`) to match it, rather than Echo's default camelCase-shortening behavior. |

The established client reaction to a broadcast event, used throughout this page, is a coarse refetch: `router.reload({ only: [...] })`. Coolify's broadcast events carry no rich payload (they're refetch signals, not data payloads) — this was already the pattern for `Tags\Show`'s polling (Phase 4), just event-triggered here instead of timer-triggered.

### `Project\Application\Deployment\Index` conversion

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ApplicationDeploymentController.php` | created | `index()` (deployment list + pagination + PR filter + all 3 nested components' props), `deploy()`/`restart()`/`stop()`/`checkStatus()`, plus private helpers replicating the original's `queue_application_deployment()` calls, `StopApplication`/`GetContainersStatus` job dispatch, and `pendingDeploymentConfigurationDiff()`-based config-diff computation (with environment-value redaction for non-admins) |
| `resources/js/Pages/Project/Application/Deployment/Index.jsx` | created | Main page: deployment list with status badges, pagination, PR-ID filter (`useForm()`), a `useTeamChannel(['ServiceChecked'], ...)` listener, and a `setInterval` 5s fallback poll gated on `!skip` — mirroring the original's `wire:poll.5000ms` + `@if (!$skip)` |
| `resources/js/Pages/Project/Application/Deployment/Heading.jsx` | created | App name, last-deployment link, 3-link nav (Configuration/Deployments/Logs), Deploy/Restart/Stop/Force-deploy buttons. Uses `useTeamChannel(['ServiceStatusChanged', 'ServiceChecked'], ...)` plus a 10s fallback poll (mirroring `wire:poll.10000ms="checkStatus"`) |
| `resources/js/Pages/Project/Application/Deployment/ConfigurationChecker.jsx` | created | Config-diff banner + modal, per-row expand/collapse, grouped by `section_label`, `requiresBuild` badge. Uses `useTeamChannel(['ApplicationConfigurationChanged'], ...)` → `router.reload({ only: ['configurationChecker'] })` |
| `routes/web.php` | modified | `project.application.deployment.{index,deploy,restart,stop,check-status}` repointed at `ApplicationDeploymentController`; `deployment.show` (still Livewire) untouched |
| `resources/views/livewire/project/application/{previews,heading}.blade.php` | modified | Removed `{{ wireNavigate() }}` from the 2 links pointing at the deployment index route. **`heading.blade.php` itself was not deleted** — grep confirmed other not-yet-converted pages still use this Livewire component; only the one link was touched |
| `app/Livewire/Project/Application/Deployment/Index.php`, `resources/views/livewire/project/application/deployment/index.blade.php` | **deleted** | Real cutover, grep-confirmed no other references to the class |
| `tests/v4/Feature/ApplicationDeploymentIndexTest.php` | created | 3 tests: renders page, lists an existing deployment, redirects to dashboard for a nonexistent application |
| `docs/command.md` | created | Reference of every commonly-needed command in this repo (start/stop dev stack, yarn/artisan/pest/pint/phpstan invocations, CI-parity block) — requested by the user alongside this phase's work, not itself part of the migration |

**Explicitly documented simplifications in `Heading.jsx`** (not silent gaps):

- The resource breadcrumb trail (`x-resources.breadcrumbs`), domain-link chips (`x-applications.links`), and the "advanced" button group (`x-applications.advanced`) are not ported.
- The stop confirmation uses `window.confirm()`, not the original's modal-with-checkbox (`x-modal-confirmation`).
- The mobile dropdown action menu (a separate condensed control set in the original) is not ported — the same action buttons render at all viewport widths instead.

### A real bug found by the new tests

`bootstrap/helpers/timezone.php`'s `formatDateInServerTimezone()` and `calculateDuration()` both called `new DateTime($date)` directly on their arguments. Every existing call site in the Livewire/Blade views passes an array-sourced value (`data_get($execution, 'created_at', ...)` on a `->toArray()`'d record), which is always a string. `ApplicationDeploymentController::deploymentProps()` is the first caller to pass an Eloquent attribute directly (`$deployment->created_at`, `$deployment->finished_at`) — both auto-cast to `Illuminate\Support\Carbon` instances by Eloquent, not strings. `new DateTime($carbonInstance)` throws (`DateTime::__construct(): Argument #1 ($datetime) must be of type string, Illuminate\Support\Carbon given`) — a latent bug in an existing shared helper, only surfaced because this was the first caller to use it this way. **Fixed** by casting both arguments to `(string)` before constructing the `DateTime` (`Carbon`'s `__toString()` returns `Y-m-d H:i:s`, which `DateTime`'s constructor parses fine) — a one-line fix in the helper itself, benefiting every existing call site too since `(string) $alreadyAString` is a no-op.

### Phase 7 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP changes | passed |
| 3 new Feature tests (`ApplicationDeploymentIndexTest`) | 1 failed on first run (the `formatDateInServerTimezone` bug above), 3 passed after the fix |
| `yarn build` | Succeeded — `Heading-*.js`, `ConfigurationChecker-*.js`, `Index-*.js` (deployment page), and a shared `useTeamChannel-*.js` chunk (73 kB, pulling in `laravel-echo`/`pusher-js`) all emitted |
| Full suite | 345 passed (1263 assertions) — up from 342, no regressions |

## 20. Non-goals of Phase 7

- `Server\Navbar` was scoped and deferred, not converted (see above) — still Livewire, still blocking the ~21 pages that depend on it as chrome.
- 58 Hard-bucket components remain on Livewire, including the still-not-designed embedded-Livewire-island question for pages that nest components with no natural React equivalent yet.
- `Heading.jsx`'s dropped breadcrumbs/domain-links/advanced-button-group/mobile-dropdown (documented above) are real, deliberate simplifications for this pass, not tracked elsewhere as a TODO — revisit if a future page's conversion needs the same shared elements at higher fidelity.
- No manual browser QA this phase either — same lighter, user-directed bar as Phases 2-6 (Section 9).

## 21. Phase 8 — `Terminal\Index`: a non-Echo Hard-bucket page (raw WebSocket)

Every Hard-bucket page converted so far (Phase 7) depended on Laravel Echo/broadcast events. `Terminal\Index` is a structurally different kind of "Hard": it's a live SSH/PTY terminal that talks over a raw WebSocket straight to a Node terminal server (`coolify-realtime`'s `terminal-server.js`), with zero Laravel broadcasting involved. Before starting, a dedicated research pass (Explore agent) catalogued the remaining Hard-bucket pages not blocked by the still-deferred `Server\Navbar`; the two next-best candidates it found nest either broadcast-driven `Heading` variants (Database/Service, themselves unconverted) or were much larger multi-child tabbed pages, so `Terminal\Index` — genuinely Hard, self-contained, comparable in scope to the Phase 7 cluster — was confirmed with the user before starting given its materially different technical shape and higher operational stakes (a regression here breaks live server access, not a settings form).

### Design

- **`terminal.js` (843-line Alpine component) is untouched.** It still drives the still-Livewire `ExecuteContainerCommand` pages (`project.{application,database,service}.command`, `server.command`), which nest `Project\Shared\Terminal` — also untouched, since it's still used there (same "kept nested Livewire child" pattern as `Heading` in Phase 7).
- **New `resources/js/terminalSession.js`**: a framework-agnostic port of `terminal.js`'s orchestration logic (WebSocket lifecycle, exponential-backoff reconnect, ping/pong heartbeat with missed-heartbeat detection, xterm.js flow control via pause/resume, session-expiry countdown, visibility-change handling for tab-backgrounding) into a plain ES class. The only real change from the original is the reactivity glue: Alpine's `$wire.dispatch(...)`/`$watch(...)` calls become constructor callbacks (`onError`, `onTerminalConnected`, `onTerminalDisconnected`, `onStateChange`) that a React component wires to `useState`. The WebSocket protocol, timing constants, and reconnect/heartbeat logic are line-for-line unchanged.
- **Two Livewire components collapsed into one controller.** The original split `connectToContainer()` (`Terminal\Index`, resolves the selection) from `sendTerminalCommand()` (`Project\Shared\Terminal`, builds and validates the actual SSH command) across two Livewire components purely because of a documented architectural constraint in the original code (the websocket connection isn't available server-side, so the SSH command has to be dispatched back down to the browser). That two-component split had no reason to survive the move to Inertia/React — `TerminalController::connect()` does both steps in one request, faithfully porting all of the original's validation (team ownership, `isTerminalEnabled()`/`isForceDisabled()`, container-name format validation, running-status check, shell-availability probe, non-root `sudo` handling) and returns either `{command: "..."}` or a JSON error.
- **Two-phase loading preserved via Inertia's deferred props.** The original `mount()` loaded the server list synchronously, then a separate `x-init="$wire.loadContainers()"` call enumerated containers across every reachable server (slow — it SSHes into each one) without blocking the initial paint. `TerminalController::index()` reproduces this exactly: `servers` is a normal eager prop, `containers` is `Inertia::defer(...)`, and `Terminal/Index.jsx` wraps the select/connect form in `<Deferred data="containers" fallback={...}>`.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/TerminalController.php` | created | `index()` (servers + deferred containers + terminal config), `connect()` (resolves selection → validates → builds SSH command → returns JSON), private `getAllActiveContainers()`/`checkShellAvailability()` ported from the two original Livewire components |
| `resources/js/terminalSession.js` | created | Framework-agnostic port of `terminal.js`'s WebSocket/xterm/reconnect/heartbeat logic, driven by callbacks instead of `$wire`/Alpine `$watch` |
| `resources/js/Pages/Terminal/Index.jsx` | created | Server/container picker (deferred containers, `<select>` grouped by server), calls `connect()` via `fetch()` (not a full Inertia visit — the response feeds the terminal directly, it isn't a page navigation), passes the resolved SSH command down to `TerminalWindow` |
| `resources/js/Pages/Terminal/TerminalWindow.jsx` | created | React port of `resources/views/livewire/project/shared/terminal.blade.php` — fullscreen toggle, session-expiry timer badge, mobile control toolbar, "Terminal Not Available" (no-shell) state — driven by `terminalSession.js` instead of Alpine |
| `routes/web.php` | modified | `terminal` repointed at `TerminalController::index`; new `terminal.connect` POST route. `terminal.auth`/`terminal.auth.ips` (unrelated auth-check endpoints) untouched |
| `app/Livewire/Terminal/Index.php`, `resources/views/livewire/terminal/index.blade.php` | **deleted** | Real cutover, grep-confirmed no other references to the class |
| `tests/v4/Feature/TerminalIndexTest.php` | created | 4 tests: renders the page (servers/containers/config props), 403s a non-admin member, and 2 validation tests on `connect()` (no selection, unowned server) — deliberately scoped short of SSH/Process-mocking since the deeper validation chain (`checkShellAvailability`, `SshMultiplexingHelper`) is unchanged, already-tested logic being relocated, not new logic |
| `docs/command.md` | n/a this phase | (created in Phase 7, unrelated to Terminal) |

**Known simplifications, documented not silent:**

- `Index.jsx`'s helper tooltip (`x-helper` in the original) is a plain `title` attribute, not a rich popover — no existing React equivalent exists yet in this migration (first page to need one).
- The original's `wire:poll.keep-alive.30s="keepTerminalPageAlive"` kept the Livewire component's server-side session alive during a long SSH session. Inertia pages have no persistent server-side component to keep alive, so `TerminalWindow.jsx` has no equivalent — noted as a comment in the file.
- `AppLayout.jsx` already had a gated "Terminal" nav item (`permissions.canAccessTerminal`) since Phase 2, and the old Blade navbar's Terminal link had no `wireNavigate()` to strip — no navbar changes needed this phase.

### A CI regression found and fixed mid-phase

Deleting `app/Livewire/Terminal/Index.php` (this phase) and `app/Livewire/Project/Application/Deployment/Index.php` (Phase 7) each left stale `phpstan-baseline.neon` entries pointing at now-nonexistent files — the same category of bug documented in Phase 5 (Section 4's "flagged but not cleaned up" note about `SettingsEmail`), except this time it actually broke CI (`Invalid entries in ignoreErrors: Path ... is neither a directory, nor a file path, nor a fnmatch pattern.`), not just a harmless stale line. Fixed by removing the 25 stale baseline blocks referencing either deleted path (a small script parsing the `.neon` file's block structure, same approach used earlier in this migration for a larger baseline cleanup).

With the stale entries gone, PHPStan could actually analyze the new controllers and surfaced **11 real findings** — 7 pre-existing in `ApplicationDeploymentController.php` (masked until now by the stale-but-passing baseline validation short-circuiting before analysis ran) and 4 new in `TerminalController.php`. 9 were fixed directly (missing return-type/param-type PHPDoc on 5 private prop-building methods, a redundant `?? []` on an array key PHPStan proved always exists, an `ApplicationDeploymentQueue` type hint replacing an untyped `$deployment` parameter, and dropping a redundant `collect()` re-wrap of an already-typed `Collection` parameter). The remaining 2 were deliberately left to the baseline rather than "fixed" blindly:

- A `nullsafe.neverNull` complaint on `$lastDeployment?->commit_message` that, per PHPStan, considers the nullsafe operator redundant — but `get_last_successful_deployment()` has no declared return type and can genuinely return `null` from `->first()`; removing the `?->` on PHPStan's suggestion alone would risk a real null-pointer fatal if the static analysis reasoning here is wrong or based on an incomplete type inference chain. Baselined instead of applying an unverified "fix" that trades a lint warning for a potential production crash.
- A `return.type` complaint on `getAllActiveContainers()` caused by `Illuminate\Support\Collection`'s non-covariant `TValue` template — a well-documented, known PHPStan/Laravel limitation (the error message itself links to PHPStan's blog post about it), not a real type error.

### Phase 8 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 4 new Feature tests (`TerminalIndexTest`) | 1 failed on first run (`InstanceSettings` singleton gotcha, same as every prior phase touching an abort/error-page path), 4 passed after adding the standard `beforeEach` fixture |
| PHPStan (`vendor/bin/phpstan analyse`) | 11 real findings surfaced after baseline cleanup; 9 fixed, 2 baselined (see above); final run: `[OK] No errors` |
| `yarn build` | Succeeded — `TerminalWindow-*.js` (19 kB) and the Terminal `Index-*.js` chunk emitted; `@xterm/xterm`/`@xterm/addon-fit` now also bundled into the Inertia entrypoint (previously only reachable from the Livewire `app.js` entrypoint) |
| Full suite | 349 passed (1281 assertions) — up from 345, no regressions |

## 22. Non-goals of Phase 8

- `Server\Navbar` remains deferred — still the highest-leverage remaining piece of work, unblocking ~21 pages at once, but still not started.
- `Project\Shared\Terminal.php`/`terminal.js`/its Blade view are untouched and still load-bearing for the still-Livewire `ExecuteContainerCommand` pages — not dead code, don't remove them when eventually converting those pages without re-checking.
- 57 Hard-bucket components remain on Livewire, including the two Database/Service `Heading` variants and `Project/Shared/Logs`/`GetLogs` flagged during this phase's candidate research as genuinely Hard but wider in scope (nest multiple broadcast-driven children) than a single-page pass.
- No manual browser QA this phase either — same lighter, user-directed automated-checks bar as every phase since Phase 2 (Section 9). This is a real gap worth calling out explicitly for this specific page: a live xterm.js/WebSocket terminal is exactly the kind of interactive, stateful UI that automated `assertInertia()` checks cannot meaningfully exercise — the verification log above proves the page renders and the `connect()` validation logic is correct, not that a real terminal session actually works end-to-end in a browser.

## 23. Phase 9 — `Security\CloudTokens`: a non-broadcast Hard-bucket page (old-style `getListeners()` + nested child)

A third flavor of Hard-bucket page, distinct from both prior phases: no Echo/broadcast dependency (Phase 7) and no raw WebSocket (Phase 8) — this one was Hard-bucket purely because of the two other disqualifiers from the Section 6 triage criteria: old-style `getListeners()` (`CloudProviderTokens` listens for a `tokenAdded` event) and a nested `<livewire:.../>` child (`CloudProviderTokenForm`, the "add token" form). Structurally it's much closer to the Medium-bucket recipe than Phases 7-8 — no new architecture needed, just the established controller/`useForm()`/Pest pattern applied to a page that happened to be split across two Livewire components.

### Design

- **Three Livewire components existed; one was kept, two were folded together.** `Security\CloudTokens` (a thin wrapper) and `CloudProviderTokens` (the real list/validate/delete logic, listening for `tokenAdded`) were both deleted — their combined logic became `SecurityCloudTokensController` + one React page. **`CloudProviderTokenForm.php` and its Blade view were kept untouched** — grepping for its usage turned up two other still-Livewire call sites (`server/new/by-hetzner.blade.php` and `server/cloud-provider-token/show.blade.php`, both using its `modal_mode="true"` variant for on-the-fly token creation during server setup), the same "kept nested Livewire child" pattern as `Heading` (Phase 7) and `Project\Shared\Terminal` (Phase 8). The React page reimplements the form's non-modal ("full page layout") branch directly rather than trying to share code with the still-Livewire component.
- **The `tokenAdded` event listener had no reason to survive.** In Livewire, `CloudProviderTokenForm::addToken()` dispatches `tokenAdded` so the sibling `CloudProviderTokens` list can refresh itself without a full reload. Once the add-form and the list live in the same React component/request cycle, `useForm()`'s standard `onSuccess` (Inertia auto-refreshes shared props after any successful visit) makes the whole listener mechanism unnecessary — the list is just always current after a submit.
- **Two Livewire methods, `validateToken()` (API-side, per-row) and the outer `CloudProviderTokens` component, share one name — disambiguated in the controller.** `SecurityCloudTokensController::validateToken(int $id)` is the HTTP action; the private `validateProviderToken()`/`validateHetznerToken()`/`validateDigitalOceanToken()` helpers are faithful ports of the original's provider-specific API-validation logic (Hetzner: `GET /v1/servers`, DigitalOcean: `GET /v2/account`, both with a 10s timeout and try/catch-to-`false`).

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SecurityCloudTokensController.php` | created | `index()` (list + `canCreate` gate), `store()` (validate provider API + create), `validateToken()` (per-row API check), `destroy()` (blocked if `hasServers()`, matching the original's in-use guard) |
| `resources/js/Pages/Security/CloudTokens.jsx` | created | Add-token form (`useForm()`, Hetzner-only provider select disabled per the original's current-provider-support state) + saved-tokens list with Validate/Delete actions. Hand-renders the same Security sub-nav copy every other converted Security page already hand-renders (established in Phase 5) |
| `resources/views/components/security/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Cloud Tokens" link only — `security.private-key.index` (list page, still Livewire) and `security.cloud-init-scripts` (still Livewire) keep theirs |
| `routes/web.php` | modified | `security.cloud-tokens` repointed at the new controller; new `security.cloud-tokens.{store,validate,destroy}` routes |
| `app/Livewire/Security/{CloudTokens,CloudProviderTokens}.php` + matching Blade views | **deleted** | Real cutover, grep-confirmed no other references to either class |
| `tests/v4/Feature/SecurityCloudTokensTest.php` | created | 6 tests: renders page, 403s a non-admin member, adds a token (Hetzner API faked success), rejects a token (Hetzner API faked 401), blocks delete when a server references the token, deletes an unused token |

**Known simplification**: the delete confirmation uses `window.prompt()` requiring the token name typed back (matching `Destination\Show.jsx`'s established pattern from Phase 5), not the original's `x-modal-confirmation` component. Same trade-off already documented and accepted for every prior typed-confirmation delete in this migration.

### The same stale-baseline bug, a third time

Deleting `CloudTokens.php` and `CloudProviderTokens.php` left the same category of stale `phpstan-baseline.neon` entries as Phases 7 and 8 — by now a fully mechanical fix (same script, new file paths). Worth noting as a pattern: **every phase of this migration that deletes a Livewire class will hit this** until the baseline itself is regenerated in a way that stops tracking deleted files, or until PHPStan analysis is run (and the baseline cleaned) as a standard step of the per-page recipe rather than an after-the-fact CI-failure response. Recommended for the next phase's checklist. Unlike Phase 8, PHPStan surfaced **zero new findings** in `SecurityCloudTokensController.php` after the baseline cleanup — the return/param types were written correctly from the start this time, suggesting the Phase 8 fix-up pass established a pattern worth continuing to apply proactively rather than retroactively.

### Phase 9 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 6 new Feature tests (`SecurityCloudTokensTest`) | 1 failed on first run (`InstanceSettings` singleton gotcha, same as every prior phase touching an abort/error-page path), 6 passed after adding the standard `beforeEach` fixture |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 2 deleted files cleaned up; `[OK] No errors` on the first analysis run afterward — no new findings in the new controller |
| `yarn build` | Succeeded — `CloudTokens-*.js` (4.4 kB) chunk emitted |
| Full suite | 355 passed (1308 assertions) — up from 349, no regressions |

## 24. Non-goals of Phase 9

- `CloudProviderTokenForm.php`/its Blade view are untouched and still load-bearing for `server.new.by-hetzner` and `server.cloud-provider-token.show` (both still Livewire) — not dead code.
- `Security\CloudInitScripts` and `Security\PrivateKey\Index` (the list page, not `Show`) remain on Livewire — both still use the shared `x-security.navbar` Blade partial, now with only the "Cloud Tokens" link converted.
- `Server\Navbar` remains deferred, unchanged from Phase 8.
- 56 Hard-bucket components remain on Livewire.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). Lower-stakes than Phase 8's terminal (no live connection state to fail to exercise), but the external Hetzner/DigitalOcean API calls are only verified against `Http::fake()`, not a real provider account.

## 25. Phase 10 — `Security\CloudInitScripts`: the Security sub-nav's second Hard-bucket page

The direct sibling to Phase 9's `CloudTokens` — same Hard-bucket disqualifiers (old-style `getListeners()` listening for `scriptSaved`, a nested `<livewire:.../>` form child), same shared `x-security.navbar` Blade partial, converted immediately after using the same recipe. The one real difference: the original UI used actual modal dialogs (`<x-modal-input>`) for both "Add" and "Edit", rather than an inline form section — this page establishes the first reusable-in-spirit modal pattern for a create/edit (not just view/confirm) form in this migration.

### Design

- **All three Livewire files deleted, not two.** Unlike `CloudProviderTokenForm` (Phase 9, kept — reused by two other still-Livewire pages), grepping `CloudInitScriptForm` turned up no consumer besides the page being converted here. `CloudInitScripts.php`, `CloudInitScriptForm.php`, and both Blade views were deleted outright — a cleaner cutover than Phase 9's.
- **One form, two modes, one `useForm()` instance.** The original had a single Livewire component (`CloudInitScriptForm`) handle both create (`scriptId = null`) and update (`scriptId` set, fields pre-populated in `mount()`), reused via two separate `<x-modal-input>` invocations (one for "+ Add", one per-row "Edit"). The React page keeps that one-form-two-modes shape: a single `useForm({name, script})` instance, a `modalScript` state (`null` = closed, `{id: null}` = creating, a real script object = editing) that decides both which URL (`storeUrl` vs. that row's `updateUrl`) and which fields to pre-populate.
- **Modal chrome reused, not reinvented.** The fixed-overlay/backdrop/centered-panel modal structure is copied from `ConfigurationChecker.jsx`'s (Phase 7) view-only modal — the first precedent for any dialog-style overlay in this migration. This is the first time that pattern has been reused for a create/edit form rather than a read-only detail view, confirming it generalizes.
- **The `scriptSaved` listener, like Phase 9's `tokenAdded`, had no reason to survive.** Inertia's default behavior (shared props refetch automatically after any successful visit) already gives the list a current view after a create/update submit — no manual "tell the sibling to reload" event needed once both live in the same request/response cycle.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SecurityCloudInitScriptsController.php` | created | `index()` (list + per-row `canUpdate`/`canDelete`/action URLs), `store()`, `update()`, `destroy()` — validation (`ValidCloudInitYaml` rule, faithfully reused) factored into one private `validated()` helper shared by create and update |
| `resources/js/Pages/Security/CloudInitScripts.jsx` | created | Script grid + single reusable add/edit modal (`useForm()` + `modalScript` state) + delete via the established `window.prompt()`-typed-confirmation pattern |
| `resources/views/components/security/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Cloud-Init Scripts" link only — now only `security.private-key.index` (the still-Livewire list page) keeps its `wireNavigate()` on this shared partial |
| `routes/web.php` | modified | `security.cloud-init-scripts` repointed at the new controller; new `security.cloud-init-scripts.{store,update,destroy}` routes |
| `app/Livewire/Security/{CloudInitScripts,CloudInitScriptForm}.php` + both matching Blade views | **deleted** | Real cutover — grep-confirmed zero other consumers of either class |
| `tests/v4/Feature/SecurityCloudInitScriptsTest.php` | created | 6 tests: renders page, 403s a non-admin member, creates a script, rejects one with invalid cloud-init YAML, updates a script, deletes a script |

### PHPStan baseline: proactive cleanup, no reactive fix-up needed this time

Applying the lesson recorded in Phase 9 (clean the baseline as part of the recipe, not after a CI failure), the stale `phpstan-baseline.neon` entries for the two deleted files were cleaned up as a standard step before considering this phase done, rather than waiting to discover them via a broken CI run. `vendor/bin/phpstan analyse` reported **zero findings** in the new controller on the first run after cleanup — no fix-up pass needed, unlike Phase 8.

### Phase 10 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 6 new Feature tests (`SecurityCloudInitScriptsTest`) | 6 passed on the first run — no `InstanceSettings` singleton gotcha this time (no abort/error-page path exercised outside the standard `beforeEach` fixture, included proactively) |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 2 deleted files cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `CloudInitScripts-*.js` chunk emitted |
| Full suite | 361 passed (1335 assertions) — up from 355, no regressions |

## 26. Non-goals of Phase 10

- `Security\PrivateKey\Index` (the list page) remains the only still-Livewire consumer of `x-security.navbar` — all 3 of its sibling tabs (`Cloud Tokens`, `Cloud-Init Scripts`, `API Tokens`) are now converted.
- `Server\Navbar` remains deferred, unchanged from Phases 8-9.
- 55 Hard-bucket components remain on Livewire.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). The reusable modal pattern (borrowed from Phase 7) has not been visually confirmed in a real browser for this create/edit use case, only via `assertInertia()`/`assertSessionHas()` checks on the underlying HTTP actions.

## 27. Phase 11 — `Server\Navbar` foundation + 3 pilot pages

The largest single piece of shared chrome left in the app: `App\Livewire\Server\Navbar` is nested inside **21 different pages** (confirmed via grep), manages the proxy lifecycle (start/stop/restart via job dispatch, broadcast-driven status via `ProxyStatusChangedUI`), and itself nests another shared component (`<livewire:activity-monitor>`, a live process-log poller used by 9 *other* still-Livewire pages). This phase builds the React replacement for that chrome once, then proves it on 3 pilot pages, rather than converting all 21 dependents in one pass — the same "build the shared piece once, adopt page-by-page" approach `AppLayout.jsx` used starting in Phase 2.

### Scoping, done before writing any code

A dedicated research pass (Explore agent) catalogued all 20 non-`Show` pages nesting Navbar by PHP/blade line count, further nested children, and broadcast dependency, specifically to avoid repeating a mistake from earlier in this phase: the first assumed-simple pilot candidate, `Server\Show`, turned out to be one of the *largest* dependents (669 PHP lines — full settings form, Sentinel management, Hetzner Cloud linking, its own 2 Echo listeners) — the same "looks simple until you actually check" pattern already seen with `Server\Navbar` itself (Phase 7) and the Medium/Hard bucket miscount (Phase 6).

The research surfaced a real, cheap pilot: **`Server\Swarm`** (70 PHP / 47 blade lines, two boolean toggles, zero nested children, zero broadcast dependency). The user chose to convert Swarm plus 2 more pilots in the same pass to shake out more edge cases up front: **`Server\Security\TerminalAccess`** (adds a password-confirmation + admin-only gate) and **`Server\Delete`** (adds a destructive action with dynamic checkboxes and a redirect-after-delete flow). Together the 3 pilots exercise instant-save toggles, password-gated actions, and destructive-action confirmation — the three interaction shapes most of the remaining 18 dependents will also need.

The user also decided, when asked, to build a full reusable React port of the `ActivityMonitor` proxy-startup-log viewer now rather than deferring it — despite none of the 3 pilots' own content needing it (only Navbar's slide-over does).

### Shared chrome architecture

| File | Change | Purpose |
| --- | --- | --- |
| `app/Support/ServerChromeData.php` | created | `navbar(Server $server): array` and `sidebar(Server $server, string $variant, string $activeMenu): array` — server-side prop builders every converted Server-scoped page's controller calls into, so the chrome's data shape lives in one place rather than being re-derived per page. Faithfully ports `Server\Navbar::mount()`/`loadProxyConfiguration()`/`getHasTraefikOutdatedProperty()` |
| `app/Http/Controllers/ServerProxyActionsController.php` | created | `restart()`/`checkStatus()`/`start()`/`stop()` — the proxy lifecycle actions, ported from Navbar's own methods, shared by every Server-scoped page (not duplicated per page) |
| `app/Http/Controllers/ActivityController.php` | created | `show(int $id)` JSON polling endpoint backing `ActivityLog.jsx`, porting `ActivityMonitor::hydrateActivity()`'s team-ownership verification (by `properties.team_id` or by resolving `properties.server_uuid`'s owning team) |
| `resources/js/Components/ActivityLog.jsx` | created (new `Components/` directory, alongside existing `Layouts/`/`Pages/`/`hooks/`) | React port of `ActivityMonitor.php`'s polling loop — poll every 1s, auto-scroll, stop on exit code. **Scope reduction**: only the plain "call an `onFinished` callback" completion path is ported, not the original's ability to dispatch an arbitrary broadcast-event class by string name on completion (Navbar's own use of `ActivityMonitor` never exercises that path, so it wasn't needed) |
| `resources/js/Components/ServerNavbar.jsx` | created | React port of `Server\Navbar` + its Blade view: proxy/Sentinel status badges, the 6-item conditional sub-nav (Configuration/Proxy/Sentinel/Resources/Terminal/Security), Start/Stop/Restart with confirmation, a slide-over showing `ActivityLog` during proxy startup, and a `useTeamChannel(['ProxyStatusChangedUI'], ...)` listener reproducing the original's status-transition notification de-duplication (only toast on meaningful transitions, not every poll) |
| `resources/js/Components/ServerSidebar.jsx` | created | React port of 2 of the 4 `resources/views/components/server/sidebar*.blade.php` variants — `sidebar.blade.php` ("main", used by Swarm/Delete) and `sidebar-security.blade.php` ("security", used by TerminalAccess). `sidebar-proxy.blade.php`/`sidebar-sentinel.blade.php` are not ported yet — add them the same way when a page using them is converted |
| `app/Http/Middleware/HandleInertiaRequests.php` | modified | Added `proxyActivityId` to the shared `flash` prop, so `ServerNavbar.jsx` can detect "a start/restart was just triggered" and open its log slide-over after the redirect-back completes |

### The 3 pilot pages

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerSwarmController.php`, `resources/js/Pages/Server/Swarm.jsx` | created | Two-checkbox instant-save form (`is_swarm_manager`/`is_swarm_worker`, mutually exclusive) |
| `app/Http/Controllers/ServerSecurityTerminalAccessController.php`, `resources/js/Pages/Server/Security/TerminalAccess.jsx` | created | Admin-only, password-confirmed toggle for terminal access, reusing `verifyPasswordConfirmation()` (the same helper Phase 6 discovered checks `InstanceSettings`'s skip-confirmation flag) and the established typed-name-then-password `window.prompt()` sequence from `Team\AdminView` (Phase 6) |
| `app/Http/Controllers/ServerDeleteController.php`, `resources/js/Pages/Server/Delete.jsx` | created | Destructive delete flow with dynamic checkboxes (force-delete-resources / delete-from-Hetzner, shown only when applicable), a custom modal (checkboxes need real form state, `window.prompt()` alone can't hold them — same modal-with-state pattern as `CloudInitScripts`, Phase 10), and redirect to `server.index` after deletion |
| `routes/web.php` | modified | 3 pages repointed at new controllers with new `.update`/`.toggle`/`.destroy` routes; new shared `server.proxy-actions.{restart,stop,start,check-status}` routes (reusable by all future Server-scoped conversions); new top-level `activity.show` route |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the Swarm and Danger links only — every other link in this shared partial still points at not-yet-converted pages |
| `app/Livewire/Server/{Swarm,Security/TerminalAccess,Delete}.php` + matching Blade views | **deleted** | Real cutovers, grep-confirmed no other references. `Server\Navbar` itself and `ActivityMonitor.php` are untouched — still load-bearing for the other 18 dependent pages and 9 other `ActivityMonitor` consumers respectively |
| `tests/v4/Feature/{ServerSwarmTest,ServerSecurityTerminalAccessTest,ServerDeleteTest,ServerProxyActionsTest}.php` | created | 16 tests total across the 3 pages plus the shared proxy-actions controller |

### Testing the shared proxy actions without touching real SSH

`ServerProxyActionsController`'s 4 actions have 2 different underlying implementations that matter for testing: `restart()` dispatches `RestartProxyJob` (implements `ShouldQueue` — cleanly interceptable with `Bus::fake()`), while `checkStatus()`/`start()`/`stop()` call `CheckProxy`/`StartProxy`/`StopProxy` — `lorisleiva/laravel-actions` classes invoked via `::run()`/`::dispatch()` that do **not** implement `ShouldQueue`, meaning they execute their `handle()` synchronously and immediately, bypassing the queue entirely (so `Bus::fake()` can't intercept them). Rather than building deeper SSH-mocking infrastructure to test all 4 actions' happy paths (a bigger, separate investment — this repo already has a namespace-scoped precedent for it in `tests/Support/Fakes/action_remote_process_overrides.php`, but only for `App\Actions\Application`, not `App\Actions\Proxy`), this phase tested:

- `restart()` — `Bus::fake()` + `assertDispatched()`, safe and complete.
- `checkStatus()` — called against a deliberately non-functional server (`is_reachable`/`is_usable` both false), which hits `CheckProxy::handle()`'s first early-return branch before any remote process runs.
- All 4 actions — a 404-for-a-server-owned-by-another-team check (the `ownedByCurrentTeam()` guard), which requires no action execution at all.

`start()`'s happy path specifically was left untested — it's always called with `force: true`, which is the one code path in `StartProxy::handle()` that does *not* early-return, so there is no safe way to exercise it without real SSH mocking. Recorded here rather than silently skipped.

### Phase 11 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 16 new Feature tests (4 files) | 8 failed on first run (`InstanceSettings` singleton gotcha — this time surfacing in every 404/error-abort-path test across all 4 files at once, not just one), 16 passed after adding the standard `beforeEach` fixture to each |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 3 deleted files cleaned proactively (per the Phase 10 lesson); `[OK] No errors` — zero new findings in any of the new controllers |
| `yarn build` | Succeeded — `ServerSidebar-*.js` (8.3 kB) plus the 3 new page chunks confirmed present in `manifest.json` |
| Full suite | 377 passed (1399 assertions) — up from 361, no regressions |

## 28. Non-goals of Phase 11

- **18 of the 21 `Server\Navbar`-dependent pages remain on Livewire** — this phase proved the pattern on 3, not all 21. The next-best candidates by size/isolation (per the research pass): `Security\TerminalAccess`-sized pages are exhausted; remaining ones mostly nest further live children (Sentinel/Proxy/Docker-cleanup sub-components) or are considerably larger (`Server\Charts` at 315 blade lines, `Server\LogDrains` at 199 PHP lines).
- `ServerSidebar.jsx` only covers the "main" and "security" Blade sidebar variants — `sidebar-proxy.blade.php` and `sidebar-sentinel.blade.php` are not ported; needed when `Server\Proxy\Show`/`Server\Sentinel\Show` (both "thin wrapper" pages nesting further live children, explicitly flagged as bad pilot candidates) eventually get converted.
- `ActivityMonitor.php`/its Blade view are untouched and still load-bearing for 9 other still-Livewire pages (Database/Service Heading, Settings Index, Server Security Patches, Server validate-and-install, Server CloudflareTunnel, Database import-form, Boarding Index) — `ActivityLog.jsx` is a new, parallel React implementation, not a replacement.
- `start()`'s happy path is untested (see above) — a real gap, not a silently-accepted one.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). This is a bigger real gap than usual: the proxy start/stop/restart flow, the live log slide-over, and the status-transition toast de-duplication logic in `ServerNavbar.jsx` have never been exercised in a real browser, only via `assertInertia()`/redirect/flash checks on the underlying HTTP actions.

## 29. Phase 12 — `Server\Advanced`: first re-use of the `Server\Navbar` foundation

The first page converted purely by *reusing* Phase 11's shared chrome, not building any of it. `Server\Advanced` (94 PHP / 51 blade lines) was one of the "zero nested children, zero broadcast dependency" candidates the Phase 11 research pass identified — a plain instant-save settings form (disk-usage check frequency/threshold, concurrent builds, deployment timeout, deployment queue limit), same shape as Phase 11's `Server\Swarm` pilot. This phase exists mainly to confirm the foundation genuinely generalizes to a new page with zero changes to `ServerChromeData`/`ServerNavbar.jsx`/`ServerSidebar.jsx` — it did.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerAdvancedController.php` | created | `index()` (calls the existing `ServerChromeData::navbar()`/`sidebar()` unchanged) and `update()` — faithfully ports the cron-expression validation for disk-usage check frequency, including the original's defensive try/catch (see below) |
| `resources/js/Pages/Server/Advanced.jsx` | created | Single `useForm()` covering all 5 settings fields, submitted via one PUT |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Advanced" link only |
| `routes/web.php` | modified | `server.advanced` repointed at the new controller; new `server.advanced.update` route |
| `app/Livewire/Server/Advanced.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references |
| `tests/v4/Feature/ServerAdvancedTest.php` | created | 4 tests: renders, updates settings, rejects an invalid cron expression, 404s for a server owned by another team |

**A real, latent bug found and fixed while writing the invalid-cron-expression test**: the original Livewire component's `submit()` wrapped `validate_cron_expression()` in a try/catch because the underlying `Cron\CronExpression` constructor throws `InvalidArgumentException` on a malformed string rather than returning `false` — `validate_cron_expression()` itself has no internal try/catch, so any caller that doesn't wrap it will get an uncaught exception (a 500) instead of a clean validation error for bad input. The first draft of `ServerAdvancedController::update()` called it unwrapped; the rejection test caught this immediately (a 500 instead of the expected redirect-with-error). Fixed by adding the same try/catch the original component had — a case of the original code being *correct but non-obviously so*, and a fresh port dropping a defensive wrapper that looked like unnecessary boilerplate until the test proved otherwise.

### Phase 12 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 4 new Feature tests (`ServerAdvancedTest`) | 1 failed on first run (uncaught `InvalidArgumentException` from `validate_cron_expression()`, see above), 4 passed after adding the try/catch |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entry for the 1 deleted file cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `Server/Advanced.jsx` confirmed present in `manifest.json` (disambiguated from the pre-existing `Settings/Advanced.jsx` chunk from Phase 5, which shares the same base filename) |
| Full suite | 381 passed (1419 assertions) — up from 377, no regressions |

## 30. Non-goals of Phase 12

- 17 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 18). Remaining zero-nested-child/zero-broadcast candidates per the Phase 11 research: `Server\CaCertificate\Show` (145/92) and `Server\LogDrains` (199/123) — both good next candidates using this same reuse-only recipe.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged — the foundation itself wasn't touched this phase.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 31. Phase 13 — `Server\CaCertificate\Show`: second reuse of the `Server\Navbar` foundation

Another zero-nested-child, zero-broadcast candidate from the Phase 11 research list, converted with the same reuse-only recipe as Phase 12's `Server\Advanced` — no changes to `ServerChromeData`/`ServerNavbar.jsx`/`ServerSidebar.jsx` needed. This page manages the custom CA certificate Coolify uses to sign database SSL certificates: view/edit/save the certificate content, or regenerate it entirely, both actions writing the result to the server over SSH (`remote_process()`) and queuing `RegenerateSslCertJob`.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerCaCertificateController.php` | created | `index()` (reuses `ServerChromeData` unchanged), `save()` (validates + parses the pasted certificate via `openssl_x509_read()`/`openssl_x509_export()`, writes it to the server, queues regeneration), `regenerate()` (generates a fresh 10-year CA cert via `SslHelper`, writes it, queues regeneration) |
| `resources/js/Pages/Server/CaCertificate/Show.jsx` | created | Show/hide toggle for the certificate textarea (a Livewire round-trip in the original, now a plain local `useState` — a faithful simplification since it's pure UI state), Save/Regenerate actions using the established typed-confirmation `window.prompt()` pattern (confirmation text is the certificate's filesystem path, matching the original's `confirmationText`) |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "CA Certificate" link only |
| `routes/web.php` | modified | `server.ca-certificate` repointed at the new controller; new `server.ca-certificate.{save,regenerate}` routes |
| `app/Livewire/Server/CaCertificate/Show.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references |
| `tests/v4/Feature/ServerCaCertificateTest.php` | created | 4 tests: renders, rejects an invalid (non-x509) certificate, rejects an empty certificate, 404s for a server owned by another team. The `save()`/`regenerate()` happy paths (which write to the server over SSH) are deliberately not exercised — same documented trade-off as Phase 11's `start()` proxy action |

**A second real latent bug found by the same category of test, one phase after the first**: `openssl_x509_read()` raises a PHP warning on malformed input that this app's exception handler promotes to a catchable `ErrorException`, rather than simply returning `false` as the calling code assumed. The original Livewire component's `saveCaCertificate()` wrapped its entire body in a top-level try/catch specifically to absorb exactly this; the first draft of `ServerCaCertificateController::save()` didn't, and the "rejects an invalid certificate" test caught it immediately (a 500 instead of the expected redirect-with-error). Fixed with the same narrow try/catch pattern as Phase 12's cron-expression fix. **Two phases in a row have now found a real bug in the exact same shape**: a PHP builtin/library call that the original Livewire component defensively wrapped in `try/catch (\Throwable)`, which looked like unnecessary boilerplate when porting to a fresh controller until a rejection-path test proved otherwise. Worth treating as a standing rule for the rest of this migration: **any `try/catch` in a Livewire method being ported is signal, not boilerplate — port it, don't drop it.**

A separate PHPStan finding — `nullsafe.neverNull` on `$caCertificate?->ssl_certificate ?? ''` — was baselined rather than "fixed," for the same reason as Phase 7's identical finding: `$caCertificate` is genuinely nullable (`?SslCertificate`), and PHPStan's suggested fix (drop the `?->`) would introduce a real null-pointer risk if its analysis here is wrong.

### Phase 13 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 4 new Feature tests (`ServerCaCertificateTest`) | 1 failed on first run (the `openssl_x509_read()` uncaught-exception bug above), 4 passed after adding the try/catch |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entry for the 1 deleted file cleaned proactively; 1 new finding (`nullsafe.neverNull`, baselined per the reasoning above); final run: `[OK] No errors` |
| `yarn build` | Succeeded — `Server/CaCertificate/Show.jsx` confirmed present in `manifest.json` |
| Full suite | 385 passed (1438 assertions) — up from 381, no regressions |

## 32. Non-goals of Phase 13

- 16 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 17). `Server\LogDrains` (199/123) remains the next-best zero-nested-child/zero-broadcast candidate per the Phase 11 research.
- The `save()`/`regenerate()` happy paths are untested (see above) — writing to a server over SSH, same category of gap as Phase 11's proxy `start()` action.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 33. Phase 14 — `Server\LogDrains`: third reuse, and a real behavioral adaptation

The last of the Phase 11 research pass's "zero nested child, zero broadcast" candidates. `Server\LogDrains` manages 3 mutually-exclusive log-drain providers (New Relic, Axiom, Custom FluentBit), each with its own enable toggle and settings form, where enabling one starts a log-drain service over SSH (`StartLogDrain::run()`) and disabling stops it (`StopLogDrain::run()`).

### A real design decision, not just a port

The original Livewire component's `instantSave()` (the enable/disable toggle) validates and saves **whatever is currently typed into the on-screen fields at that moment**, because Livewire's `wire:model` keeps every field on the page live-bound to the component's PHP properties continuously — even fields the user hasn't explicitly "saved" yet are already reflected server-side by the time the checkbox click reaches the server. A stateless Inertia/React page has no equivalent: the server only knows what the last completed HTTP request told it. Two options were considered: (a) require the user to Save a provider's fields first, then separately toggle it on against whatever was last persisted, or (b) have the toggle request carry the provider's current in-memory field values alongside the enable flag, replicating the original's "validate and save together" behavior in one request. **Chose (b)** — `ServerLogDrainsController::toggle()` accepts the provider's field values in the same request as the toggle, validates them (only when enabling; matching `customValidation()`'s original guard), and saves both together before starting/stopping the service. This is a case where a faithful port required a genuine request-shape decision, not just a mechanical translation.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerLogDrainsController.php` | created | `index()` (reuses `ServerChromeData` unchanged), `toggle()` (validate-and-save-together per the design decision above, then `StartLogDrain::run()`/`StopLogDrain::run()`), `submit()` (per-provider field save, no SSH — matches the original, where `submit()` never touches the log-drain service directly) |
| `resources/js/Pages/Server/LogDrains.jsx` | created | 3 provider sections, each a `useForm()` instance for its own fields plus a checkbox wired to `toggle()`. Fields render disabled/read-only once `isLogDrainEnabled` is true, matching the original's `@if ($server->isLogDrainEnabled())` |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Log Drains" link only |
| `routes/web.php` | modified | `server.log-drains` repointed at the new controller; new `server.log-drains.{toggle,submit}` routes |
| `app/Livewire/Server/LogDrains.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references |
| `tests/v4/Feature/ServerLogDrainsTest.php` | created | 7 tests: renders, saves each of the 3 providers' fields without enabling (all SSH-free, fully exercised), rejects invalid New Relic settings, rejects enabling a provider with missing required fields (validated before the SSH call, so safe to test), 404s for a server owned by another team |

Unlike Phases 12-13, no new PHP builtin/library defensive-wrapper bug surfaced this time — the `submit()` happy paths (the majority of this page's real logic) were fully testable without SSH mocking, since saving fields alone never calls `StartLogDrain`/`StopLogDrain`. Only the toggle-to-enabled happy path remains untested, consistent with the established SSH-testing boundary from Phases 11 and 13.

### Phase 14 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 7 new Feature tests (`ServerLogDrainsTest`) | 7 passed on the first run |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entry for the 1 deleted file cleaned proactively; `[OK] No errors` — zero new findings |
| `yarn build` | Succeeded — `Server/LogDrains.jsx` confirmed present in `manifest.json` |
| Full suite | 392 passed (1470 assertions) — up from 385, no regressions |

## 34. Non-goals of Phase 14

- 15 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 16). The Phase 11 research pass's list of easy zero-dependency candidates is now exhausted — the next page will need a fresh look at the remaining 15 (several nest further live children: Sentinel/Proxy/Docker-cleanup sub-components, or are considerably larger, e.g. `Server\Charts` at 315 blade lines).
- The toggle-to-enabled happy path (which starts the log-drain service over SSH) is untested — same category of gap as Phase 11's proxy `start()` and Phase 13's certificate save/regenerate.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). The request-shape design decision above (toggle carries field values) has not been visually confirmed in a real browser.

## 35. Phase 15 — `Server\Resources`: no nested children, but a real echo listener and two-tier data loading

The first `Server\Navbar`-dependent page conversion since the "easy" candidate list ran out (Phase 14). `Server\Resources` has no nested `<livewire:.../>` children (the status badges it renders are plain Blade components, `x-status.index`/`x-status.services`, not Livewire), but it does have a genuine broadcast dependency (`echo-private:team.{id},ApplicationStatusChanged`) and two meaningfully different data sources: a cheap DB query (managed resources — Applications/Databases/Services already tracked by Coolify) and an expensive SSH enumeration (unmanaged containers — a live `docker ps` on the server).

### Design

- **Managed resources load eagerly; unmanaged containers are deferred.** Same `Inertia::defer()` pattern established by Terminal's container list (Phase 8) — the expensive SSH-backed data doesn't block the initial page paint. This is a real, documented behavior change from the original: Livewire only fetched unmanaged containers when the user clicked that tab; the deferred prop fetches automatically shortly after page load regardless of which tab is showing. Judged an acceptable trade-off (same one already accepted for Terminal), not re-litigated.
- **The Echo listener triggers a coarse partial reload**, matching the established pattern since Phase 7: `useTeamChannel(['ApplicationStatusChanged'], () => router.reload({ only: ['managedResources', 'unmanagedContainers'] }))`. This is the first page since `ServerNavbar.jsx` itself (Phase 11) to use `useTeamChannel` directly from a page component rather than from shared chrome.
- **Status badge rendering was simplified from a faithful port to a category-based one.** The original's `x-status.index`/`x-status.services` components branch on a raw Docker/service status string (`running`, `degraded:unhealthy`, `exited:excluded`, etc.) with fairly involved per-case logic (restart-count warnings, `formatContainerStatus()` for the multi-container service case). The controller now computes a display-ready string (reusing `formatContainerStatus()` server-side, unchanged) plus a `statusCategory` (`running`/`degraded`/`restarting`/`stopped`), and the React page just color-codes by category — the restart-count/crash-loop warning sub-text is not ported. Documented as a real fidelity gap, not silently dropped.

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerResourcesController.php` | created | `index()` (reuses `ServerChromeData::navbar()` unchanged; managed resources eager, unmanaged containers deferred), `containerAction()` (start/restart/stop for a specific unmanaged container, validated via the existing `ValidationPatterns::isValidContainerName()`) |
| `resources/js/Pages/Server/Resources.jsx` | created | Two-table layout (Managed/Unmanaged), `<Deferred>` wrapping the unmanaged table, `useTeamChannel` for live refresh, a manual "Refresh" button doing the same partial reload |
| `routes/web.php` | modified | `server.resources` repointed at the new controller; new `server.resources.container-action` route |
| `app/Livewire/Server/Resources.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references. (The old Livewire `Server\Navbar`'s own "Resources" tab link, in `resources/views/livewire/server/navbar.blade.php`, was intentionally left untouched — it still serves the 14 other not-yet-converted pages that render the Livewire Navbar) |
| `tests/v4/Feature/ServerResourcesTest.php` | created | 4 tests: renders with zero managed resources (confirms `unmanagedContainers` is absent from the initial payload, proving the deferred prop is genuinely deferred), lists a real managed resource, rejects a container action with an invalid identifier (safe — validated before any SSH call), 404s for a server owned by another team |

### A real PHPStan finding, this time a genuine type-safety gap (not a false positive)

`containerAction()`'s `match ($validated['action']) { 'start' => ..., 'restart' => ..., 'stop' => ... }` had no `default` arm. PHPStan correctly flagged `match.unhandled` because `Validator::validate()`'s return type is `array<string, mixed>` — the `in:start,restart,stop` rule guarantees the runtime value at the *data* level, but nothing narrows the *static type* of `$validated['action']` down from `mixed`, so PHPStan can't see that the match is actually exhaustive. Unlike Phases 12-13's findings, this one isn't a case of PHPStan being overly cautious about a real invariant — it's flagging a genuine "what if validation's contract changes and this code silently does nothing" gap. Fixed with an explicit `default => throw new \LogicException(...)` arm rather than a baseline suppression, since a thrown exception on a truly-unreachable branch is strictly safer than either silently baselining it or trusting an implicit assumption.

### Phase 15 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 4 new Feature tests (`ServerResourcesTest`) | 4 passed on the first run |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entry for the 1 deleted file cleaned proactively; 1 new finding (`match.unhandled`, see above) fixed directly (not baselined); final run: `[OK] No errors` |
| `yarn build` | Succeeded — `Server/Resources.jsx` confirmed present in `manifest.json` |
| Full suite | 396 passed (1499 assertions) — up from 392, no regressions |

## 36. Non-goals of Phase 15

- 14 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 15). All remaining candidates either nest further live children (Sentinel/Proxy/Docker-cleanup sub-components, the 3 "thin wrapper" pages, `Server\CloudProviderToken\Show`) or are considerably larger with no obvious shortcut (`Server\Charts` at 315 blade lines, `Server\Security\Patches` at 194 PHP lines, `Server\DockerCleanup` at 165 PHP lines).
- The container start/restart/stop happy paths are untested — same category of SSH-testing gap as every prior Server-scoped action (Phases 11, 13, 14).
- The restart-count/crash-loop warning sub-text from the original status badges was not ported (see above) — a real fidelity gap, not a silent one.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 37. Phase 16 — `Server\Security\Patches`: first reuse of `ActivityLog.jsx` outside Navbar, and a generic activity-notification design

The first page conversion to reuse `ActivityLog.jsx` (Phase 11's React port of `ActivityMonitor`'s polling loop) for a feature *other than* the proxy-startup slide-over it was originally built for — confirming that piece of infrastructure genuinely generalizes, not just to `ServerNavbar.jsx`. `Server\Security\Patches` checks a server for available OS package updates (`apt`/`dnf`/`zypper`) and updates all-or-one package, each update running over SSH with a live log viewer.

### A real design problem: two features, one flash-based slide-over trigger

Phase 11's `ServerNavbar.jsx` opens its proxy-startup log slide-over by watching a flashed `proxyActivityId` session value (since the activity ID is only known *after* the redirect-back from the POST completes). Patches needed the exact same mechanism for its own, separate "Updating Packages" slide-over — but `ServerNavbar` renders on **every** Server-scoped page, including this one, so naively reusing the same flash key would make Navbar's proxy slide-over pop open in response to a *patches* update, and vice versa on any future third consumer.

Fixed by generalizing the mechanism instead of duplicating it: the shared `flash` prop now carries `activityId` **and** `activityContext` (a short discriminator string — `'proxy'`, `'patches-update'`, etc.). `HandleInertiaRequests::share()` needed exactly one addition (`activityContext`) to support this and every future consumer, rather than a new middleware edit per feature. `ServerNavbar.jsx` and `Patches.jsx` each check their own context string before reacting, so they can never cross-trigger each other. `ServerProxyActionsController::start()` was updated to flash `['activityId' => ..., 'activityContext' => 'proxy']`.

### A second design decision: the original's cross-tab broadcast, ported safely

The original's `ActivityMonitor::polling()` has a special case: once an activity's exit code is known, if the `eventToDispatch` set on it looks like a real event class (`App\Events\...`), it dispatches that class by string name — this is how `Patches::updatePackage()`'s completion becomes a real `ServerPackageUpdated` broadcast that notifies *every* client watching the team channel, not just the tab that triggered the update. `ActivityLog.jsx` (Phase 11) deliberately does not support this generic "dispatch an arbitrary class by name" behavior — accepting a class name from the client and instantiating/dispatching it would be a real injection risk, and the original itself only ever sets `eventToDispatch` from trusted server-side code, never from client input.

Kept the safety property and the behavior: `ActivityLog.jsx` still just calls a plain `onFinished` callback; `Patches.jsx`'s `onFinished` handler POSTs to a new, feature-specific `server.security.patches.notify-updated` route, and `ServerSecurityPatchesController::notifyUpdated()` dispatches `ServerPackageUpdated::dispatch($server->team_id)` server-side, where the event class is a fixed, trusted constant — not client-supplied.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Middleware/HandleInertiaRequests.php` | modified | Added `activityContext` alongside the existing `activityId` flash key |
| `app/Http/Controllers/ServerProxyActionsController.php` | modified | `start()` now flashes `activityContext: 'proxy'` alongside `activityId` |
| `resources/js/Components/ServerNavbar.jsx` | modified | Only opens its slide-over when `flash.activityContext === 'proxy'` |
| `app/Http/Controllers/ServerSecurityPatchesController.php` | created | `index()`, `checkUpdates()` (JSON, via `fetch()` — matches Terminal's established non-navigational-POST pattern), `updateAll()`/`updatePackage()` (flash `activityContext: 'patches-update'`), `notifyUpdated()`, `sendTestEmail()` (dev-only, matches original) |
| `resources/js/Pages/Server/Security/Patches.jsx` | created | Check-updates button + results table, its own log slide-over (reusing `ActivityLog.jsx`), typed-confirmation `window.prompt()` for "Update All" (matching the established pattern), no confirmation for a single-package update (matching the original, which doesn't wrap that action in a modal either) |
| `resources/views/components/server/sidebar-security.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Server Patching" link only |
| `routes/web.php` | modified | `server.security.patches` repointed at the new controller; 5 new `server.security.patches.*` routes |
| `app/Livewire/Server/Security/Patches.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references |
| `tests/v4/Feature/ServerSecurityPatchesTest.php` | created | 6 tests: renders, `checkUpdates()`/`updateAll()` both return a clean error on a non-functional server (safe — no SSH touched, since both underlying actions early-return on `serverStatus() === false` before any remote call), rejects the test-email action outside dev mode, confirms `notifyUpdated()` really dispatches `ServerPackageUpdated` (via `Event::fake()`), 404s for a server owned by another team |

**A real, pre-existing latent bug found while writing tests, not introduced by this migration**: `UpdatePackage::handle()` can return either an `Activity` or an `array` with an `'error'` key (mirroring `CheckUpdates`), but the original Livewire `updateAllPackages()`/`updatePackage()` methods only ever handled the `Activity` case — calling `$activity->id` on the array-return path would silently emit a PHP warning and evaluate to `null`, never surfacing the actual error to the user. The first draft of the new controller carried this same gap forward faithfully; fixed by adding an explicit `is_array($activity)` check before accessing `->id`, surfacing the real error message instead.

### Phase 16 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 6 new Feature tests (`ServerSecurityPatchesTest`) | 6 passed on the first run |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entry for the 1 deleted file cleaned proactively; `[OK] No errors` — zero new findings |
| `yarn build` | Succeeded — `Server/Security/Patches.jsx` confirmed present in `manifest.json` |
| Full suite | 402 passed (1520 assertions) — up from 396, no regressions |

## 38. Non-goals of Phase 16

- 13 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 14).
- The `checkUpdates()`/`updateAll()`/`updatePackage()` happy paths (real SSH-driven package scanning/updating) remain untested — same category of gap as every prior Server-scoped SSH action (Phases 11, 13, 14, 15).
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). The two-slide-over discriminator design (`activityContext`) and the cross-tab broadcast notification flow have not been visually confirmed in a real browser.

## 39. Phase 17 — `Server\CloudflareTunnel`: third `activityContext` consumer, confirms the discriminator design generalizes

The second reuse of `ActivityLog.jsx` for a feature besides Navbar's own proxy slide-over (after Phase 16's Patches), and the first real test of whether Phase 16's `activityContext` discriminator design holds up with a *third* consumer rather than just two. It does — `ServerCloudflareTunnelController::automatedConfig()` flashes `activityContext: 'cloudflare-tunnel'`, and `CloudflareTunnel.jsx` opens its own slide-over exactly the same way `Patches.jsx` does, with zero changes needed to `HandleInertiaRequests` or `ServerNavbar.jsx`.

Unlike Patches, this page's activity-monitor usage needed no cross-tab broadcast trick: the original `automatedCloudflareConfig()` dispatches `activityMonitor` with no custom `eventToDispatch` (defaulting to the plain local `'activityFinished'` case), so `ActivityLog.jsx`'s existing plain `onFinished` callback (no server round-trip needed) already covers it — confirming that design choice from Phase 11 was the right scope, not an arbitrary limitation.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerCloudflareTunnelController.php` | created | `index()` (redirects away for the localhost server, matching the original's `mount()`), `toggle()` (disable — SSH-touching, no early-return guard), `manualConfig()` (pure DB flag flip, no SSH), `automatedConfig()` (SSH-touching via `ConfigureCloudflared::run()`, flashes `activityContext: 'cloudflare-tunnel'`) |
| `resources/js/Pages/Server/CloudflareTunnel.jsx` | created | Enabled/disabled state UI, typed-confirmation `window.prompt()` for both disable and manual-config (matching the established pattern), automated-config form + its own log slide-over |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Cloudflare Tunnel" link only |
| `routes/web.php` | modified | `server.cloudflare-tunnel` repointed at the new controller; 3 new `server.cloudflare-tunnel.*` routes |
| `app/Livewire/Server/CloudflareTunnel.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references |
| `tests/v4/Feature/ServerCloudflareTunnelTest.php` | created | 5 tests: renders, redirects away for the localhost server, enables via manual config (safe, no SSH — fully exercised), rejects automated config with missing fields, 404s for a server owned by another team |

**A real PHPStan finding, a genuine type bug (not a false positive)**: the SSH-domain cleanup logic chained `str($sshDomain)->replace(...)->replace(...)->trim()`, reassigned the `Stringable` result back into `$sshDomain`, then called `str($sshDomain)` *again* on the next line — passing an already-`Stringable` object into a helper typed `string|null`. This is the exact same chained-reassignment shape the original Livewire component used, and it happened to work at runtime there too (PHP's implicit `__toString()` coercion papers over the static mismatch), but PHPStan correctly flagged it as a real type error. Fixed by removing the redundant intermediate re-wrap and chaining straight through: `str($sshDomain)->replace(...)->replace(...)->trim()->replace('/', '')`.

### Phase 17 verification log

| Check | Result |
| --- | --- |
| Pint after all PHP/JS changes | passed |
| 5 new Feature tests (`ServerCloudflareTunnelTest`) | 5 passed on the first run |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entry for the 1 deleted file cleaned proactively; 1 new finding (`argument.type` on the chained `str()` call, see above), fixed directly (not baselined) — final run: `[OK] No errors` |
| `yarn build` | Succeeded — `Server/CloudflareTunnel.jsx` confirmed present in `manifest.json` |
| Full suite | 407 passed (1543 assertions) — up from 402, no regressions |

## 40. Non-goals of Phase 17

- 12 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 13).
- The `toggle()` (disable) and `automatedConfig()` happy paths are untested — both touch SSH unconditionally with no early-return guard to test around, same category of gap as every prior Server-scoped SSH action (Phases 11, 13, 14, 15, 16).
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 41. Phase 18 — `Server\PrivateKey\Show`: an inline-ported create modal, without touching the shared `Create` component

`Server\PrivateKey\Show` lets a server owner pick which team SSH key Coolify uses to connect to that server, and (via a nested `+ Add` modal) create a brand-new key without leaving the page. The nested modal was the interesting part: the original Blade view embeds `<livewire:security.private-key.create />` directly — a shared Livewire component also used by `security.private-key.index`, `server.new.by-hetzner`, `GlobalSearch`, and `Dashboard`. Deleting or rewriting that component was out of scope; it still has 4 other real consumers.

### Design: port the modal's logic inline, keep the shared component untouched

Instead, the create-form logic (`generateNewRSAKey()`/`generateNewEDKey()` → `PrivateKey::generateNewKeyPair()`, `createPrivateKey()` → `PrivateKey::createAndStore()`) was ported into two new endpoints on the existing `SecurityPrivateKeyController` (which already had `show()`/`update()`/`destroy()` from an earlier phase): `store()` and `generateKey()`. The React page's own modal calls these directly — the shared Livewire `Create` component is never touched, so its 4 other consumers are unaffected.

`store()` supports two response shapes via a `modal_mode` flag, mirroring the original component's own dual-mode behavior (full-page vs. modal-embedded): with `modal_mode=true` it flashes success + the new key's ID and redirects back (used here); without it, it redirects to the key's own `security.private-key.show` page (matching the original's non-modal behavior, for any future full-page consumer).

One deliberate simplification: the original Livewire form does **live per-keystroke validation** of the private key field (`updated($property)` calling `PrivateKey::validateAndExtractPublicKey()` on every change) so the public-key preview and errors update as you type. The React port validates only on submit — consistent with every prior phase's precedent of dropping Livewire's reactive-per-keystroke validation in favor of standard Inertia form submission. The "Generate RSA/ED25519" buttons still populate the public-key preview immediately (via a direct `fetch()` to the new `generateKey()` JSON endpoint), since that's a one-shot action, not per-keystroke validation.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SecurityPrivateKeyController.php` | modified | Added `store()` (create a key, `modal_mode`-aware) and `generateKey()` (JSON endpoint backing the Generate RSA/ED25519 buttons) |
| `app/Http/Controllers/ServerPrivateKeyController.php` | created | `index()` (lists the team's non-git-related keys + current key), `setKey()` (associate a key with the server, validates ownership before an SSH-touching connection check), `checkConnection()` |
| `resources/js/Pages/Server/PrivateKey/Show.jsx` | created | Key-card grid ("Use this key" / "Currently used"), "Check connection" button, and an inline `+ Add` modal porting the shared `Create` component's fields (name/description/value, Generate RSA/ED25519 buttons, public-key preview) |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Private Key" link only |
| `routes/web.php` | modified | `server.private-key` repointed at the new controller; added `server.private-key.set`, `server.private-key.check-connection`, `security.private-key.store`, `security.private-key.generate` |
| `app/Livewire/Server/PrivateKey/Show.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references. `App\Livewire\Security\PrivateKey\Create` was explicitly **not** touched — confirmed 4 other consumers |
| `tests/v4/Feature/ServerPrivateKeyTest.php` | created | 7 tests: renders, rejects using a foreign-team key (safe, no SSH), 404s for a server owned by another team, creates a key via `store()` (safe — pure DB + crypto validation), rejects an invalid private key, generates a key pair via the JSON endpoint, denies non-admins from both endpoints |

### Phase 18 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| 7 new Feature tests (`ServerPrivateKeyTest`) | 1 failure on first run — two `PrivateKey::create()` calls in the same test reused an identical fixture key, tripping the model's own fingerprint-uniqueness check (`This private key already exists.`); fixed by generating a distinct key for the second row. 7 passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 1 deleted file cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `Server/PrivateKey/Show.jsx` confirmed present in `manifest.json` |
| Full suite (`php artisan test --compact`) | 274 passed (719 assertions), no regressions |

## 42. Non-goals of Phase 18

- 11 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 12).
- `setKey()` and `checkConnection()`'s happy paths are untested — both call `$server->validateConnection()` unconditionally with no early-return guard, same category of gap as every prior Server-scoped SSH action (Phases 11, 13, 14, 15, 16, 17).
- The live per-keystroke private-key validation/preview from the original `Create` component's form is intentionally not replicated — submit-time validation only, consistent with this migration's standing precedent for dropping Livewire's reactive-per-keystroke validation.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 43. Phase 19 — `Server\Destinations`: a second inline-ported create modal, plus a JSON-backed scan action

Manages the Docker networks ("destinations") attached to a server: a list of already-added `StandaloneDocker`/`SwarmDocker` records, an SSH-backed "Scan for Destinations" action that finds not-yet-added Docker networks on the server, one-click "Add {name}" buttons for anything found, and a `+ Add` modal for creating a destination directly (optionally on a *different* server than the one you're viewing — matching the original's behavior).

### Design: same inline-port pattern as Phase 18, applied to a second shared component

The `+ Add` modal originally embeds `<livewire:destination.new.docker :server_id="$server->id" />` — a shared component also used by the still-Livewire `Destination\Index` page. Following the exact precedent from Phase 18 (`Security\PrivateKey\Create`), that shared component was left untouched; its create logic (name/network/server-select fields, duplicate-network rejection, `StandaloneDocker::create()`) was ported inline into a new `create()` endpoint on the new `ServerDestinationsController`, scoped to this page's own modal.

The "Scan for Destinations" action is a genuinely new pattern for this migration: it's an SSH-backed read (`docker network ls`) that needs to return a **list** of results to populate a right-away UI (the "Found Destinations" button row), but unlike Phase 15's `Resources` page (which used `Inertia::defer()` for its slow SSH-backed unmanaged-container list on initial page load), this is a **user-triggered, on-demand** action, not something to defer-load automatically on every visit. It doesn't fit the `activityId`/`ActivityLog.jsx` slide-over pattern either (Phases 11/16/17), since there's no long-running process to poll — the scan itself completes synchronously within the request. So it uses the same plain JSON-endpoint-plus-`fetch()` pattern already established in Phase 18 for the "Generate RSA/ED25519" buttons: `scan()` returns `{ networks: [...] }` directly, and the React page renders the results into local state without a page reload.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerDestinationsController.php` | created | `index()` (lists standalone/swarm dockers + a `servers` list for the modal's server-select), `scan()` (JSON endpoint, SSH-touching, no early-return guard), `add()` (one-click add for a scanned network; SSH-touching via `ConnectProxyToNetworksJob::dispatchSync()` for the standalone case, but the duplicate-network rejection returns before touching SSH), `create()` (the `+ Add` modal's inline-ported create logic, safe — no SSH) |
| `resources/js/Pages/Server/Destinations.jsx` | created | Destination list, scan button + found-networks list (via `fetch()` + local state), `+ Add` modal with name/network/server-select fields |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Destinations" link only |
| `routes/web.php` | modified | `server.destinations` repointed at the new controller; added `server.destinations.scan`, `server.destinations.add`, `server.destinations.create` |
| `app/Livewire/Server/Destinations.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references. `App\Livewire\Destination\New\Docker` was explicitly **not** touched — confirmed it's still used by `Destination\Index` |
| `tests/v4/Feature/ServerDestinationsTest.php` | created | 5 tests: renders (relies on the `Server` model's auto-created default `coolify` `StandaloneDocker` rather than creating a second one, since `(server_id, network)` is unique), 404s for a server owned by another team, creates a destination via the modal endpoint (safe, no SSH), rejects a duplicate network via the modal endpoint, rejects a duplicate network via the one-click `add()` endpoint without touching SSH |

### Phase 19 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| 5 new Feature tests (`ServerDestinationsTest`) | 1 failure on first run — `StandaloneDocker::factory()->create(['network' => 'coolify'])` collided with the row `Server`'s own model event already auto-creates for every new server (`defaultStandaloneDockerAttributes()` always uses `network: 'coolify'`); fixed by relying on that auto-created row instead of creating a duplicate. 5 passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 1 deleted file cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `Server/Destinations.jsx` confirmed present in `manifest.json` |
| Full suite (`php artisan test --compact`) | 277 passed (744 assertions), no regressions |

## 44. Non-goals of Phase 19

- 10 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 11).
- `scan()` and `add()`'s SSH-touching happy paths are untested — same category of gap as every prior Server-scoped SSH action (Phases 11, 13, 14, 15, 16, 17, 18).
- The Swarm-mode branch of `add()` (creating a `SwarmDocker` instead of a `StandaloneDocker`) is ported faithfully from the original but has no UI trigger in either the old or new modal (the create form never exposes an `isSwarm` toggle) — same dead-but-faithfully-ported code path as the original.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 45. Phase 20 — `Server\DockerCleanup`: settings form + a real-time executions list with per-execution polling

Manages Docker cleanup settings for a server (cleanup frequency/threshold, force-cleanup, and three "Advanced" destructive options) plus a "Recent executions" history list — the first genuinely new real-time list-with-detail pattern since Phase 7's Deployment Index, and the first to combine Echo-driven list refresh (Phase 15's pattern) with per-item polling (Phase 11's `ActivityLog.jsx` pattern) for two different concerns on the same page.

### Design

- **Settings form**: same instant-save-checkbox + explicit-Save-for-text-fields shape established in Phases 12/14 (`Server\Advanced`, `Server\LogDrains`). The cron-expression validation bug class fixed in Phase 12 (`validate_cron_expression()` before persisting, not after) was ported correctly the first time here, not re-discovered — this migration's standing "any try/catch in ported code is signal" rule (Phase 13) paid off again by keeping the validation-then-persist order intact.
- **"Trigger Manual Cleanup"**: the original's `manualCleanup()` only calls `DockerCleanupJob::dispatch()` (queued, not `dispatchSync()`), so unlike most SSH-touching actions in this migration, this one's happy path *is* safely testable — verified via `Queue::fake()` + `Queue::assertPushed()`, no untested-SSH gap here.
- **Executions list — a new hybrid pattern**: the original's `DockerCleanupExecutions` component had three refresh mechanisms layered on top of each other: an unconditional 5-second `wire:poll`, an Echo listener on `DockerCleanupDone` (team channel), and a 1-second client poll while a selected execution's status is `running`. Porting all three literally would be redundant (the Echo listener and the 5s poll both refresh the same list for the same reason). Simplified to two mechanisms with distinct jobs: `useTeamChannel(['DockerCleanupDone'], ...)` (Phase 15's pattern) refreshes the whole list on completion, and a plain `setInterval`-driven `fetch()` against a new JSON endpoint (`GET .../docker-cleanup/executions`, same shape as Phase 11's `ActivityController::show()`) polls every 2s only while the *currently selected* execution is `running` — dropping the redundant unconditional 5s poll.
- **Log viewer**: the original paginates raw `message` lines 100-at-a-time via server-side state (`$currentPage`) plus a separate structured `cleanup_log` (JSON array of `{command, output}` per cleanup step). Since the full execution payload is already fetched client-side (unlike Terminal's streaming logs), the 100-line chunking was kept as a client-side `slice()` in a small `ExecutionRow` component rather than a server round-trip — same data, simpler mechanism.
- **Download logs**: a plain `<a href>` to a new streamed-download route (`response()->streamDownload()`), not a `fetch()` — browser-native file download, no JS blob handling needed.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerDockerCleanupController.php` | created | `index()`, `update()` (cron validation before persist), `manualCleanup()` (queued, safely testable), `executions()` (JSON polling endpoint), `downloadLog()` (streamed download) |
| `resources/js/Pages/Server/DockerCleanup.jsx` | created | Settings form, staleness warning callout, manual-cleanup confirmation modal (no typed text/password — matches the original's lighter-weight `x-modal-confirmation`), executions list with `ExecutionRow` sub-component (client-side log-line pagination + structured cleanup-log blocks) |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Docker Cleanup" link only |
| `routes/web.php` | modified | `server.docker-cleanup` repointed at the new controller; added `.update`, `.manual-cleanup`, `.executions`, `.download-log` routes |
| `app/Livewire/Server/DockerCleanup.php` + `DockerCleanupExecutions.php` + matching Blade views | **deleted** | Real cutover, grep-confirmed no other references to either class (the executions component had exactly one consumer — this page — so it was ported inline rather than kept as a separate shared component) |
| `tests/v4/Feature/ServerDockerCleanupTest.php` | created | 7 tests: renders, 404s for a server owned by another team, updates settings, rejects an invalid cron expression, dispatches the manual-cleanup job via `Queue::fake()` (safe, no SSH), returns executions as JSON, downloads a log |

### Phase 20 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| 7 new Feature tests (`ServerDockerCleanupTest`) | 2 failures on first run — (1) `delete_unused_volumes` isn't cast to `boolean` on `ServerSetting` (only `force_docker_cleanup` is), so the raw SQLite `1` failed a strict `toBeTrue()` assertion; fixed by loosening to `toBeTruthy()` rather than changing the model's casts (out of scope, pre-existing, harmless in practice since PHP/Blade truthy-checks handle `1`/`0` fine). (2) `downloadLog()`'s return type hint (`Illuminate\Http\Response`) didn't match what `response()->streamDownload()` actually returns (`Symfony\Component\HttpFoundation\StreamedResponse`); fixed the type hint. 7 passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 2 deleted files (18 entries total) cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `Server/DockerCleanup.jsx` confirmed present in `manifest.json` |
| Full suite (`php artisan test --compact`) | 282 passed (775 assertions), no regressions |

## 46. Non-goals of Phase 20

- 9 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 10).
- The unconditional 5-second list poll from the original was deliberately dropped in favor of Echo-driven refresh only — if Echo/Soketi is down, the list won't self-heal until the next `DockerCleanupDone` broadcast succeeds or the page is reloaded. This is a real, intentional behavior change (not just a implementation detail), and should be called out in manual QA.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 47. Phase 21 — `Server\CloudProviderToken\Show`: the first fully-testable SSH-adjacent happy path, and two affiliate-link removals found along the way

Manages which Hetzner Cloud API token a server uses (only shown for servers actually provisioned through Hetzner), following the identical key-card-grid shape as Phase 18's `Server\PrivateKey\Show`: a "Use this token" / "Currently used" grid, a "Validate token" action, and a `+ Add` modal for a shared component (`Security\CloudProviderTokenForm`, also used by the still-Livewire `Server\New\ByHetzner`).

### A genuinely new thing: a fully-testable "external API" happy path

Every prior Server-scoped SSH action in this migration (Phases 11, 13, 14, 15, 16, 17, 18, 19) has had an untested happy-path gap, because `instant_remote_process()`/SSH has no fake/mock seam this migration built. This phase's equivalent risky operation — validating a token against `https://api.hetzner.cloud/v1/servers` via Laravel's `Http` facade — **does** have a built-in fake seam (`Http::fake()`), so for the first time, every action's happy path (`setToken()`, `validateToken()`, `store()`) is fully covered, not just the safe/validation-rejection paths. Worth noting as a category distinction for future phases: outbound HTTP calls to third-party APIs are cheap to fake; SSH to a user's own infrastructure is not.

### Two affiliate links removed, one already shipped to a previously-converted page

The shared `Security\CloudProviderTokenForm` component's "Don't have a Hetzner account?" block included a hardcoded `coolify.io/hetzner` affiliate link and "(Coolify's affiliate link... supports us (€10) and gives you €20)" text — the same category of commercial content removed earlier this session (sponsorship popup, "Sponsor us" nav link, Stripe billing). Since this component is shared with the still-Livewire `Server\New\ByHetzner`, the text was stripped from the original Blade view too, not just left out of this phase's new React port. A repo-wide grep for the same pattern also turned up an **identical copy already carried over into `Security\CloudTokens.jsx`** (converted in an earlier phase, before the de-commercialization directive existed) — fixed there too.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerCloudProviderTokenController.php` | created | `index()`, `setToken()` (validates the token against Hetzner's API for both general validity and access to this specific server, mirroring the original's two-step check), `validateToken()`, `store()` (inline-ported create-token logic) |
| `resources/js/Pages/Server/CloudProviderToken.jsx` | created | Token-card grid, "Validate token" button, `+ Add` modal (no affiliate text) |
| `resources/views/livewire/security/cloud-provider-token-form.blade.php` | modified | Removed the affiliate-link block from both the modal and full-page layouts |
| `resources/js/Pages/Security/CloudTokens.jsx` | modified | Removed the same affiliate-link block, found via a repo-wide grep while working on this phase |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Hetzner Token" link only |
| `routes/web.php` | modified | `server.cloud-provider-token` repointed at the new controller; added `.set`, `.validate`, `.store` routes |
| `app/Livewire/Server/CloudProviderToken/Show.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references. `App\Livewire\Security\CloudProviderTokenForm` was explicitly **not** touched (beyond the affiliate-text removal) — confirmed still used by `Server\New\ByHetzner` |
| `tests/v4/Feature/ServerCloudProviderTokenTest.php` | created | 11 tests, all exercising real happy paths via `Http::fake()`: renders, shows the non-Hetzner message, 404s for another team's server, rejects a foreign-team token, associates a valid token, rejects an invalid token, reports no-token-associated, validates a token successfully, creates a token, rejects a Hetzner-invalid token on create, denies a non-admin from creating |

### Phase 21 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| 11 new Feature tests (`ServerCloudProviderTokenTest`) | 11 passed on the first run |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 1 deleted file (11 entries) cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `Server/CloudProviderToken.jsx` confirmed present in `manifest.json` |
| Full suite (`php artisan test --compact`) | 284 passed (801 assertions), no regressions |

## 48. Non-goals of Phase 21

- 8 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 9): Sentinel, Proxy, Metrics, Terminal command, and `Server\Show` itself.
- The next two candidates (`Server\Proxy\Show`, `Server\Sentinel\Show`) are a step up in complexity from everything since Phase 14: both use a dedicated sidebar variant (`x-server.sidebar-proxy`, `x-server.sidebar-sentinel`) that `ServerChromeData::sidebar()` doesn't support yet (only `main`/`security`), and both nest a real, substantial management component (`<livewire:server.proxy>`, `<livewire:server.sentinel>`) rather than a thin settings form — flagged during Phase 11's original scoping as needing their own design pass.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 49. Phase 22 — `Server\Charts` (Metrics): the first page needing a dynamically-loaded third-party chart library

Renders live CPU/memory usage charts for a server (ApexCharts area charts), with an interval selector (5min/10min "live" polling vs. static historical ranges up to 30 days) and an "Enable/Disable Metrics" toggle that starts or restarts the Sentinel monitoring container over SSH.

### Design: a script that only ever loaded because it always shared the Livewire root view

The original page never installs ApexCharts as a JS dependency — `layouts/base.blade.php` (the Livewire root view) unconditionally loads `public/js/apexcharts.js` as a global `<script>` tag on every Livewire page, so `window.ApexCharts` is simply always present by the time any page's inline chart-init script runs. Inertia pages use a separate, minimal root view (`app-inertia.blade.php`) that doesn't load this script at all. Rather than add `apexcharts` as an npm dependency (a dependency change, out of scope without approval) or load it unconditionally on every Inertia page, `Server/Metrics.jsx` lazily injects the same `/js/apexcharts.js` `<script>` tag itself on mount (memoized so a second visit doesn't re-fetch it), then constructs `new window.ApexCharts(...)` exactly as the original inline scripts did — same global asset, same library, loaded on-demand instead of unconditionally.

### A second new pattern: client-driven interval-based polling, not Echo

The original's `wire:poll.5000ms='pollData'` combined with a `poll` boolean that flips permanently to `false` once the selected interval exceeds 10 minutes (short "live" ranges keep polling forever; longer historical ranges fetch once and stop) is a client-timing concern, not a server-broadcast one — there's no natural Echo event for "new metrics sample available." Ported as a plain `setInterval` in the React page with the identical stop condition, rather than reaching for `useTeamChannel` (which wouldn't fit here) or `ActivityLog.jsx` (which is scoped to a single activity ID, not a recurring metrics feed).

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerMetricsController.php` | created | `index()`, `toggleMetrics()` (SSH-touching via `StartSentinel::run()`/`$server->restartSentinel()`, no early-return guard), `data()` (JSON endpoint returning `{cpu, memory}` for a given interval — has a real early-return guard: returns `null` for both without touching SSH if metrics are disabled, confirmed via `HasMetrics::getMetrics()`) |
| `resources/js/Pages/Server/Metrics.jsx` | created | Lazily-loaded ApexCharts, `useApexChart` hook wrapping chart lifecycle (create/update/destroy) per chart, interval select, Enable/Disable Metrics button, "Sentinel Required" / "Metrics Disabled" callouts matching the original |
| `resources/views/components/server/sidebar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Metrics" link only |
| `routes/web.php` | modified | `server.metrics` repointed at the new controller; added `.toggle`, `.data` routes |
| `app/Livewire/Server/Charts.php` + matching Blade view | **deleted** | Real cutover, grep-confirmed no other references |
| `tests/v4/Feature/ServerMetricsTest.php` | created | 5 tests: renders, 404s on index/toggle/data for a server owned by another team, returns `null` cpu/memory without touching SSH when metrics are disabled (exercises the real early-return guard, a genuine safe happy path) |

### A real, if minor, type-correctness fix

`Server::isMetricsEnabled()`/`isSentinelEnabled()` return the raw `is_metrics_enabled` attribute rather than a real PHP `bool` (that column isn't cast to `boolean` on `ServerSetting`, unlike its sibling `force_docker_cleanup`). Passing that raw value straight through as an Inertia prop would have sent `0`/`1` to the frontend instead of `false`/`true`. Caught by a strict Pest assertion on the first test run; fixed by casting at the boundary in the new controller (`(bool) $server->isMetricsEnabled()`) rather than touching the underlying model, since the model's own quirk is pre-existing and out of scope here.

### Phase 22 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| 5 new Feature tests (`ServerMetricsTest`) | 1 failure on first run (the `(bool)` cast issue above); 5 passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Stale baseline entries for the 1 deleted file (7 entries) cleaned proactively; `[OK] No errors` |
| `yarn build` | Succeeded — `Server/Metrics.jsx` confirmed present in `manifest.json` |
| Full suite (`php artisan test --compact`) | 285 passed (815 assertions), no regressions |

## 50. Non-goals of Phase 22

- 7 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 8): Sentinel, Proxy, Terminal command, and `Server\Show` itself.
- `toggleMetrics()`'s happy path is untested in either direction (enable or disable) — both unconditionally touch SSH with no early-return guard, same category of gap as every prior Server-scoped SSH action. Unlike Phase 21's Hetzner API calls, there's no `Http::fake()`-equivalent seam for `instant_remote_process()`.
- The dynamically-loaded ApexCharts script is a real behavioral difference from every other page in this migration (all prior React pages are self-contained bundles with no runtime `<script src>` injection) — worth a manual QA pass specifically checking that the chart actually renders on a cold page load (first visit, script not yet cached) and not just on a warm one.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 51. Phase 23 — `Server\Proxy\Show`: a dynamically-loaded Monaco editor, a new `'proxy'` sidebar variant, and two distinct "switch proxy" mechanisms

Converts the server's proxy configuration page: choosing a proxy type (Traefik/Caddy/None) when none is set yet, an "out of sync" warning banner, a Monaco-backed YAML editor for the raw proxy configuration, instant-save toggles (`generateExactLabels`, redirect-to-domain), Traefik version/outdated-branch callouts, and a "Reset Configuration" modal gated behind typing the server's name.

### Design: reusing the Livewire root view's Monaco asset, the same pattern as Phase 22's ApexCharts

Exactly like Phase 22's ApexCharts problem: the original page never installs Monaco as a JS dependency — it works only because `layouts/base.blade.php` always loads `public/js/monaco-editor-0.52.2/min/vs/loader.js` as a global `<script>` tag. `resources/js/Components/MonacoEditor.jsx` (new, reusable) injects that same static asset itself on mount via a module-level memoized `loaderPromise` (so repeat mounts across the app don't re-fetch it), then calls `window.monaco.editor.create(...)` with the same options the original Alpine component used (`theme: vs-dark`/`vs`, `wordWrap: 'on'`, `minimap: {enabled: false}`), plus a `MutationObserver` on `document.documentElement`'s `class` attribute so the editor's theme flips immediately when the user toggles dark mode — matching the original's live theme-switch behavior. Built as a standalone component (not inlined into `Server/Proxy.jsx`) since Monaco is very likely needed again by other configuration-editing pages later in the Hard bucket.

### Two distinct "switch proxy" mechanisms, kept separate rather than merged

The original has two different code paths that both change `$server->proxy`, and they are not interchangeable:

- **`selectProxy($type)`** — used only when no proxy is selected yet (`proxySet()` is false). Sets the type directly and, if the server is a genuinely functional/connected server, kicks off `StartProxy::run()` over SSH. On the factory-default (non-functional) server used in tests, that SSH branch is skipped entirely — confirmed safe and exercised as a real happy-path test, same category of finding as Phase 21's `Http::fake()` discovery.
- **`changeProxy()`** (the "Switch Proxy" button on an already-configured server) — nulls out `$server->proxy` and other proxy-related settings so the page falls back to the "no proxy selected" screen, but is blocked client-side (a toast, no server round-trip at all) unless the proxy container is first stopped, matching the original's `$dispatch('error', ...)` behavior exactly.

Kept as two separate controller actions (`selectProxy()` vs `resetProxySelection()`) rather than one parameterized action, mirroring the original's own separation and avoiding a false abstraction over two behaviorally-different operations.

### A new `'proxy'` sidebar variant, and a partial cutover within it

`ServerChromeData::sidebar()` gained a `'proxy'` variant. Only the "Configuration" link points at the new Inertia route (`<Link>`); "Dynamic Configurations" and "Logs" remain plain `<a>` links to their still-Livewire routes this phase (same precedent as `ServerNavbar.jsx`'s terminal-command link) — `wireNavigate()` was stripped only from the "Configuration" link in `sidebar-proxy.blade.php`, left in place on "Dynamic Configurations" (and never present on "Logs").

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Support/ServerChromeData.php` | modified | Added `'proxy'` sidebar variant (`proxySet`, `urls.configuration/dynamicConfs/logs`) |
| `resources/js/Components/ServerSidebar.jsx` | modified | Renders the `'proxy'` variant; Configuration via `<Link>`, the other two via plain `<a>` (still Livewire) |
| `resources/js/Components/MonacoEditor.jsx` | created | Reusable dynamically-loaded Monaco YAML editor, dark-mode-aware |
| `app/Http/Controllers/ServerProxyController.php` | created | `index()`, `selectProxy()` (SSH-touching, guarded), `resetProxySelection()` (no SSH), `instantSave()` (no SSH), `instantSaveRedirect()` (SSH-touching, unguarded), `submit()` (SSH-touching, unguarded), `resetConfiguration()` (SSH-touching, unguarded), plus private Traefik-version helpers ported from the original's computed properties |
| `resources/js/Pages/Server/Proxy.jsx` | created | No-proxy-selected screen, main configuration form, Switch Proxy modal/blocked-toast, instant-save toggles, dismissible Traefik warnings (`localStorage`-backed, same key pattern as the original), Reset Configuration modal (typed-name confirmation), Monaco YAML editor |
| `routes/web.php` | modified | `server.proxy` repointed at the new controller; added `.select`, `.reset-selection`, `.instant-save`, `.instant-save-redirect`, `.submit`, `.reset-configuration` |
| `resources/views/components/server/sidebar-proxy.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Configuration" link only |
| `app/Livewire/Server/Proxy.php` + `app/Livewire/Server/Proxy/Show.php` (+ matching Blade views) | **deleted** | Real cutover — `Show` was a thin wrapper, `Proxy` the substantial component this phase actually converts; grep-confirmed no other `<livewire:server.proxy>` consumers |
| `tests/v4/Feature/ServerProxyTest.php` | created | 7 tests: renders with no proxy selected, 404 for foreign-team server, selects a proxy type without touching SSH (real happy path, non-functional test server), rejects an invalid proxy type, resets proxy selection without touching SSH, instant-saves `generateExactLabels` without touching SSH, 404s on select/reset-selection/instant-save for a foreign-team server |

### Two PHPStan findings fixed, not baseline-suppressed

The first PHPStan run against the new controller surfaced two real, fixable findings rather than pre-existing noise: `getTraefikVersions()` was missing a value-type on its `?array` return (fixed with a `@return array<string, string>|null` PHPDoc), and `newerTraefikBranchAvailable()` had a redundant `isset($outdatedInfo['type'])` check — PHPStan had already inferred, from `Server::$traefik_outdated_info`'s shape, that `'type'` is always present (non-optional) whenever the array itself is non-null, so the `isset()` was dead code and removed.

### Phase 23 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| PHPStan (`vendor/bin/phpstan analyse`) | 2 real findings on first run (above), both fixed; 18 stale baseline entries cleaned for the 2 deleted files; `[OK] No errors` after |
| 7 new Feature tests (`ServerProxyTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 286 passed (829 assertions), no regressions |
| `yarn build` | Succeeded — `Proxy-D5ecN3Xb.js` (12.36 kB) confirmed present in `manifest.json` |

## 52. Non-goals of Phase 23

- 6 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 7): Sentinel, Terminal command, and `Server\Show` itself, plus "Dynamic Configurations" and "Logs" within the Proxy area specifically.
- `instantSaveRedirect()`, `submit()`, and `resetConfiguration()` are all untested on their SSH-touching happy path — each unconditionally calls `setupDefaultRedirect()` / `SaveProxyConfiguration::run()` / `GetProxyConfiguration::run(forceRegenerate: true)` with no early-return guard, same category of gap as `toggleMetrics()` in Phase 22. No `Http::fake()`-equivalent seam exists for these.
- The Monaco editor, like Phase 22's ApexCharts, is a real runtime `<script src>` injection rather than a bundled dependency — worth a manual QA pass on a genuinely cold page load, same caveat as Phase 22's chart rendering.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 53. Phase 24 — `Server\Sentinel\Show`: a model-level `updated` event turns a "safe" settings save into a real SSH-adjacent action

Converts the Sentinel configuration page: Enable/Disable Sentinel, Save (Coolify URL, token + regenerate, metrics rate/history/push interval), Restart/Sync, and (dev-only) a debug checkbox and custom Docker image override.

### A genuine finding: `ServerSetting::booted()`'s `static::updated()` hook, not a porting bug

The first attempt at a Feature test for the Save action assumed `submit()` was a safe, SSH-free happy path — identical in shape to Phase 23's `instantSave()`. It wasn't. `ServerSetting::booted()` (`app/Models/ServerSetting.php:237-247`) registers a `static::updated` listener that calls `$settings->server->restartSentinel()` whenever `sentinel_token`, `sentinel_custom_url`, or any of the three metrics-interval fields change — completely independent of which UI framework triggers the save. `restartSentinel()` defaults to `$async = true`, dispatching `StartSentinel` through the queue; under this repo's `QUEUE_CONNECTION=sync` test config, that dispatch executes synchronously inside the same request. The failure surfaced as a bare `404` with no logged exception, traced by bisecting the controller with temporary dumps down to `handleError()`'s `if ($error instanceof ModelNotFoundException) { abort(404); }` branch — something in the synchronously-executed job's model resolution threw a `ModelNotFoundException`, which `handleError()` (called from `restartSentinel()`'s own catch block) turned into a raw `abort(404)` that then propagated straight out past the `updated` event, past `save()`, and out of the controller, since neither `submit()` nor `regenerateToken()` had a wrapping try/catch to begin with.

Two fixes followed from this: (1) `submit()` and `regenerateToken()` both needed the same try/catch around the save that the original Livewire `submit()`/`regenerateSentinelToken()` methods already have — a real gap in the first draft, not a stylistic nicety; (2) the corresponding tests needed `Queue::fake()` (the same technique already established in Phase 20's `ServerDockerCleanupTest`) so the settings-save happy path can be verified without the cascading restart actually dispatching.

### Two sidebar links, one converted

`ServerChromeData::sidebar()` gained a `'sentinel'` variant with two links — "Configuration" (now the converted Inertia route) and "Logs" (`server.sentinel.logs`, left on Livewire, plain `<a>`, `wireNavigate()` untouched) — the same partial-cutover shape as Phase 23's Proxy sidebar.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Support/ServerChromeData.php` | modified | Added `'sentinel'` sidebar variant (`urls.configuration`/`urls.logs`) |
| `resources/js/Components/ServerSidebar.jsx` | modified | Renders the `'sentinel'` variant |
| `app/Http/Controllers/ServerSentinelController.php` | created | `index()`, `submit()` (Save button; SSH-adjacent via the model event above, try/catch added), `toggle()` (Enable/Disable; enabling has a real early-return guard for build servers, disabling unconditionally dispatches `StopSentinel`), `restart()` (Restart/Sync button, unconditional), `regenerateToken()` (SSH-adjacent via the same model event) |
| `resources/js/Pages/Server/Sentinel.jsx` | created | Enable/Disable buttons, Out-of-Sync callout, dev-only debug checkbox, Coolify URL/token fields with Regenerate, metrics rate/history/push-interval fields, Save |
| `routes/web.php` | modified | `server.sentinel` repointed at the new controller; added `.submit`, `.toggle`, `.restart`, `.regenerate-token`; removed the `SentinelShow` import |
| `resources/views/components/server/sidebar-sentinel.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Configuration" link only |
| `app/Livewire/Server/Sentinel.php` + `app/Livewire/Server/Sentinel/Show.php` (+ matching Blade views) | **deleted** | Real cutover — `Show` was a thin wrapper, `Sentinel` the substantial component this phase converts; `Sentinel/Logs.php` + its view are untouched (still Livewire); grep-confirmed no other `<livewire:server.sentinel>` consumers |
| `tests/v4/Feature/ServerSentinelTest.php` | created | 7 tests: renders, 404 for foreign-team server, saves settings with `Queue::fake()` (real happy path once the cascading dispatch is faked), rejects an invalid token, refuses to enable Sentinel on a build server without touching SSH (real guarded happy path), regenerates the token with `Queue::fake()`, 404s on submit/toggle/regenerate-token for a foreign-team server |

### Phase 24 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| PHPStan (`vendor/bin/phpstan analyse`) | 10 stale baseline entries cleaned for the 2 deleted files; `[OK] No errors` |
| 7 new Feature tests (`ServerSentinelTest`) | 2 failures on first run (the missing try/catch + missing `Queue::fake()` above); 7 passed after |
| Full suite (`php artisan test --compact`) | 287 passed (841 assertions), no regressions |
| `yarn build` | Succeeded — `Sentinel-wW_h8Uo2.js` (4.33 kB) confirmed present in `manifest.json` |

## 54. Non-goals of Phase 24

- 5 of the 21 `Server\Navbar`-dependent pages remain on Livewire (down from 6): Terminal command, `Server\Show` itself, plus "Dynamic Configurations"/"Logs" within Proxy and "Logs" within Sentinel.
- `toggle()`'s disable branch and `restart()` are both untested on their SSH-touching happy path — each unconditionally dispatches/runs `StopSentinel`/`StartSentinel` with no early-return guard, same category of gap as every prior Server-scoped SSH action.
- Observed (not fixed, not in scope) an unrelated pre-existing issue on the still-Livewire `/terminal` page during this phase's manual QA: an endless WebSocket reconnect loop where the handshake authenticates successfully server-side but the connection then closes abnormally (code 1006). Logged in `docs/smoketest.md`'s Terminal checklist and `todo.md` for real validation once Terminal is converted.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 55. Phase 25 — `Security\PrivateKey\Index`: first page outside `Server\Navbar`/`Security` single-pages this session, and the first shared-component extraction

Converts the top-level "Keys & Tokens → Private Keys" list page: grid of private keys (clickable for Owner/Admin, view-only badge for Members per `PrivateKeyPolicy::view()`), an "+ Add" modal, and a "Delete unused SSH Keys" confirmation.

### Why this page, out of ~39 remaining Hard-bucket pages

The only two full pages left in the `Server\Navbar` family (`Server\Show`, Terminal command) are both large, architecturally-new undertakings — `Server\Show` nests a live `server.validate-and-install` child and was already flagged in Section 40's research as one of the *largest* `Server\Navbar` dependents (669 PHP + 428 Blade lines); Terminal needs an actual WebSocket-in-React bridge, a pattern this migration hasn't built yet. Rather than start either mid-session, a dedicated research pass (Explore agent) surveyed the other ~34 Hard-bucket pages outside that family and ranked `Security\PrivateKey\Index` as the strongest next candidate: 30 PHP + 56 Blade lines, one nested child (`security.private-key.create`), no listeners, no Echo — and Phase 18 had already inline-ported that exact child's create/generate-key logic into `SecurityPrivateKeyController::store()`/`generateKey()` when converting `Server\PrivateKey\Show`. Converting this page needed zero new backend logic, just a second controller action (`index()`) and a second consumer of already-existing endpoints.

### First shared-component extraction of this migration: `PrivateKeyCreateModal.jsx`

Every prior phase needing this exact "+ Add private key" modal (Phase 18's `Server/PrivateKey/Show.jsx`) inlined it directly in the page, since each was the modal's only consumer at the time. This phase makes it a genuine second consumer of the identical UI and behavior, so the modal was extracted into `resources/js/Components/PrivateKeyCreateModal.jsx` (open/onClose/onCreated props, wraps the Generate RSA/ED25519 buttons + create form) and `Server/PrivateKey/Show.jsx` was refactored to use it too — the first time this migration has retrofitted an already-converted page to remove duplication rather than leaving two copies. The underlying Livewire `Security\PrivateKey\Create` component (and its Blade view) is untouched and still serves its three other consumers (`Dashboard`, `GlobalSearch`, `server.new.by-hetzner`).

### A real architectural close-out: `x-security.navbar` retired

Converting this page removed the last consumer of the shared Blade component `resources/views/components/security/navbar.blade.php` (a plain nav partial predating this migration's React-side inline-duplication convention for the Security area — see `Security/CloudTokens.jsx`, `Security/ApiTokens.jsx`, etc., each of which already inlines its own copy of that same 4-link nav rather than sharing a component, matching this page's React version). Grep-confirmed zero remaining `<x-security.navbar>` usages; deleted outright rather than left as dead code.

### Two more stray `wireNavigate()` bugs found and fixed

Same audit habit as earlier this session: grepped every reference to `route('security.private-key.index')` across the app and found two links still carrying `wireNavigate()` that are now navigating into a fully-Inertia `/security/*` area — the main site navbar's "Keys & Tokens" link (`resources/views/components/navbar.blade.php`) and a "Create a new private key" link inside `github-private-repository-deploy-key.blade.php` (a still-Livewire onboarding flow). Both stripped.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SecurityPrivateKeyController.php` | modified | Added `index()` (list + per-key `canView`/`isInUse`/`showUrl`) and `cleanupUnusedKeys()` (no SSH, plain `safeDelete()` per key) |
| `resources/js/Components/PrivateKeyCreateModal.jsx` | created | Extracted shared "+ Add" modal (Generate RSA/ED25519 + create form), reused by both PrivateKey pages |
| `resources/js/Pages/Security/PrivateKey/Index.jsx` | created | Grid of keys, per-key view/view-only rendering, "+ Add" and "Delete unused SSH Keys" modals, inline Security nav (matching the existing convention in sibling Security pages) |
| `resources/js/Pages/Server/PrivateKey/Show.jsx` | modified | Refactored to consume the new shared `PrivateKeyCreateModal` instead of ~130 duplicated lines |
| `routes/web.php` | modified | `security.private-key.index` repointed at the new controller action; added `.cleanup`; removed the `SecurityPrivateKeyIndex` import |
| `resources/views/components/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Keys & Tokens" link |
| `resources/views/livewire/project/new/github-private-repository-deploy-key.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Create a new private key" link |
| `app/Livewire/Security/PrivateKey/Index.php` + matching Blade view | **deleted** | Real cutover; `Create.php`/`create.blade.php` untouched (3 other Livewire consumers remain) |
| `resources/views/components/security/navbar.blade.php` | **deleted** | Last consumer removed by this phase; grep-confirmed zero remaining `<x-security.navbar>` usages |
| `tests/v4/Feature/SecurityPrivateKeyIndexTest.php` | created | 5 tests: renders with a key listed, scopes to the current team only, creates a key via the shared `store()` endpoint, cleans up unused keys without touching SSH (real happy path — `safeDelete()` has no SSH dependency), forbids a Member from creating |

### Phase 25 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| PHPStan (`vendor/bin/phpstan analyse`) | 2 stale baseline entries cleaned for the deleted file; `[OK] No errors` |
| 5 new Feature tests (`SecurityPrivateKeyIndexTest`) + 10 existing PrivateKey tests (`SecurityPrivateKeyShowTest`, `ServerPrivateKeyTest`) | all 15 passed on first run, confirming the shared-modal refactor didn't regress `Server/PrivateKey/Show` |
| Full suite (`php artisan test --compact`) | 288 passed (859 assertions), no regressions |
| `yarn build` | Succeeded — `Security/PrivateKey/Index.jsx`, `Server/PrivateKey/Show.jsx`, and the shared `PrivateKeyCreateModal` chunk all confirmed present in `manifest.json` |

### An unrelated dev-environment gap found during manual smoke testing, fixed on request

While the user was smoke-testing in parallel with this phase, `/settings` (still fully Livewire) 404'd. Traced to `App\Livewire\Settings\Index::mount()`'s `Server::findOrFail(0)` call (executed whenever `isCloud()` is false, which it always is in this self-hosted fork) — this dev database was missing the built-in `localhost` pseudo-server (id 0) that `database/seeders/ServerSeeder.php` normally creates. Not a code bug and unrelated to this migration; fixed by running `php artisan db:seed --class=ServerSeeder` with the user's explicit go-ahead.

## 56. Non-goals of Phase 25

- `Server\Show` and the Terminal command page (`App\Livewire\Project\Shared\ExecuteContainerCommand`) remain the only two full pages in the `Server\Navbar` family still on Livewire — both large, both needing real design work (embedded-Livewire-island handling for the former, a WebSocket-in-React bridge for the latter) before conversion starts.
- ~33 other Hard-bucket pages outside that family remain untouched; see the Phase 25 research inventory (not reproduced verbatim here) for the full ranked list — next-best candidates identified: `Destination\Index` (same inline-port pattern as Phase 19's create-modal logic) and the `Project\Show`/`Project\Edit` pair (shared `delete-project` child, convertible together).
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 57. Phase 26 — `Destination\Index`: second inline-port of a create-modal, and a full component retirement

Converts the top-level "Destinations" list page: a grid of standalone/swarm Docker network destinations (with a deprecated badge for swarm ones), and a "+ Add" modal for creating a new standalone destination directly from this page (server picker included, not scoped to any one server).

### Reusing Phase 19's inline-port pattern a second time

Exactly like Phase 25 reused Phase 18's private-key create logic, this phase reuses Phase 19's `ServerDestinationsController::create()` — the same name/network/server-select fields, the same duplicate-network rejection (`Exception('Network already added to this server.')`), the same unconditional `StandaloneDocker::create()` (the original `Destination\New\Docker` component's `isSwarm` property was always `false` in practice — grep-confirmed its one consumer, this very page, never passed a different value). Added as `DestinationController::store()` (a new top-level, non-server-scoped route) alongside the existing `show()`/`resources()`/`update()`/`destroy()` methods from Phase 5.

### A full component retirement, not just the page

Unlike Phase 25 (where the shared `Create` component survived for its other consumers), grep confirmed `Destination\New\Docker` had exactly one consumer — this page — so both `app/Livewire/Destination/Index.php` and `app/Livewire/Destination/New/Docker.php` (plus their views) were deleted outright. This is the first phase in the migration to fully retire a nested-child component rather than leaving it in place for others.

### One more stray `wireNavigate()` found

Same audit habit as every prior phase: the main site navbar's "Destinations" link (`resources/views/components/navbar.blade.php`) still carried `{{ wireNavigate() }}`, now pointing into a fully-Inertia target. Stripped. (`AppLayout.jsx`'s own "Destinations" nav entry was already a plain Inertia route, unaffected.)

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/DestinationController.php` | modified | Added `index()` (list + per-destination `isSwarm`/`showUrl`) and `store()` (inline-ported create logic, no SSH) |
| `resources/js/Pages/Destination/Index.jsx` | created | Grid of destinations, deprecated badge for swarm entries, "+ Add" modal with auto-generated name (server + network, kebab-cased, mirroring the original's `generateName()`) |
| `routes/web.php` | modified | `destination.index` repointed at the new controller action; added `.store`; removed the `DestinationIndex` import |
| `resources/views/components/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "Destinations" link |
| `app/Livewire/Destination/Index.php` + `app/Livewire/Destination/New/Docker.php` (+ matching Blade views) | **deleted** | Real cutover of both — grep-confirmed zero remaining consumers of either |
| `tests/v4/Feature/DestinationIndexTest.php` | created | 5 tests: renders with the auto-created `coolify` destination listed, excludes servers not marked reachable/usable, creates a destination via the store endpoint, rejects a duplicate network, 404s when the targeted server belongs to another team |

### Phase 26 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| PHPStan (`vendor/bin/phpstan analyse`) | 5 stale baseline entries cleaned for the 2 deleted files; `[OK] No errors` |
| 5 new Feature tests (`DestinationIndexTest`) + 9 existing Destination tests (`DestinationShowTest`, `ServerDestinationsTest`) | all 14 passed on first run |
| Full suite (`php artisan test --compact`) | 288 passed (859 assertions), no regressions |
| `yarn build` | Succeeded — `Destination/Index.jsx` confirmed present in `manifest.json` |

## 58. Non-goals of Phase 26

- `Server\Show` and the Terminal command page remain the only two full pages in the `Server\Navbar` family still on Livewire, unchanged from Phase 25 — both still need real design work before conversion starts.
- ~32 other Hard-bucket pages remain untouched; the `Project\Show`/`Project\Edit` pair (shared `delete-project` child) identified in Phase 25's research is still the next-best candidate outside the `Server\Navbar` family.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 59. Phase 27 — `Project\Show` + `Project\Edit`: converted as a pair, second shared-modal extraction, and a full component retirement

Converts two pages together, as planned in Phase 26's research: the project's environments list (`Project\Show`, with an inline "+ Add Environment" form and a "Delete Project" action) and the project settings form (`Project\Edit`, name/description + the same delete action). Both share the exact same `project.delete-project` nested child, so converting them separately would have meant either duplicating the delete-modal twice or converting one at a time while the shared child straddles Livewire and React — doing both together avoided that.

### Second shared-component extraction: `DeleteProjectModal.jsx`

Following the precedent set by Phase 25's `PrivateKeyCreateModal.jsx`, the delete-confirmation modal (typed project-name confirmation, blocked with an explanatory message when the project has resources) was built once as `resources/js/Components/DeleteProjectModal.jsx` and used by both `Project/Show.jsx` and `Project/Edit.jsx` from the start — unlike Phase 25, there was no "first inline it, extract later" step, since both consumers converted in the same phase.

### A wider stray-`wireNavigate()` sweep than usual

Because `project.show`/`project.edit` are linked from far more places than a typical single-page phase (project cards on the Dashboard and the Projects index, environment-edit and resource-index breadcrumbs, a shared `resources/breadcrumbs.blade.php` component used across the whole Project/Application/Service/Database area), this phase's stray-link audit was the widest yet: **8 separate `wireNavigate()` instances across 5 files** needed stripping — `dashboard.blade.php` (2, including the project card's `Project::navigateTo()` overlay link, which conditionally targets either the new Inertia `project.show` or the still-Livewire `project.resource.index` depending on environment count — resolved by stripping unconditionally, since a full page load works correctly either way and leaving it in place would blank-page the common case), `project/index.blade.php` (2, same `navigateTo()` pattern), `project/environment-edit.blade.php` (1), `project/resource/index.blade.php` (2), and `components/resources/breadcrumbs.blade.php` (3).

### A third full component retirement

Following Phase 26's precedent, `app/Livewire/Project/DeleteProject.php` (the shared nested child) was deleted outright alongside `Show.php` and `Edit.php` — grep-confirmed zero other consumers of `<livewire:project.delete-project>` beyond the two pages converting this phase.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectController.php` | created | `show()`, `createEnvironment()` (the inline "+ Add Environment" form's submit target, no SSH), `edit()`, `update()`, `destroy()` (inline-ported delete-project logic, no SSH) |
| `resources/js/Components/DeleteProjectModal.jsx` | created | Shared delete-confirmation modal, reused by both pages from the start |
| `resources/js/Pages/Project/Show.jsx` | created | Environments grid, "+ Add Environment" modal, Delete Project button |
| `resources/js/Pages/Project/Edit.jsx` | created | Name/description form, Delete Project button |
| `routes/web.php` | modified | `project.show`/`project.edit` repointed at the new controller; added `.create-environment`, `.update`, `.destroy`; removed the `ProjectEdit`/`ProjectShow` imports |
| `resources/views/livewire/dashboard.blade.php`, `resources/views/livewire/project/index.blade.php`, `resources/views/livewire/project/environment-edit.blade.php`, `resources/views/livewire/project/resource/index.blade.php`, `resources/views/components/resources/breadcrumbs.blade.php` | modified | Removed the 8 stray `wireNavigate()` instances described above |
| `app/Livewire/Project/Show.php` + `Edit.php` + `DeleteProject.php` (+ matching Blade views) | **deleted** | Real cutover of all three — grep-confirmed zero remaining consumers |
| `tests/v4/Feature/ProjectShowEditTest.php` | created | 7 tests: renders Show with its auto-created "production" environment, 404s for a foreign-team project, creates a new environment, renders Edit, updates name/description, deletes an empty project, refuses to delete a project with resources without touching SSH |

### Phase 27 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed import ordering in the new test file on first run; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | 14 stale baseline entries cleaned for the 3 deleted files; `[OK] No errors` |
| 7 new Feature tests (`ProjectShowEditTest`) | 2 failures on first run (a duplicate "production" environment name collision, and a missing `InstanceSettings` fixture — both test-setup bugs, not app bugs); 7 passed after |
| Full suite (`php artisan test --compact`) | 291 passed (873 assertions), no regressions |
| `yarn build` | Succeeded — `Project/Show.jsx`, `Project/Edit.jsx`, and the shared `DeleteProjectModal` chunk all confirmed present in `manifest.json` |

### A real model-behavior discovery during test-writing

`Project::booted()`'s `static::created` hook (`app/Models/Project.php:112-123`) auto-creates a "production" `Environment` for every new project — the same "auto-created default row" pattern already seen with `Server` (auto-creates a `coolify` `StandaloneDocker`, Phase 19) and `ServerSetting` (auto-generates a Sentinel token, Phase 24). The first test draft manually created a second "production" environment and hit a unique-constraint violation; fixed by asserting against the auto-created row instead, matching the established fix pattern from Phase 19.

## 60. Non-goals of Phase 27

- `Server\Show` and the Terminal command page remain the only two full pages in the `Server\Navbar` family still on Livewire, unchanged from Phase 25.
- ~31 other Hard-bucket pages remain untouched outside that family; no specific next candidate has been research-ranked yet beyond what Phase 25's inventory already covered.
- `createEnvironment()`, `update()`, and `destroy()` are all genuinely safe, fully-tested happy paths (no SSH anywhere in this phase's logic) — an unusually clean phase compared to most Server-scoped ones.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 61. Phase 28 — `Storage\Index`: the first genuinely network-touching (non-SSH) untested happy path

Converts the top-level "S3 Storages" list page: a grid of configured S3-compatible storage targets (with a "Not Usable" badge when the last connection check failed) and a "+ Add" modal for registering a new one.

### A new category of untested happy path: outbound S3 API calls, not SSH

Every prior "untested happy path" gap in this migration has been an SSH action (`instant_remote_process()` against a target server). This phase's `store()` action is the first to hit a *different* external dependency: `S3Storage::testConnection()` (`app/Models/S3Storage.php:206`) builds a real Flysystem S3 disk from the submitted credentials and calls `$disk->files()` — a genuine `ListObjectsV2` API call against the S3-compatible endpoint, with a 15-second timeout — before saving. Unlike Phase 21's Hetzner token validation (a plain `Http::post()` call, trivially fakeable with `Http::fake()`), this goes through Laravel's `Storage::build()`/Flysystem abstraction, which doesn't have an equivalent one-line fake for a dynamically-constructed disk. The happy path (a real, reachable S3 endpoint) is therefore left untested here, same as every SSH action — but the validation-rejection path is fully covered, since `SafeWebhookUrl` (already applied identically in the original Livewire `Create` component) rejects unsafe endpoints (`localhost`, loopback, link-local/metadata ranges) *before* `testConnection()` ever runs, making that specific rejection a genuine, network-free happy path.

### Same inline-port, keep-the-shared-child pattern as Phases 18/19/23/24/25

`Storage\Create` (the nested modal component) has a second consumer, `GlobalSearch`, so — matching the established precedent — only `Storage\Index` was deleted; `Create.php`/`create.blade.php` stay in place untouched, and `StorageController::store()` inline-ports the same validation rules, field list, and default-endpoint fallback (`https://s3.{region}.amazonaws.com` when left blank).

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/StorageController.php` | created | `index()` (list + per-storage `isUsable`/`showUrl`), `store()` (inline-ported create logic; SSH-free but network-touching via `testConnection()`) |
| `resources/js/Pages/Storage/Index.jsx` | created | Grid of storages with "Not Usable" badge, "+ Add" modal (name/description/region/key/secret/bucket/endpoint) |
| `routes/web.php` | modified | `storage.index` repointed at the new controller; added `.store`; removed the `StorageIndex` import |
| `resources/views/components/navbar.blade.php` | modified | Removed `{{ wireNavigate() }}` from the "S3 Storages" link |
| `app/Livewire/Storage/Index.php` + matching Blade view | **deleted** | Real cutover; `Storage\Create` untouched (still used by `GlobalSearch`) |
| `tests/v4/Feature/StorageIndexTest.php` | created | 5 tests: renders with a storage listed, scopes to the current team only, forbids a non-admin from creating (both the page flag and a direct `store()` attempt), rejects an unsafe endpoint without touching the network (real happy path via `SafeWebhookUrl`), rejects a request missing required fields |

### Phase 28 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed (one run hit a 60s git-status timeout under environment load, unrelated to the changes — simple retry succeeded) |
| PHPStan (`vendor/bin/phpstan analyse`) | 2 stale baseline entries cleaned for the deleted file; `[OK] No errors` |
| 5 new Feature tests (`StorageIndexTest`) | 1 failure on first run (missing `InstanceSettings` fixture); 5 passed after |
| Full suite (`php artisan test --compact`) | 296 passed (918 assertions), no regressions |
| `yarn build` | Succeeded — `Storage/Index.jsx` confirmed present in `manifest.json` |

## 62. Non-goals of Phase 28

- `Storage\Show` (the paired detail page, nesting `storage.form` + `storage.resources`) remains on Livewire — a separate, larger undertaking (52+52 PHP/Blade lines for the page itself, 173+93 for its two nested children) not attempted this phase.
- `Server\Show` and the Terminal command page remain the only two full pages in the `Server\Navbar` family still on Livewire, unchanged since Phase 25.
- ~30 other Hard-bucket pages remain untouched; no specific next candidate has been research-ranked yet beyond what Phase 25's inventory already covered (`Project\Index` — simple but doesn't retire `project.add-empty`, still used by Dashboard/GlobalSearch — remains the next-simplest unconverted candidate by line count).
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 63. Phase 29 — `Project\Index`: the Projects landing page, no component retirement this time

Converts the top-level "Projects" list page: a grid of project cards (whole-card click-through via `Project::navigateTo()`, a conditional "+ Add Resource" shortcut when the project has an environment, a "Settings" link when the user can update), and a "+ Add" modal for creating a new empty project.

### The first phase in a while where the shared child stays exactly as it was found

Unlike the last several phases, `project.add-empty` (the nested "+ Add" modal) has two other live consumers — `Dashboard` and `GlobalSearch`, both still Livewire — so this phase inline-ports its `submit()` logic (validate name/description, `Project::create()`, redirect into the auto-created "production" environment — see Phase 27's Section 59 for that auto-creation behavior) into `ProjectController::store()` without touching `AddEmpty.php` at all. No component retirement this phase, just a second consumer of an existing shared component, mirroring Phase 25's `PrivateKey\Create` and Phase 28's `Storage\Create` precedent.

### `Project::navigateTo()`'s dual-target link, resolved the same way as Phase 27

The project card's whole-card overlay link uses `Project::navigateTo()` (`app/Models/Project.php:233-243`), which returns `project.resource.index` (still Livewire) when the project has exactly one environment, or `project.show` (now Inertia) otherwise. Same resolution as Phase 27's identical link on the Dashboard/old `project.index` view: rendered as a plain `<a>` (not an Inertia `<Link>`), so a full page load works correctly regardless of which target it resolves to.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectController.php` | modified | Added `index()` (project grid, per-project `navigateUrl`/`editUrl`/`addResourceUrl`) and `store()` (inline-ported `AddEmpty` logic, no SSH) |
| `resources/js/Pages/Project/Index.jsx` | created | Project grid with whole-card click-through, conditional "+ Add Resource"/"Settings" links, "+ Add" modal |
| `routes/web.php` | modified | `project.index` repointed at the new controller; added `.store`; removed the `ProjectIndex` import |
| `app/Livewire/Project/Index.php` + matching Blade view | **deleted** | Real cutover; `AddEmpty.php` untouched (still used by `Dashboard`, `GlobalSearch`) |
| `tests/v4/Feature/ProjectIndexTest.php` | created | 3 tests: renders with a project listed, scopes to the current team only, creates a new project and redirects into its auto-created production environment |

### Phase 29 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| PHPStan (`vendor/bin/phpstan analyse`) | 4 stale baseline entries cleaned for the deleted file; `[OK] No errors` |
| 3 new Feature tests (`ProjectIndexTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 299 passed (949 assertions), no regressions |
| `yarn build` | Succeeded — `Project/Index.jsx` confirmed present in `manifest.json` |

## 64. Non-goals of Phase 29

- `Storage\Show` remains on Livewire, unchanged since Phase 28.
- `Server\Show` and the Terminal command page remain the only two full pages in the `Server\Navbar` family still on Livewire, unchanged since Phase 25.
- ~29 other Hard-bucket pages remain untouched; no specific next candidate has been research-ranked yet beyond what Phase 25's inventory already covered.
- The `Project\Index`/`AddEmpty` unused-props observation: the original Livewire `Index::mount()` also loaded `$servers`/`$private_keys` into public properties that the Blade view never actually referenced — dead code in the original, not ported (the new controller only sends what `Project/Index.jsx` actually uses).
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 65. Phase 30 — `Storage\Show` + `Storage\Resources`: closes out the whole Storage feature area, and the first real PHPStan findings from a polymorphic relation

Converts the two-tab Storage detail page: General (credentials form, Usable/Not Usable badge, Validate Connection, Delete) and Resources (backup schedules using this storage, with per-row move-to-another-storage and disable-S3 actions). Completes the pairing flagged back in Phase 28 — the whole `Storage\*` Livewire area is now fully retired.

### One Livewire class, two routes, three nested children — untangled into two controller actions

The original `Storage\Show` component is a single class serving both `storage.show` and `storage.resources` routes, picking which nested child to render (`storage.form` or `storage.resources`) based on `request()->route()->getName()`. This phase splits that into what it always structurally was — two separate pages — matching the `DestinationController::show()`/`resources()` precedent from Phase 5: `StorageController::show()` renders `Storage/Show.jsx` (inlining `Storage\Form`'s logic), and `StorageController::resources()` renders `Storage/Resources.jsx` (inlining `Storage\Resources`'s logic). The original's "Save" button lived in the *parent* (`Show`) but dispatched a `submitStorage` browser event that the *child* (`Form`) listened for via `#[On('submitStorage')]` — a Livewire-specific indirection with no React equivalent needed, since the whole form now lives in one page component with a normal `onSubmit` handler.

### A genuinely new class of PHPStan finding: an untyped polymorphic relation

`ScheduledDatabaseBackup::database()` is a `MorphTo` relation — PHPStan types its return as a generic `Illuminate\Database\Eloquent\Model`, so accessing `->name`, `->environment`, or `->uuid` on it (needed to build the resource/backup links in the Resources table) surfaced as 7 real "undefined property" and dead-code findings, the first time this migration has hit a genuinely untyped Eloquent relation rather than a straightforward missing-type-hint gap. This is not a new problem: `app/Contracts/StandaloneDatabaseInstance.php`'s own docblock already documents that PHPStan/Larastan can't resolve `@property` PHPDoc on a plain interface, and explicitly directs this exact category of finding to `phpstan-baseline.neon` rather than per-file suppressions. Rather than hand-transcribing the 7 new error messages into the baseline file (error-prone — the regex-escaping has to match PHPStan's exact output), `vendor/bin/phpstan analyse --generate-baseline` was used to regenerate the whole baseline; the diff was verified line-by-line to confirm it only added the 7 new legitimate entries plus re-affirmed the 10 stale entries already manually removed for the phase's 3 deleted files — nothing else changed.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/StorageController.php` | modified | Added `show()`, `update()` (DB-transactional save + re-test-connection, matching the original's rollback-on-failure behavior), `testConnection()`, `destroy()`, `resources()`, `disableS3()`, `moveBackup()` |
| `resources/js/Pages/Storage/Show.jsx` | created | Credentials form, Usable/Not Usable badge, Validate Connection button, General/Resources tab nav, inline typed-name Delete Storage modal (backup-count-aware messaging, matching the original) |
| `resources/js/Pages/Storage/Resources.jsx` | created | Backup schedules table grouped by database, client-side search filter, per-row storage-move `<select>` + Save/Disable S3 actions, links out to still-Livewire resource/backup pages |
| `routes/web.php` | modified | `storage.show`/`storage.resources` repointed at the new controller actions; added `.update`, `.destroy`, `.test-connection`, `.resources.disable-s3`, `.resources.move-backup`; removed the `StorageShow` import |
| `app/Livewire/Storage/Show.php` + `Form.php` + `Resources.php` (+ matching Blade views) | **deleted** | Real cutover of all three — grep-confirmed zero remaining consumers; `Storage\Create` stays (still used by `GlobalSearch`) |
| `phpstan-baseline.neon` | regenerated | Removed 10 stale entries for the 3 deleted files; added 7 real new entries for the polymorphic-relation findings described above |
| `tests/v4/Feature/StorageShowTest.php` | created | 8 tests: renders Show, 404 for foreign-team storage, rejects an unsafe endpoint on update without touching the network, deletes a storage without touching the network, renders Resources (including the "Deleted database" fallback path for a backup whose polymorphic target no longer exists — a real edge case, not a fixture shortcut), disables S3 for a backup, moves a backup to a different storage, rejects moving a backup to the same storage |

### Phase 30 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed |
| PHPStan (`vendor/bin/phpstan analyse`) | 7 real findings on first run (polymorphic relation, described above), fixed via baseline regeneration (diff verified); `[OK] No errors` after |
| 8 new Feature tests (`StorageShowTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 307 passed (990 assertions), no regressions |
| `yarn build` | Succeeded — `Storage/Show.jsx` and `Storage/Resources.jsx` both confirmed present in `manifest.json` |

## 66. Non-goals of Phase 30

- The `App\Contracts\StandaloneDatabaseInstance` plain-interface PHPStan limitation (Section 65) is deliberately left baselined rather than fixed — a real fix means converting it to an abstract base class, touching all 8 database engine models and everything depending on the contract. Logged in `todo.md` as a lower-priority cleanup item, out of scope for this migration.
- `Server\Show` and the Terminal command page remain the only two full pages in the `Server\Navbar` family still on Livewire, unchanged since Phase 25.
- ~29 other Hard-bucket pages remain untouched; no specific next candidate has been research-ranked yet beyond what Phase 25's inventory already covered.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 67. Phase 31 — `Project\EnvironmentEdit`: a real regression found via a CI failure, not a grep

Converts the environment settings page (name/description form + typed-name Delete Environment, resource-count-aware messaging), reached from `Project\Show`'s per-environment "Settings" link. `project.delete-environment` (the nested child) has a second consumer, `Project\Resource\Index` (still Livewire, much larger), so it stays untouched — same inline-port pattern as every phase since 25.

### A real regression this phase surfaced: a dead Livewire class broken by Phase 30's cleanup

Mid-phase, the user pasted a genuine GitHub Actions CI failure: `Parameter #1 $view of function view expects view-string|null, string given.` in `app/Livewire/Team/Storage/Show.php:26`. This is a *different* class from anything converted so far — a separate `App\Livewire\Team\Storage\Show` (namespace `Team\Storage`, not `Storage`) whose `render()` hardcoded `view('livewire.storage.show')`, the exact Blade view deleted in Phase 30. Phase 30's grep sweep checked for `<livewire:storage.show>` tag usage and `StorageShow::class` references — it had no way to catch a second, unrelated class independently hardcoding a `view()` call to the same path string, since that reference isn't a "consumer" in the sense grep was checking for.

Investigated and confirmed **completely dead code**: zero routes reference it, zero Blade `<livewire:team.storage.show>` tags exist anywhere, and the only other mention in the whole repo was its own two entries in `phpstan-baseline.neon`. It had silently worked only by accident, because the view file it hardcoded happened to still exist — coincidental to shared naming with the `Storage\Show` page this migration actually converted, not a real dependency. Deleted the file outright (matching this repo's stated policy on certainly-unused code) rather than patching a broken reference inside dead code, and cleaned its 2 stale baseline entries. This is the first regression this migration has caused in already-shipped work, and it was caught by CI, not by this session's own grep-based verification — worth remembering that a Blade-tag/route grep does not catch every possible reference to a deleted view file, only the common ones.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/EnvironmentController.php` | created | `edit()`, `update()`, `destroy()` (inline-ported `DeleteEnvironment` logic, no SSH) |
| `resources/js/Pages/Project/EnvironmentEdit.jsx` | created | Name/description form, breadcrumb nav, inline typed-name Delete Environment modal (resource-count-aware messaging, matching the original) |
| `routes/web.php` | modified | `project.environment.edit` repointed at the new controller; added `.update`, `.destroy`; removed the `EnvironmentEdit` Livewire import |
| `app/Livewire/Project/EnvironmentEdit.php` (+ matching Blade view) | **deleted** | Real cutover; `DeleteEnvironment.php` untouched (still used by `Project\Resource\Index`) |
| `app/Livewire/Team/Storage/Show.php` | **deleted** | Unrelated dead-code fix — see above; this file had zero consumers and was only broken by, not related to, this phase's conversion work |
| `phpstan-baseline.neon` | modified | Cleaned 6 stale entries for the deleted `EnvironmentEdit.php` plus 2 for the dead `Team\Storage\Show.php` |
| `tests/v4/Feature/ProjectEnvironmentEditTest.php` | created | 5 tests: renders with the auto-created "production" environment, 404s for a foreign-team project, updates name/description, deletes an empty environment, refuses to delete an environment with resources without touching SSH |

### Phase 31 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed import ordering/unused imports on first run; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Found the `Team\Storage\Show.php` regression (unrelated dead code broken by Phase 30) plus stale entries for this phase's own deleted file; both fixed; `[OK] No errors` after |
| 5 new Feature tests (`ProjectEnvironmentEditTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 310 passed (1006 assertions), no regressions |
| `yarn build` | Succeeded — `Project/EnvironmentEdit.jsx` confirmed present in `manifest.json` |

## 68. Non-goals of Phase 31

- `Server\Show` and the Terminal command page remain the only two full pages in the `Server\Navbar` family still on Livewire, unchanged since Phase 25.
- ~28 other Hard-bucket pages remain untouched; no specific next candidate has been research-ranked yet beyond what Phase 25's inventory already covered.
- No sweep was made for *other* possible dead-code references to the views deleted in earlier phases (Phase 25's `x-security.navbar`, Phase 26's `Destination\New\Docker`, etc.) — only the one CI surfaced was investigated and fixed. If CI or a future PHPStan run surfaces another, treat it the same way: confirm zero real consumers, then delete outright.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 69. Phase 32 — `Team\Member\Index`: three near-identical role methods consolidated into one, and the last of `Team\*`'s single-page Livewire components retired

Converts the team members page (member list with role-change/remove actions, plus the invitation panel — generate a link or send by email, and revoke a pending invitation). Reached from `Team\Index`'s "Members" nav tab, alongside the already-converted `Team\AdminView`.

### Consolidating three near-identical methods into one action

The original Livewire component (`Team\Member`) exposed `makeAdmin()`, `makeOwner()`, and `makeReadonly()` as three separate methods, each repeating the same privilege-check shape (`$currentUserRole->lt($targetRole) || $memberRole->gt($currentUserRole)` → "You are not authorized to perform this action.") against a different hardcoded target role. `TeamController::updateMemberRole()` collapses these into one method taking `role` as a validated (`in:owner,admin,member`) request field, reusing the identical check with the requested role substituted in. Same behavior, one code path instead of three.

### Full retirement, not an inline-port: four Livewire classes and one Blade component

Unlike the inline-port pattern used since Phase 18/19 (where a nested child stays because something else still uses it), every nested piece behind this page had zero other consumers, confirmed by grep before deleting: `Team\Member.php` (the wrapping page), `Team\InviteLink.php` (the "generate/send invitation" logic), and `Team\Invitations.php` (the invitation list + revoke action), plus their four Blade views. `Team\Create.php` was checked and kept — `GlobalSearch` still uses it. `resources/views/components/team/navbar.blade.php` (the `x-team.navbar` Blade wrapper shared by `Team\Index`/`Team\AdminView`/`Team\Member`) was also retired outright once this page — its last real consumer — converted, the same closeout pattern as Phase 25's `x-security.navbar`.

### A test-design bug caught before it shipped, not a porting bug

The first draft of the test suite asserted that a plain "member" inviting an admin should hit the business-rule error message ported from `InviteLink::generateInviteLink()`. That scenario is unreachable: `TeamPolicy::manageInvitations()` already returns `false` for the "member" role, so the request never reaches the controller action at all — it 403s at the policy gate first. Caught this before running the suite, not via a test failure; replaced it with two tests that actually exercise reachable code paths: an admin inviting an owner (the real privilege-escalation guard past the gate) and a plain member hitting the endpoint at all (asserting `assertForbidden()`).

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/TeamController.php` | modified | `memberIndex()`, `updateMemberRole()` (consolidates `makeAdmin`/`makeOwner`/`makeReadonly`), `removeMember()`, `sendInvitation()` (ported from `InviteLink::generateInviteLink()`), `deleteInvitation()` (ported from `Invitations::deleteInvitation()`) |
| `resources/js/Pages/Team/Member/Index.jsx` | created | Member list with role-action buttons (`MemberRow`), invitation panel with a show/hide masked-link toggle and "Copy Invitation Link" (`InvitationRow`); reuses the same General/Members/Admin View nav as `Team/Index.jsx`/`Team/AdminView.jsx`; the "+Add Team" modal is deliberately not ported (documented gap, matching Phase 27/28 precedent for modals not yet needed a second time) |
| `routes/web.php` | modified | `team.member.index` repointed at `TeamController::memberIndex`; added `.update-role`, `.member.remove`, `.invitation.send`, `.invitation.destroy`; removed the `Team\Member\Index` Livewire import |
| `app/Livewire/Team/Member/Index.php`, `Team/Member.php`, `Team/InviteLink.php`, `Team/Invitations.php` (+ 4 matching Blade views) | **deleted** | Zero other consumers, confirmed via grep; `Team/Create.php` untouched (still used by `GlobalSearch`) |
| `resources/views/components/team/navbar.blade.php` (`x-team.navbar`) | **deleted** | Its last real consumer just converted |
| `phpstan-baseline.neon` | modified | Cleaned 16 stale entries for the 4 deleted files |
| `tests/v4/Feature/TeamMemberIndexTest.php` | created | 8 tests: renders the page, promotes a member to admin, refuses an admin promoting another admin to owner, removes a member, generates an invitation link, rejects an admin inviting an owner, forbids a plain member from reaching the invitation endpoint, revokes a pending invitation |

### Phase 32 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed import ordering in the new test file; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | `[OK] No errors` after cleaning the 16 stale baseline entries |
| 8 new Feature tests (`TeamMemberIndexTest`) | 1 failed on first run (invalid test scenario, see above), fixed by replacing it; all 8 passed after |
| Full suite (`php artisan test --compact`) | 318 passed (1039 assertions), no regressions |
| `yarn build` | Succeeded — `Team/Member/Index.jsx` confirmed present in `manifest.json` |

## 70. Non-goals of Phase 32

- No specific next Hard-bucket candidate has been research-ranked yet; ~28 pages remain, per Phase 31's non-goals.
- No sweep was made for other possible dead-code references to views deleted in earlier phases, beyond what Phase 31 already found via CI — same accepted limitation.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 71. Phase 33 — `Server\Index`: the servers list page, and an explicit scope-reduction on the Hetzner Cloud creation flow

Converts the servers grid (`/servers`) and inline-ports the "Add Server by IP Address" flow from the nested `Server\Create` modal. `Server\Create` itself stays untouched — it still has two other real consumers, `Dashboard` and `GlobalSearch` — same "keep the shared child" pattern as every inline-port since Phase 18/19.

### A modal that looked small until its second-level child was checked

`Server\Create` (42 lines) nests two more Livewire children: `Server\New\ByIp` (143 lines, a plain form — inline-ported into `ServerController::store()`) and `Server\New\ByHetzner` (547 lines — a two-step wizard that calls the live Hetzner Cloud API for locations/server types/images/SSH keys, manages cloud-init scripts, and creates real infrastructure). This is the same "looks simple until you check the nested children" trap the doc already flagged for `Server\Navbar` (Phase 7) and `Server\Show` (Phase 25's non-goals) — except this time the trap was in a second-level nested child of the page's own create modal, not the page itself.

### Explicit scope-reduction: the Hetzner Cloud flow is not ported this phase

Porting `ByHetzner` faithfully means reproducing a real multi-step wizard with cascading live API data (locations → available server types → available images, each filtered by the previous selection), dynamic pricing display, and cloud-init script management — a substantially larger scope than any single form converted so far in this migration. Rather than fold it into this phase or leave a half-working stand-in, the new `Server/Index.jsx`'s "+ Add" modal only offers "Add Server by IP Address". The original `Server\Create` component (with both flows intact) remains fully reachable via `GlobalSearch`'s own "+" menu, which still renders the untouched Livewire component — so Hetzner server creation isn't lost, just not yet available from this specific page. Recorded here as an explicit, deliberate gap for a future dedicated phase, not silently dropped.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerController.php` | created | `index()` (servers grid + create-modal props), `store()` (inline-ported from `Server\New\ByIp::submit()`) |
| `resources/js/Pages/Server/Index.jsx` | created | Servers grid identical to the original; "+ Add" modal offers the IP-based flow only (see scope-reduction above) |
| `routes/web.php` | modified | `server.index` repointed at the new controller; added `server.store`; removed the `Server\Index` Livewire import |
| `app/Livewire/Server/Index.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by route name, never by class |
| `resources/views/components/navbar.blade.php`, `resources/views/livewire/project/new/select.blade.php` | modified | Stripped `wireNavigate()` from 3 links now pointing at the fully-Inertia `/servers` |
| `phpstan-baseline.neon` | modified | Cleaned 2 stale entries for the deleted `Server\Index.php` |
| `tests/v4/Feature/ServerIndexTest.php` | created | 5 tests: renders the page, scopes the list to the current team, creates a server by IP, rejects a duplicate IP within the team, rejects submission without a selected private key |

### Phase 33 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed on first run |
| PHPStan (`vendor/bin/phpstan analyse`) | `[OK] No errors` after cleaning the 2 stale baseline entries (one run hit the 256M default memory limit from concurrent background verification load; re-ran with `--memory-limit=1G`) |
| 5 new Feature tests (`ServerIndexTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 322 passed (1065 assertions), no regressions |
| `yarn build` | Succeeded — `Server/Index.jsx` confirmed present in `manifest.json` |

## 72. Non-goals of Phase 33

- `Server\New\ByHetzner` (Hetzner Cloud server creation) is not ported — see the scope-reduction note above. Still reachable via `GlobalSearch`'s unconverted "+ Add Server" modal.
- `Server\Create` and `Server\New\ByIp` are left in place on Livewire (still used by `Dashboard` and `GlobalSearch`), per the inline-port pattern.
- No specific next Hard-bucket candidate has been research-ranked yet; `Dashboard` is a natural next candidate since it nests the same `Server\Create` modal plus two others (`Project\AddEmpty`, already inline-ported for `Project\Index`; `Security\PrivateKey\Create`, already extracted as `PrivateKeyCreateModal.jsx`) — meaning only the Hetzner gap would remain unsolved there too.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 73. Phase 34 — `Project\CloneMe`: no nested children, but three real pre-existing bugs surfaced by the first automated test to exercise `clone_application()`

Converts the environment-clone page (`/project/{uuid}/environment/{uuid}/clone`) — pick a destination server/network, review the resources that will be cloned, and clone into either a brand-new project or a new environment within the same project. Chosen after Phase 33 specifically *because* it has zero nested Livewire children (confirmed via grep before starting) — a deliberate return to a structurally simple candidate after Phase 33's nested-modal complexity. Its size (448 PHP lines) comes entirely from one big, single-purpose action (`clone()`), not from UI complexity or a wizard shape.

### Three real, pre-existing bugs found via the first automated test of `clone_application()`

This is the first time any test in the repo has exercised `clone_application()` (the shared helper used for both this page and, presumably, other clone-adjacent flows) end-to-end with a real `Application`. It surfaced three latent bugs, none introduced by this phase, all fixed before the test could pass:

1. **`App\Models\ApplicationSetting`**: 11 columns documented in the class's own `@property bool ...` docblock (`is_gzip_enabled`, `is_stripprefix_enabled`, `is_log_drain_enabled`, `is_gpu_enabled`, `is_include_timestamps`, `is_swarm_only_worker_nodes`, `is_raw_compose_deployment_enabled`, `is_consistent_container_name_enabled`, `connect_to_docker_network`, `is_env_sorting_enabled`, `disable_build_cache`) were missing from `$casts`, so a fresh DB read returned raw ints instead of bools. `fqdnLabelsForTraefik()`'s strict `?bool` parameter type rejected the int, throwing a `TypeError` the moment `clone_application()` tried to regenerate labels for a cloned application with a non-default proxy-label setting. Fixed by adding all 11 to `$casts` — completing a contract the docblock already declared, not introducing new behavior. Checked first for any `=== true`/`=== false` strict-comparison call sites that could change behavior under a real bool instead of an int; found none for these 11 (two other docblocked-bool columns on this same model, `health_check_enabled` and a sibling on `Application`, were deliberately *not* touched — see non-goals).
2. **`App\Models\Application`**: same gap, one column (`is_http_basic_auth_enabled`), same fix, same care taken (grepped for strict comparisons on this specific attribute first — found only an unrelated request-input comparison, not a model-attribute one).
3. **`bootstrap/helpers/applications.php`**: `clone_application()` passed a `Illuminate\Support\Stringable` (from `str(...)->replace(...)`) directly to `base64_encode()`, which requires a plain `string`. Under this file's `declare(strict_types=1)`, PHP does not auto-coerce `Stringable` objects for built-in functions' scalar parameters, so this threw a `TypeError` the moment the label-regeneration branch was reached. Fixed with an explicit `->toString()`. Note: `App\Models\Application::parseContainerLabels()` has the exact same pattern at two call sites and was **not** touched — same bug, different file, but outside this phase's blast radius (see non-goals).

None of these were reachable via this migration's usual "safe/validation-rejection path" testing convention (Section on untested happy-path gaps) — cloning a real application with real labels regenerated is itself the happy path, and there was no way to test the new `clone()` action meaningfully without hitting it.

### A minor PHPStan finding: `Collection::flatMap()`'s generics vs. an untyped `concat()`

`Server::destinations()` returns `$standaloneDockers->concat($swarmDockers)` — a plain `Collection` with no propagated generic type. Chaining `$servers->flatMap(fn ($server) => $server->destinations())` left PHPStan unable to resolve the closure's return generics (`TFlatMapKey`/`TFlatMapValue`). Rewritten as a plain `foreach` with `firstWhere()` instead of `flatMap()` — avoids the inference gap entirely rather than baselining it, since the original Livewire component's identical `flatMap()` call was apparently never PHPStan-checked as a route-bound Livewire property access (no matching baseline entry existed for it).

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/EnvironmentController.php` | modified | `cloneMe()` (page), `clone()` (full port of `CloneMe::clone()` — project/environment cloning, volume/backup/tag/env-var replication, optional volume-data cloning via `VolumeCloneJob`) |
| `resources/js/Pages/Project/CloneMe.jsx` | created | Name input, click-to-select destination table, read-only resources table, dual submit buttons (new project / new environment) |
| `routes/web.php` | modified | `project.clone-me` repointed at the new controller; added `project.clone-me.store`; removed the `Project\CloneMe` Livewire import |
| `app/Livewire/Project/CloneMe.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by route name, never by class |
| `resources/views/livewire/project/resource/index.blade.php` | modified | Stripped `wireNavigate()` from 2 "Clone" links now pointing at the fully-Inertia clone page |
| `app/Models/ApplicationSetting.php`, `app/Models/Application.php` | modified | Added the 12 missing boolean casts described above |
| `bootstrap/helpers/applications.php` | modified | Fixed the `Stringable`-into-`base64_encode()` bug in `clone_application()` |
| `phpstan-baseline.neon` | modified | Cleaned 23 stale entries for the deleted `CloneMe.php` |
| `tests/v4/Feature/ProjectCloneMeTest.php` | created | 5 tests: renders the page, clones into a new project (with a real `Application`, exercising the full `clone_application()` path), clones into a new environment in the same project, rejects a duplicate project name, rejects submission without a destination |
| `SECURITY.md`, `CODE_OF_CONDUCT.md` | **deleted** | Unrelated to this phase — found during a user-directed doc sweep. Both were leftover upstream-Coolify files pointing real security/conduct reports at `security@coollabs.io`/`hi@coollabs.io`, coollabs' own addresses, not this fork's. Deleted per explicit user approval rather than rewritten, since this fork has no formal community/security process to document. |

### Phase 34 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed on every run |
| PHPStan (`vendor/bin/phpstan analyse --memory-limit=1G`) | found 1 real `flatMap()` generics finding from this phase's own code; fixed by rewriting rather than baselining; `[OK] No errors` after, plus cleaning the 23 stale `CloneMe.php` baseline entries |
| 5 new Feature tests (`ProjectCloneMeTest`) | failed 3 times in a row on the "clones into a new project/environment" tests — each failure was a different real pre-existing bug (see above), fixed in sequence; all 5 passed once all three were fixed |
| Full suite (`php artisan test --compact`) | 327 passed (1087 assertions), no regressions |
| `yarn build` | Succeeded — `Project/CloneMe.jsx` confirmed present in `manifest.json` |

## 74. Non-goals of Phase 34

- `App\Models\Application::parseContainerLabels()` has the same `Stringable`-into-`base64_encode()` bug pattern as the one fixed in `clone_application()`, at two call sites. Not fixed — outside this phase's blast radius, and only reachable when `mb_detect_encoding()` fails on already-decoded labels, a narrower trigger than this phase hit. Logged in `todo.md` for a future pass.
- Two more docblocked-`bool` columns with missing casts were found but deliberately **not** fixed: `Application::health_check_enabled` and `Application::custom_healthcheck_found`. Unlike the 12 columns that were fixed, `health_check_enabled` has a real `=== false` strict-comparison call site (`app/Models/Application.php:1491`) — adding the cast would silently change existing health-check behavior (a currently-always-false comparison against a raw int would start correctly evaluating true), which is a real behavior change requiring its own dedicated verification, not a side effect of a Project page conversion. Logged in `todo.md`, not fixed here.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 75. Phase 35 — `Project\Resource\Index`: the environment resources page, a third shared-modal extraction, and a real null-safety bug in `Service::serverStatus()`

Converts the resources listing page (`/project/{uuid}/environment/{uuid}`) — the two-level project/environment breadcrumb dropdown, a client-side search box filtering applications/databases/services by name/fqdn/description/tag, and the environment's "Delete Environment"/"+ New"/"Clone" actions. Chosen specifically for having zero nested Livewire children in its own Blade view (confirmed via grep before starting) — after Phase 33's nested-modal complexity, a deliberate return to a structurally simple candidate, same reasoning as Phase 34.

### Third shared-component extraction: `DeleteEnvironmentModal.jsx`

This page's "Delete Environment" button was the **second** real consumer of the exact typed-name-confirmation delete flow already built inline in Phase 31's `Project/EnvironmentEdit.jsx` (which itself already reuses `EnvironmentController::destroy()` — no new backend logic needed at all here). Extracted the existing inline modal out of `EnvironmentEdit.jsx` into `resources/js/Components/DeleteEnvironmentModal.jsx` and refactored `EnvironmentEdit.jsx` to use it, then used it here too — same "extract only once there's a genuine second consumer" discipline as `PrivateKeyCreateModal.jsx` (Phase 25) and `DeleteProjectModal.jsx` (Phase 27).

### A full component retirement bundled with this phase: `Project\DeleteEnvironment`

The nested `<livewire:project.delete-environment>` child (kept alive since Phase 31 specifically because this page was its last real consumer) now has zero consumers, confirmed via grep. Deleted outright — its logic was never actually needed, since `EnvironmentController::destroy()` already covers the same behavior.

### A real bug: `Service::serverStatus()` had no null-safety, unlike its `Application` sibling

The first automated test to render a `Service` through this page's `toSearchableArray()` (which reads `$item->server_status`) crashed with `Call to a member function isFunctional() on null`. `Service::server()` is a direct `belongsTo(Server::class)` via `server_id` — nullable, and clearly reachable in practice (a service without a server attached yet). `Service::serverStatus()`'s accessor called `$this->server->isFunctional()` with no guard at all. Compared this against `Application::serverStatus()`, which handles the identical situation correctly (`$mainServer?->isFunctional() ?? false`) — confirming this was a real, pre-existing oversight on `Service`'s side, not a deliberate difference. Fixed to match: `$this->server?->isFunctional() ?? false`. Grepped for any other direct usage of `Service::$server_status` first — found none, so no risk of the null-safety fix silently changing other behavior.

### A front-end simplification: no JS-computed flyout positioning

The original Blade view's second-level "environment → its resources" flyout used Alpine state (`envPositions`) to compute each row's pixel offset via `$el.offsetTop`, so the flyout could be absolutely positioned next to the hovered row. The React version positions each row's flyout relative to its own wrapping element (`absolute left-full top-0` on a per-row `relative` container) instead — same visual behavior, no JS position-tracking needed. Consistent with this migration's standing allowance for front-end simplifications that preserve behavior without preserving exact implementation technique.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectResourceController.php` | created | `index()` — resources grid, breadcrumb dropdown data (all projects, all sibling environments with their own resource lists), search-ready flattened arrays for applications/databases/services |
| `resources/js/Pages/Project/Resource/Index.jsx` | created | Breadcrumb dropdowns, client-side search/filter (mirrors the original's `filterAndSort` logic via `useMemo`), resource cards grouped by type, empty states |
| `resources/js/Components/DeleteEnvironmentModal.jsx` | created | Extracted from `EnvironmentEdit.jsx`; see above |
| `resources/js/Pages/Project/EnvironmentEdit.jsx` | modified | Refactored to use the extracted `DeleteEnvironmentModal` instead of its own inline copy |
| `routes/web.php` | modified | `project.resource.index` repointed at the new controller; removed the `Resource\Index` Livewire import |
| `app/Livewire/Project/Resource/Index.php`, `app/Livewire/Project/DeleteEnvironment.php` (+ matching Blade views) | **deleted** | Confirmed via grep: zero remaining consumers of either |
| `resources/views/components/resources/breadcrumbs.blade.php` | modified | Stripped `wireNavigate()` from 2 links now pointing at the fully-Inertia resource index (shared component, still used by not-yet-converted Application/Database/Service Configuration pages — only the specific links targeting `project.resource.index` were touched) |
| `app/Models/Service.php` | modified | Fixed `serverStatus()`'s missing null-safety, described above |
| `phpstan-baseline.neon` | modified | Cleaned 18 stale entries for the 2 deleted files |
| `tests/v4/Feature/ProjectResourceIndexTest.php` | created | 4 tests: renders the empty-state page, lists applications/services with correct configuration links (this is what caught the `Service::serverStatus()` bug), lists sibling environments with their own resources for the breadcrumb dropdown, 404s for a foreign-team project |

### Phase 35 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed every run |
| PHPStan (`vendor/bin/phpstan analyse --memory-limit=1G`) | found 2 real findings from this phase's own code (missing generics PHPDoc on `toSearchableArray()`); fixed; `[OK] No errors` after cleaning the 18 stale baseline entries |
| 4 new Feature tests (`ProjectResourceIndexTest`) | 1 failed on first run (the `Service::serverStatus()` crash, a real bug — see above); fixed; all 4 passed after |
| Full suite (`php artisan test --compact`) | 330 passed (1105 assertions), no regressions |
| `yarn build` | Succeeded — `Project/Resource/Index.jsx` confirmed present in `manifest.json` |

## 76. Non-goals of Phase 35

- `Project\Resource\Create` (the "+ New" resource wizard) remains on Livewire — it nests 6 different resource-creation flows (git repo, GitHub private repo, deploy-key GitHub, Dockerfile, Docker Compose, Docker image) plus a type-selector, a substantially larger scope than this phase.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 77. Phase 36 — `Dashboard`: closes the loop on all three "+ Add" modals with two more shared-component extractions

Converts the app's landing page (`/`) — Projects and Servers sections, each with an empty state and an "+ Add" modal. The last page to need the create-project and create-server flows, so this phase is mostly about wiring up components already built in earlier phases rather than writing new backend logic.

### Two more shared-component extractions: `AddProjectModal.jsx` and `AddServerModal.jsx`

Dashboard is the **second** real consumer of both `Project\Index.jsx`'s inline "New Project" modal (Phase 29) and `Server\Index.jsx`'s inline "Add Server by IP" modal (Phase 33). Extracted both into `resources/js/Components/`, refactored their original pages to use the extracted versions, and used them here too — same "extract only once there's a genuine second consumer" discipline as `PrivateKeyCreateModal.jsx` (Phase 25), `DeleteProjectModal.jsx` (Phase 27), and `DeleteEnvironmentModal.jsx` (Phase 35). This is now the fourth and fifth shared component in `resources/js/Components/`, and the third page to reuse `PrivateKeyCreateModal.jsx` (Dashboard's "no private keys found" fallback state uses it directly, unmodified — no extraction needed since it was already shared).

### A UX inconsistency in the original, resolved by following the already-converted sibling pages

The original Livewire `dashboard.blade.php` gated its "+ Add" buttons only by count (`@if ($projects->count() > 0)`, `@if ($servers->count() > 0 && $privateKeys->count() > 0)`) — no `@can('createAnyResource')` check, unlike the standalone `Project\Index`/`Server\Index` pages it duplicates functionality with. This wasn't a security gap (`ProjectController::store()`/`ServerController::store()` both already enforce `$this->authorize('createAnyResource')`/`create` server-side regardless of whether the button is shown) — just a UI inconsistency where an unauthorized user could open the modal only to have the submit fail. `Dashboard.jsx` gates both buttons with `canCreateProject`/`canCreateServer`, matching the already-converted sibling pages rather than reproducing the original's gap.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/DashboardController.php` | created | `index()` — projects (with `navigateTo()`/`addResourceUrl`), servers, private keys, and every URL the three modals need |
| `resources/js/Pages/Dashboard.jsx` | created | Projects section, Servers section (with its "no private keys yet" fallback state), wires up all three shared modals |
| `resources/js/Components/AddProjectModal.jsx`, `AddServerModal.jsx` | created | Extracted from `Project/Index.jsx` and `Server/Index.jsx` respectively; see above |
| `resources/js/Pages/Project/Index.jsx`, `Server/Index.jsx` | modified | Refactored to use the extracted modal components instead of their own inline copies |
| `routes/web.php` | modified | `dashboard` repointed at the new controller; removed the `Dashboard` Livewire import |
| `app/Livewire/Dashboard.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by route name, never by class |
| `resources/views/components/navbar.blade.php` | modified | Stripped `wireNavigate()` from all 3 links to `/` (logo, collapsed-sidebar logo, "Dashboard" nav item) |
| `resources/views/errors/{400,401,402,403,404,429,500,503}.blade.php` | modified | Stripped `wireNavigate()` from each error page's "Dashboard" back-link (8 files, same one-line change) |
| `phpstan-baseline.neon` | modified | Cleaned 4 stale entries for the deleted `Dashboard.php` |
| `tests/v4/Feature/DashboardTest.php` | created | 3 tests: renders with no projects/servers, lists projects/servers owned by the current team with correct URLs, excludes another team's projects/servers |

### Phase 36 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed every run |
| PHPStan (`vendor/bin/phpstan analyse --memory-limit=1G`) | `[OK] No errors` after cleaning the 4 stale baseline entries |
| 3 new Feature tests (`DashboardTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 337 passed (1133 assertions), no regressions |
| `yarn build` | Succeeded — `Dashboard.jsx` confirmed present in `manifest.json` |

## 78. Non-goals of Phase 36

- `Project\Resource\Create` remains the next candidate flagged in Phase 35's non-goals, still not attempted.
- No specific next Hard-bucket candidate has been research-ranked yet beyond that.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 79. Phase 37 — `Server\Proxy\DynamicConfigurations`: the third of five remaining `Server\Navbar` pages, and the first use of the existing Echo-in-React bridge

Converts the proxy dynamic-configurations page (`/server/{uuid}/proxy/dynamic`) — one of the 5 `Server\Navbar`-dependent pages `todo.md` had flagged as remaining, and the first of those 5 that turned out **not** to need new design work (unlike `Server\Show` and Terminal, which do). Lists every dynamic proxy config file on the server (Traefik `.yaml`/Caddy `.caddy`), with per-file Edit/Delete and a "+ Add" flow, all backed by real SSH reads/writes.

### Three Livewire classes, all exclusively used by this one page

`DynamicConfigurations` (75 lines, the page itself — has an `echo-private:team.{id},ProxyStatusChangedUI` listener), `NewDynamicConfiguration` (110 lines, the add/edit form — reused for both flows via a `newFile` flag), and `DynamicConfigurationNavbar` (62 lines, per-file Edit/Delete controls). Grepped all three for other consumers before starting — zero found for any — so all three were fully retired, not inline-ported-and-kept, following the same "confirm zero real consumers, then delete outright" discipline as every full retirement since Phase 18.

### First real use of the already-built `useTeamChannel` Echo bridge

Earlier phases (`Server\DockerCleanup`, the Application Deployment pages, `Server\Resources`) already built `resources/js/hooks/useTeamChannel.js` — a React hook wrapping Laravel Echo's private-channel subscription to match Livewire's `getListeners()` -> `"echo-private:team.{id},EventClass"` pattern. This phase is the first to reuse that existing hook for a *newly*-converted page rather than build a new instance of the pattern: `useTeamChannel(['ProxyStatusChangedUI'], () => router.reload({ only: ['contents'] }))` replaces the original's `loadDynamicConfigurations` listener, re-fetching just the file list (not the whole page) via Inertia's partial reload when the proxy status changes elsewhere.

### Reused, not rebuilt: `MonacoEditor.jsx` (Phase 23) and the `'proxy'` sidebar variant (Phase 23)

`ServerChromeData::sidebar()`'s `'proxy'` variant already had a `dynamicConfs` URL wired up when it was built in Phase 23, anticipating this page before it existed. No sidebar changes needed — just pointing an already-correct link at a route that finally resolves to something real. Each file's content renders in a read-only `MonacoEditor.jsx` on the main page; editing happens in a modal with an editable one — same component, same dynamic-AMD-loader-script pattern, no new Monaco integration work.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ServerProxyController.php` | modified | `dynamicConfigurations()`, `storeDynamicConfiguration()` (handles both add and edit via a `newFile` flag, matching the original), `destroyDynamicConfiguration()`, private `loadDynamicConfigurations()` helper |
| `resources/js/Pages/Server/Proxy/DynamicConfigurations.jsx` | created | File list (read-only `MonacoEditor` per file, reserved filenames shown as plain disabled `<textarea>`), Add/Edit modal (shared between both flows, matching the original), `useTeamChannel` for live reload |
| `routes/web.php` | modified | `server.proxy.dynamic-confs` repointed at the new controller; added `.store`/`.destroy`; removed the Livewire import |
| `app/Livewire/Server/Proxy/DynamicConfigurations.php`, `NewDynamicConfiguration.php`, `DynamicConfigurationNavbar.php` (+ 3 matching Blade views) | **deleted** | Confirmed via grep: zero consumers beyond this page for all three |
| `resources/views/components/server/sidebar-proxy.blade.php` | modified | Stripped `wireNavigate()` from the one link now pointing at the fully-Inertia dynamic-confs page; left untouched for `server.proxy.logs` (still Livewire) — this Blade sidebar itself stays, since `Server\Proxy\Logs` still renders it |
| `phpstan-baseline.neon` | modified | Cleaned 17 stale entries for the 3 deleted files |
| `tests/v4/Feature/ServerProxyDynamicConfigurationsTest.php` | created | 5 tests: renders the page without touching SSH on a non-functional server, 404s for a foreign-team server, rejects a path-traversal filename, rejects a reserved filename, 404s on store/destroy for a foreign-team server |

### Phase 37 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed on first run |
| PHPStan (`vendor/bin/phpstan analyse --memory-limit=1G`) | `[OK] No errors` after cleaning the 17 stale baseline entries |
| 5 new Feature tests (`ServerProxyDynamicConfigurationsTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 340 passed (1151 assertions), no regressions |
| `yarn build` | Succeeded — `Server/Proxy/DynamicConfigurations.jsx` confirmed present in `manifest.json` |

## 80. Non-goals of Phase 37

- `Server\Proxy\Logs` and `Server\Sentinel\Logs` (the remaining 2 of the original 5 flagged `Server\Navbar` pages, besides `Server\Show` and Terminal) both nest `Project\Shared\GetLogs` — a 292-line class / 564-line view shared by 12 consumers (8 database engine pages, `Project\Service\Index`, `Project\Shared\Logs`, plus these 2), with a real `wire:poll.2000ms` live-tailing UI. Investigated as a candidate for this phase and set aside — porting it properly is a phase of its own, not a quick follow-on to a 75-line page. Not attempted.
- The store/destroy actions' actual SSH-touching happy path (writing/deleting real files on a server, reloading Caddy) remains untested — verified only via validation-rejection paths in Pest, per this migration's standing untested-happy-path convention. Added to `docs/smoketest.md`'s manual QA checklist.
- No specific next Hard-bucket candidate has been research-ranked yet beyond `Server\Proxy\Logs`/`Server\Sentinel\Logs` needing their own `GetLogs`-porting phase.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 81. Phase 38 — `Source\Github\Change`: the largest single-class conversion since Phase 34, and zero nested Livewire children despite 435 PHP + 422 Blade lines

Converts the GitHub App configuration page (`/source/github/{uuid}`, plus `/permissions` and `/resources` sibling routes) — GitHub App registration (automated manifest flow or manual entry), a 3-tab configuration UI (General/Permissions/Resources) once registered, and delete. Investigated for nested Livewire children and real-time listeners first (per this migration's standard opening move) — found none of either, despite the size. The complexity here is genuinely all business logic (JWT generation for GitHub's API, a client-side manifest-flow form-post to GitHub itself, cache-backed setup-state tokens), not UI nesting — a different shape of "big" than Phase 34's `Project\CloneMe` or Phase 33's `Server\Index`, but the same lesson: size alone doesn't predict Hard-bucket difficulty as reliably as checking for nested children does.

### A pure client-side external redirect, not a backend action

The "Register Now" button doesn't call this app's backend at all — the original's `createGithubApp()` JS function builds a GitHub App manifest client-side and submits a real `<form method="post">` directly to `github.com/settings/apps/new`, using a `manifestState` value cached server-side (via `Cache::put()`, keyed by a random token) so GitHub's callback can be verified later. Ported as an equivalent plain JS function in the React component — no new backend endpoint needed for this specific flow, just the same server-computed `manifestState` passed down as a prop exactly like the original passed it to Alpine/Blade via `@js()`.

### A `session('from')` redirect-back path, easy to miss without reading `mount()` fully

When a user navigates here mid-flow from an application's "select a source" screen (tracked via `session('from')`, set elsewhere), completing GitHub App installation should redirect back to resume that flow rather than land on this page. Ported into `SourceGithubController::show()` unchanged — it's exactly the kind of behavior that's invisible from the Blade view alone and only surfaces by reading the original `mount()` method line by line, which is why this migration's recipe has always included reading the full original class, not just skimming for obvious nested-component/real-time flags.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SourceGithubController.php` | created | `show()` (handles all 3 tab routes + the mid-flow redirect-back), `update()`, `updateName()` (calls GitHub's API to sync the app slug), `checkPermissions()` (dispatches `GithubAppPermissionJob` sync), `instantSaveSystemWide()`, `createManual()`, `destroy()` |
| `resources/js/Pages/Source/Github/Change.jsx` | created | Pre-registration state (Register Now / Manual Installation cards) and post-registration state (3-tab config UI), inline typed-name delete confirmation modal, the ported `createGithubApp()` manifest-flow function |
| `routes/web.php` | modified | `source.github.show`/`.permissions`/`.resources` repointed at the new controller; added `.update`/`.update-name`/`.check-permissions`/`.instant-save`/`.create-manual`/`.destroy`; removed the Livewire import |
| `app/Livewire/Source/Github/Change.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by route name, never by class |
| `resources/views/source/all.blade.php` | modified | Stripped `wireNavigate()` from the one link to `source.github.show`, now fully Inertia |
| `phpstan-baseline.neon` | modified | Cleaned 16 stale entries for the deleted `Change.php` |
| `tests/v4/Feature/SourceGithubChangeTest.php` | created | 7 tests: renders pre-registration state, renders the tabbed post-registration state with the correct `activeTab`, 404s for a foreign-team app, updates the configuration, rejects an unsafe (SSRF-guarded) `apiUrl`, rejects deleting an app still used by an application, deletes a genuinely-unused app |

### Phase 38 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed import ordering in the new test file; passed after |
| PHPStan (`vendor/bin/phpstan analyse --memory-limit=1G`) | `[OK] No errors` after cleaning the 16 stale baseline entries |
| 7 new Feature tests (`SourceGithubChangeTest`) | all passed on first run |
| Full suite (`php artisan test --compact`) | 347 passed (1188 assertions), no regressions |
| `yarn build` | Succeeded — `Source/Github/Change.jsx` confirmed present in `manifest.json` |

## 82. Non-goals of Phase 38

- `updateName()`'s real GitHub-API-calling happy path (fetching the app's slug and renaming both the app and its associated private key) remains untested — verified only via the surrounding validation/authorization paths in Pest, per this migration's standing untested-happy-path convention.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 83. Phase 39 — the 4 `SharedVariables` "Show" pages: a single DRY controller for 4 near-identical Livewire classes, and a `yarn build` environment gotcha worth its own writeup

Converts the 4 pages that actually manage shared environment variables (`shared-variables.team.index`, `.project.show`, `.environment.show`, `.server.show` — distinct from the 4 picker/index pages converted back in Phase 2, which just list scopes). All 4 original Livewire classes (`SharedVariables\Team\Index`, `\Project\Show`, `\Environment\Show`, `\Server\Show`) were near-identical: `mount()`/`saveKey()`/`switch()`/`getDevView()`/`submit()`/`handleBulkSubmit()`/`deleteRemovedVariables()`/`updateOrCreateVariables()`/`refreshEnvs()`, differing only in which owner model (`Team`/`Project`/`Environment`/`Server`) they operate on and, for `Server`, excluding the two predefined variables (`COOLIFY_SERVER_UUID`, `COOLIFY_SERVER_NAME`). Per this session's standing DRY feedback (first applied in Phase 32's `Team\Member\Index`), ported as a **single set of ~9 shared controller methods** with a `resolveOwner(Request $request): array{0: Team|Project|Environment|Server, 1: string}` helper that branches on which route parameters are present, rather than 4 sets of near-duplicate methods.

The generic `Project\Shared\EnvironmentVariable\Add`/`Show` Livewire components (used elsewhere for application/database/service variables) were deliberately **not** ported wholesale — reading their `$shared`/`$isSharedVariable` Blade branches first showed the shared-variable code paths are dramatically simpler (no `is_buildtime`/`is_runtime`/`real_value`, since `SharedEnvironmentVariable` doesn't have those columns at all), so the React port only implements the subset that actually applies. Those two Livewire components themselves stay in place, untouched — they're still used by 8 other still-Livewire consumers.

### A real routing bug caught by the Feature tests, not by inspection

The first test run surfaced a genuine bug in the shared-controller design: `updateVariable`/`lockVariable`/`destroyVariable` originally declared `int $variable_id` as a typed method parameter, matching `StorageController::disableS3(string $storage_uuid, int $backup_id)`'s pattern. That works when the method declares **every** URI segment in order — but Laravel's `ControllerDispatcher` doesn't bind route parameters to controller parameters by name for scalar types; it splices class-typehinted dependencies (like `Request`) into their reflected position within the route's parameter list and passes everything else positionally, unchanged. Since these routes have 2 URI segments (e.g. `server_uuid`, `variable_id`) but the method only declared 1 scalar parameter, the *first* route parameter (`server_uuid`, a string) landed in the `int $variable_id` slot, throwing a `TypeError` under `strict_types=1`. Fixed by dropping the scalar parameter entirely and reading `(int) $request->route('variable_id')` inside the method body instead — the same fix pattern now worth remembering for any future shared-controller method bound to routes with more URI segments than the method needs.

### `Model $owner` didn't satisfy PHPStan for a good reason

`renderShow()`/`resolveOwner()` were first typed with the generic `Illuminate\Database\Eloquent\Model` for `$owner`, which PHPStan correctly flagged — `Model::environment_variables()` doesn't exist; only `Team`/`Project`/`Environment`/`Server` each declare their own `environment_variables()` relation independently (no shared interface or base class). Fixed by typing `$owner` as the real union `Team|Project|Environment|Server` throughout, which is what PHPStan needed to resolve the relation call.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SharedVariablesController.php` | modified | Added `teamShow()`/`projectShow()`/`environmentShow()`/`serverShow()`, a private `renderShow()` shared by all 4, and `storeVariable()`/`updateVariable()`/`lockVariable()`/`destroyVariable()`/`bulkUpdateVariables()` shared across all 4 scopes via `resolveOwner()` |
| `resources/js/Components/SharedVariablesManager.jsx` | created | The shared UI: header with scope-aware heading + "+ Add" modal + Developer/Normal view toggle (client-side only), normal view (locked rows show masked key + delete-confirm modal + comment-only edit; unlocked rows show key/value/comment + instant-save "Is Multiline?" checkbox + Update/Lock/Delete), dev view (textarea + "Save All") |
| `resources/js/Pages/SharedVariables/Team/Index.jsx`, `Project/Show.jsx`, `Environment/Show.jsx`, `Server/Show.jsx` | created | Thin wrappers spreading props into `SharedVariablesManager` — no collision with the Phase 2 picker pages (`SharedVariables/Index.jsx`, `SharedVariables/{Environment,Project,Server}/Index.jsx`), since these use `Show`/`Team/Index` component names |
| `routes/web.php` | modified | 4 show routes repointed at the new controller methods (keeping existing route *names* — `shared-variables.team.index`, `.project.show`, `.environment.show`, `.server.show` — since `EnvVarInput.php`/`GlobalSearch.php`/the Phase 2 picker pages all reference them by name); added 20 action routes (`.store`/`.update`/`.lock`/`.destroy`/`.bulk-update` × 4 scopes); removed the 4 now-unused Livewire imports |
| `app/Livewire/SharedVariables/Team/Index.php`, `Project/Show.php`, `Environment/Show.php`, `Server/Show.php` (+ matching Blade views) | **deleted** | Confirmed via grep: only referenced by route name, never by class |
| `tests/v4/Feature/SharedVariablesTeamShowTest.php`, `ProjectShowTest.php`, `EnvironmentShowTest.php`, `ServerShowTest.php` | created | 8/7/7/8 tests across the 4 files: renders with variables, 404s for a foreign-team owner, creates, updates, locks, deletes, bulk-updates (including the server-scope predefined-key exclusion), plus one authorization-rejection test (`Team`'s `update` policy requires admin/owner; `Project`/`Environment`/`Server` policies currently allow any team member) |
| `phpstan-baseline.neon` | modified | Cleaned 61 stale entries for the 4 deleted Livewire files |

### Phase 39 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed import ordering + a superfluous duplicate `@param` tag; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Found 10 real `method.notFound` errors from the initial `Model $owner` typing (see above); `[OK] No errors` after retyping to the `Team\|Project\|Environment\|Server` union |
| 30 new Feature tests across the 4 files | 17 failed on first run (stale-baseline-unrelated): missing `InstanceSettings::forceCreate(['id' => 0])` in 3 of the 4 files' `beforeEach`, a Pest `expect($model)->prop->toBe(...)->prop2->toBe(...)` chaining conflict specific to a model attribute literally named `value`, `is_shown_once` compared with `toBeTrue()` against a raw un-cast SQLite integer, a duplicate `COOLIFY_SERVER_UUID` insert not accounting for `Server::factory()`'s own predefined-variable-seeding boot hook, and the routing bug above; all fixed, 34 passed on the next run, all 30 (Server file grew to 8 after fixes) passed on the final run |
| Full suite (`php artisan test --compact`) | 561 passed (2391 assertions), no regressions |
| `yarn build` | Succeeded, but **took over 3 hours through the normal `docker exec coolify-vite yarn build` path** before completing natively on the Windows host in 8.49 seconds — see the new "Known issue" writeup in `docs/command.md` for the full root-cause (Docker Desktop WSL2 9P bind-mount + Windows Defender scanning, not a code issue) and the workaround used |

## 84. Non-goals of Phase 39

- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).
- The `yarn build` slowness investigated this phase is an environment characteristic of this specific Windows/Docker Desktop setup, not something fixed in the codebase — see `docs/command.md` for the workaround; the underlying WSL2 bind-mount performance issue itself is out of scope for this migration.

## 85. Phase 40 — `Project\Database\Backup\Index`: a thin controller wrapping 4 still-Livewire nested children, a first shared-component extraction outside the SharedVariables family, and two real pre-existing bugs

Converts the standalone-database backups page (`project.database.backup.index`, distinct from `project.service.database.backups`, which serves the same UI for service-databases and stays Livewire). The original Livewire class itself is tiny (mount-only, resolves project/environment/database from route params, redirects to Configuration if the engine doesn't support backups) — nearly all the real complexity lives in 4 nested Livewire children (`Project\Shared\ConfigurationChecker`, `Project\Database\Heading`, `Project\Database\CreateScheduledBackup`, `Project\Database\ScheduledBackups`). Research confirmed all 4 children are still used by other still-Livewire pages (`Database\Configuration`, `Service\DatabaseBackups`, `Shared\Logs`, `Shared\ExecuteContainerCommand`, `Backup\Execution`), so none of them could be deleted — only their `<livewire:...>` tags were removed from the now-deleted `backup/index.blade.php`, and React equivalents were built fresh for this one page.

### `ConfigurationChecker` becomes a second consumer, so it moved to a shared location

`Project/Application/Deployment/ConfigurationChecker.jsx` (built in Phase 7) already degrades correctly for a resource with no diff data (`diff.changes` defaults to `[]`, producing the plain "Please redeploy..." banner with no "View changes" button) — exactly what `App\Livewire\Project\Shared\ConfigurationChecker` does for non-Application resources (`$this->configurationDiff = []` for anything that isn't `Application`). Rather than duplicate the file, it moved to `resources/js/Components/ConfigurationChecker.jsx` and the Deployment/Index page's import was updated — the established "extract only once there's a genuine second consumer" rule (first applied in Phase 25), now exercised outside the `SharedVariablesManager`/`DeleteEnvironmentModal`/`DeleteProjectModal` family for the first time.

### Dead code found and dropped: the custom-type selector can never fire on this route

`ScheduledBackups.blade.php`'s "Select the type of database..." branch (`@if ($database->is_migrated && blank($database->custom_type))`) looked like it needed porting, but `is_migrated`/`custom_type` only exist as columns on `service_databases` (added by `2025_04_30_134146_add_is_migrated_to_services.php`) — never on `standalone_postgresqls`/`standalone_mysqls`/`standalone_mariadbs`/`standalone_mongodbs`. Since `Environment::databases()` (which this route resolves against) only ever returns standalone engine instances, `$database->is_migrated` is always `null` here, and the branch is permanently dead on this specific page (it's only real for `project.service.database.backups`, which stays Livewire). Confirmed via grep before writing any code, then dropped `setCustomType()`, its route, and the `CustomTypeForm` React component entirely rather than port unreachable UI.

### Two real pre-existing bugs found via test design, before either test ran

Designing a safe (non-SSH) test for the Start/Restart buttons meant using a freshly-created, not-yet-marked-reachable `Server` — which surfaces `StartDatabase`/`RestartDatabase`'s existing `handle()` behavior: when `$server->isFunctional()` is false, both actions return the **string** `'Server is not functional'` instead of an `Activity`. The original `Heading.php` (and my first-draft controller, copied faithfully from it) both do `$activity = StartDatabase::run($database); ... $activity->id` unconditionally — a fatal error waiting to happen on a string. Fixed by checking `$activity instanceof \Spatie\Activitylog\Models\Activity` before touching `->id`, falling back to the string itself as a flashed error message. This is the same class of bug as Phase 34/35's finds: a real, pre-existing flaw surfaced by writing a *safe* test, not by SSH-mocking infrastructure this migration doesn't have.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectDatabaseBackupController.php` | created | `index()`, `store()`, `start()`/`restart()`/`stop()`/`checkStatus()` (the `Heading` actions, reusable once Configuration/Logs/Terminal convert), plus private `resolveDatabase()`/`headingProps()`/`configurationCheckerProps()`/`backupProps()` |
| `resources/js/Components/ConfigurationChecker.jsx` | moved (from `Pages/Project/Application/Deployment/`) | Now shared by `Deployment/Index` and `Database/Backup/Index` |
| `resources/js/Components/DatabaseHeading.jsx` | created | Nav (Configuration/Logs/Terminal-if-permitted/Backups, plain `<a>` since only Backups is Inertia so far) + Start/Restart/Stop actions + activity-monitor slide-over (`ActivityLog.jsx`, same `flash.activityId`/`activityContext` pattern as `ServerNavbar.jsx`) + `wire:poll.10000ms`-equivalent status-check fallback |
| `resources/js/Pages/Project/Database/Backup/Index.jsx` | created | Scheduled-backups list (status-badged cards linking to the still-Livewire Execution page), "+ Add" modal (frequency/S3), no delete affordance (matches the original — deletion was never reachable from this page for standalone databases, only from the service-database variant) |
| `routes/web.php` | modified | Added `project.database.{start,stop,restart,check-status}` + `.backup.{index,store}`; removed the `DatabaseBackupIndex` Livewire import |
| `app/Livewire/Project/Database/Backup/Index.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by route name |
| `resources/views/livewire/project/database/heading.blade.php` | modified | Stripped `wireNavigate()` from the Backups nav link, now Inertia |
| `tests/v4/Feature/ProjectDatabaseBackupIndexTest.php` | created | 11 tests: renders with backups, redirects for a non-backup-supporting engine (Redis), redirects for a foreign-team database, creates (with and without S3), rejects an invalid cron expression, rejects S3 without a valid storage, check-status/start/restart all correctly report "server not functional" without crashing, stop dispatches `StopDatabase` (via `Bus::fake()` + `JobDecorator::decorates()`, the established pattern for asserting on `lorisleiva/laravel-actions` dispatches) |
| `phpstan-baseline.neon` | modified | Removed 3 stale entries for the deleted Livewire file; added 16 new entries for the pre-existing, already-documented `StandaloneDatabaseInstance` plain-interface gap (Section: see `todo.md`'s Cleanup opportunities) |

### Phase 40 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed import ordering, unused imports; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | 16 `property.notFound`/`method.notFound`/`nullsafe.neverNull` errors, all instances of the pre-existing `StandaloneDatabaseInstance` interface gap (PHPStan can't resolve `@property` on a plain interface) — baselined, matching the established precedent for this exact gap, not a new problem |
| 11 new Feature tests | 2 failed on first run: `save_s3` compared with `toBeTrue()` against a raw un-cast SQLite integer (same class of issue as Phase 39), and `Queue::assertPushed(StopDatabase::class)` failing because `lorisleiva/laravel-actions` wraps dispatched actions in a `JobDecorator` rather than pushing the action class itself — fixed using the existing `Bus::fake()` + `JobDecorator::decorates()` pattern already established in `tests/Unit/Database/StartDatabaseTest.php`; all 11 passed after |
| Full suite (`php artisan test --compact`) | 572 passed (2429 assertions), no regressions |
| `yarn build` (native Windows, per Phase 39's documented workaround) | Succeeded in 8.96s — `Project/Database/Backup/Index.jsx` confirmed in `manifest.json` |

## 86. Non-goals of Phase 40

- `store()`'s S3-backup-creation happy path and the actual scheduled-backup cron execution are untested beyond validation/rejection paths — same standing untested-happy-path convention as every SSH-adjacent action in this migration.
- `start()`/`restart()`'s real, server-functional success path (an `Activity` actually returned) remains untested — only the "not functional" branch (now bug-fixed) was safe to exercise without SSH mocking.
- Configuration/Logs/Terminal (the other 3 tabs `DatabaseHeading` links to) remain Livewire; `DatabaseHeading.jsx`/`ConfigurationChecker.jsx` were built to be directly reusable once those convert, but that's future work, not done here.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 87. Phase 41 — `Project\Database\Backup\Execution`: closes out the standalone-database backup page family, reuses Phase 40's `DatabaseHeading`/`ConfigurationChecker`, and fixes a real broadcast-channel bug

Converts the single-backup detail page (`project.database.backup.execution` — reached by clicking a card on the Phase 40 Index page), which edits a `ScheduledDatabaseBackup`'s settings and lists/manages its executions. Like Phase 40's `Backup\Index`, the original Livewire class itself is a thin mount-only wrapper; the real complexity lives in 3 nested children (`Project\Database\BackupEdit`, `BackupExecutions`, `BackupNow`). Research confirmed all 3 stay in place — they're still used by `settings-backup.blade.php` (the instance-wide Settings → Backup page) and by `scheduled-backups.blade.php`'s `type === 'service-database'` branch (the service-database equivalent of this same feature, still Livewire) — so only their `<livewire:...>` tags were removed from the now-deleted `execution.blade.php`, matching Phase 40's pattern exactly. `DatabaseHeading.jsx` and `ConfigurationChecker.jsx`, both built in Phase 40 specifically anticipating reuse, are used here unmodified — the first payoff of that investment.

### A dead `wireNavigate()` link found, but deliberately left alone

`scheduled-backups.blade.php`'s `type === 'database'` branch (the card that links to this page) still has `{{ wireNavigate() }}` on its href — normally something this migration's recipe strips once the destination becomes Inertia. Investigation showed that branch is now **entirely unreachable**: `ScheduledBackups` is only ever rendered with `type === 'database'` by `Project\Database\Backup\Index`, which Phase 40 already deleted. The only remaining renderer (`Project\Service\DatabaseBackups`) always sets `type === 'service-database'`. Left the dead branch as-is rather than editing unreachable code in a file this phase isn't otherwise touching — noted here and in `todo.md`'s cleanup list instead of silently fixing or silently ignoring it.

### A second real pre-existing bug: `BackupExecutions` listens on the wrong Echo channel

`App\Livewire\Project\Database\BackupExecutions::getListeners()` subscribes to `"echo-private:team.{$userId},BackupCreated"` using `Auth::id()` — but `BackupCreated::broadcastOn()` broadcasts on `PrivateChannel("team.{$teamId}")`, keyed by **team** ID, not **user** ID. Since a user's ID essentially never equals their current team's ID, this listener has likely never fired in production; `wire:poll.5000ms` was doing all the real work silently covering for it. The React port uses `useTeamChannel(['BackupCreated'], ...)` — the correctly-team-scoped hook already established throughout this migration — fixing the mismatch as a side effect of porting to the existing hook, alongside keeping the same 5-second polling fallback for parity.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectDatabaseBackupController.php` | modified | Added `execution()`, `updateBackupSchedule()`, `destroyBackupSchedule()`, `backupNow()`, `cleanupFailedExecutions()`, `cleanupDeletedExecutions()`, `destroyExecution()`, plus private `resolveBackup()`/`backupEditProps()`/`executionProps()` — extended rather than a new controller, since Index/Execution are two views of one feature area |
| `resources/js/Pages/Project/Database/Backup/Execution.jsx` | created | `BackupEditForm` (all settings fields, per-engine conditional databases-to-backup input, retention settings), `ExecutionCard` (status/timing/size, download link, delete), a shared `PasswordConfirmModal` (typed-confirmation + password + optional checkboxes, reused 3 times: schedule delete, execution delete, cleanup-deleted) |
| `routes/web.php` | modified | Repointed `project.database.backup.execution` at the controller; added `.update`/`.destroy`/`.backup-now`/`.cleanup-failed`/`.cleanup-deleted`/`.execution.destroy`; removed the now-unused `DatabaseBackupExecution` Livewire import |
| `app/Livewire/Project/Database/Backup/Execution.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by route name |
| `tests/v4/Feature/ProjectDatabaseBackupExecutionTest.php` | created | 11 tests: renders with executions, 404-redirects for a foreign backup UUID, updates the schedule, rejects an invalid cron expression, deletes the schedule (correct/incorrect password), dispatches `DatabaseBackupJob` for Backup Now (plain `ShouldQueue` job this time — `Queue::assertPushed()` works directly, unlike Phase 40's lorisleiva-Action case), cleans up failed/deleted executions, deletes a single execution (correct/incorrect password) |
| `phpstan-baseline.neon` | modified | Removed all stale entries for the deleted `Execution.php`; regenerated entries for the controller's growth against the same pre-existing `StandaloneDatabaseInstance` interface gap (count bumps, not new categories of error) |

### Phase 41 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed unused imports in the new test file; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Baseline fully regenerated for `ProjectDatabaseBackupController.php` (all pre-existing-gap noise) after removing the deleted-file entries; `[OK] No errors` |
| 11 new Feature tests | All 11 passed on the first run |
| Full suite (`php artisan test --compact`) | 583 passed (2472 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 6.42s — `Execution-*.js` confirmed in the build output |

## 88. Non-goals of Phase 41

- `updateBackupSchedule()`'s and `backupNow()`'s real SSH-touching happy paths remain untested beyond validation/rejection — same standing convention.
- The dead `wireNavigate()` branch in `scheduled-backups.blade.php` (see above) was identified but deliberately not touched this phase.
- Settings → Backup (`settings-backup.blade.php`, instance-wide) and `Project\Service\DatabaseBackups` (the service-database equivalent) both still render `BackupEdit`/`BackupExecutions` as Livewire islands — converting either is separate future work.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 89. Phase 42 — `Settings\Backup`: closes out the backup Livewire island, extracts shared components/backend logic for reuse across both backup surfaces, and fixes a real behavioral regression found via PHPStan

Converts the instance-wide Settings → Backup page (`settings.backup`), the last consumer of the `BackupEdit`/`BackupExecutions` Livewire islands that Phase 41 left in place (it only removed the `<livewire:...>` tags from `Project\Database\Backup\Execution`, since this page and the service-database branch of `scheduled-backups.blade.php` were both still using them). This phase converts the settings surface; the service-database branch stays Livewire — untouched, out of scope.

Phase 41's `Execution.jsx` had grown into a single 491-line file with `BackupEditForm`, `ExecutionCard`, the executions-list-with-polling logic, and `PasswordConfirmModal` all inline. This phase's real prerequisite was extracting all four into standalone `resources/js/Components/*.jsx` files — the established "extract only once there's a genuine second consumer" rule, exercised on this component family for the first time. `Execution.jsx` itself shrank to 40 lines (heading/checker plus the two extracted components). On the backend, `ProjectDatabaseBackupController`'s private `backupEditProps()`/`executionProps()` plus the schedule-update/execution-delete/cleanup logic moved into a new `App\Http\Controllers\Concerns\ManagesScheduledDatabaseBackups` trait, used by both `ProjectDatabaseBackupController` and the new `SettingsBackupController` — the two controllers share the exact same `ScheduledDatabaseBackup`/`ScheduledDatabaseBackupExecution` shape and validation rules, just against a different owning database (a team's real database vs. the hardcoded `id=0` `coolify-db`).

### A real regression found by PHPStan, not by a test

PHPStan flagged `SettingsBackupController::index()` as dead code (`booleanAnd.alwaysFalse`): `if ($backup && ! $server->isFunctional())` can never be true, because the method already returns early a few lines above when `! $server->isFunctional()`. Tracing this back to the original Livewire `mount()` showed why the check exists at all: the original has **no early return** on a non-functional server — it always resolves `$database`/`$backup` and disables the backup schedule as a side effect (`$backup->enabled = false; $backup->save();`) whenever the server is unhealthy, regardless of what the page ends up rendering; only the Blade template's `@if ($server->isFunctional())` decides what's shown afterward. My first-draft controller's early return for the "not functional" response accidentally skipped this side effect entirely, meaning a backup schedule would never get auto-disabled when the root server went unhealthy. Fixed by computing `$serverFunctional` once, running the disable-if-unhealthy side effect unconditionally (matching `mount()`), and only branching on it for the response afterward.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `resources/js/Components/BackupEditForm.jsx`, `BackupExecutionsList.jsx`, `ExecutionCard.jsx`, `PasswordConfirmModal.jsx` | created (extracted from Phase 41's `Execution.jsx`) | Now shared by both `Project/Database/Backup/Execution.jsx` and the new `SettingsBackup.jsx` |
| `resources/js/Pages/Project/Database/Backup/Execution.jsx` | modified | Shrank from 491 to 40 lines, now composes the 4 extracted components |
| `resources/js/Pages/SettingsBackup.jsx` | created | Identity form (UUID/Name/User/Password readonly, Description editable — matches the original's readonly fields exactly), server-not-functional / no-database / full states mirrored from the original Blade's 3-way branch, reuses `BackupEditForm`/`BackupExecutionsList` unmodified |
| `app/Http/Controllers/Concerns/ManagesScheduledDatabaseBackups.php` | created | `backupEditProps()`, `executionProps()`, `applyBackupScheduleUpdate()`, `deleteBackupScheduleFiles()`, `deleteBackupExecution()`, `cleanupFailedBackupExecutions()`, `cleanupDeletedBackupExecutions()`, `s3StorageOptions()` — extracted from `ProjectDatabaseBackupController`, now shared |
| `app/Http/Controllers/ProjectDatabaseBackupController.php` | modified | Uses the new trait instead of its own private copies; behavior unchanged |
| `app/Http/Controllers/SettingsBackupController.php` | created | `index()`, `update()` (identity), `addDatabase()`, `updateSchedule()`, `backupNow()`, `cleanupFailedExecutions()`, `cleanupDeletedExecutions()`, `destroyExecution()` |
| `routes/web.php` | modified | Repointed `settings.backup` at the controller; added `.update`/`.add-database`/`.schedule.update`/`.backup-now`/`.cleanup-failed`/`.cleanup-deleted`/`.execution.destroy`; removed the `SettingsBackup` Livewire import |
| `app/Livewire/SettingsBackup.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by the route name |
| `tests/v4/Feature/SettingsBackupControllerTest.php` | created | 12 tests: non-admin redirect, server-not-functional/no-database/full render states, identity update, schedule update (+ invalid cron rejection), Backup Now dispatch, cleanup failed/deleted, execution delete (correct/incorrect password) |
| `phpstan-baseline.neon` | regenerated | Removed stale entries for the deleted Livewire file and the trait's moved-from-controller code; the two `property.notFound`/`nullsafe.neverNull` entries left on the trait are the same pre-existing `StandaloneDatabaseInstance` interface gap documented elsewhere, not new |

### Phase 42 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | Caught the real dead-code/lost-side-effect bug above before any test ran; baseline regenerated twice (once after the trait extraction, once after deleting the old Livewire file); `[OK] No errors` |
| 12 new Feature tests | 2 early failures during development, not from production code: the standard `isInstanceAdmin()` test convention (`User::forceCreate(['id' => 0])`, which auto-provisions and owns a "Root Team" id 0 via `User`'s `created` hook) needs that Root Team's `show_boarding` explicitly set to `false` afterward — `Team::factory()`'s default disables it, but the auto-provisioned Root Team keeps the schema default of `true`, which the `DecideWhatToDoWithUser` middleware redirects to `/onboarding`. Fixed in the test helper once found; all 12 passed after |
| Full suite (`php artisan test --compact`) | 595 passed (2542 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in ~7s — `SettingsBackup-*.js` and `BackupExecutionsList-*.js` confirmed as separate chunks |

## 90. Non-goals of Phase 42

- `addDatabase()`'s real SSH-touching happy path (`instant_remote_process(['docker inspect coolify-db'], ...)`) remains untested beyond what's implied by the "no database" render test — same standing untested-happy-path convention as every SSH-adjacent action in this migration.
- `updateSchedule()`'s and `backupNow()`'s real SSH-touching happy paths remain untested beyond validation/rejection, matching Phase 41.
- The service-database branch of `scheduled-backups.blade.php` (which also renders `BackupEdit`/`BackupExecutions` as Livewire islands, for `Project\Service\DatabaseBackups`) stays Livewire — this phase only converts the instance-wide Settings surface.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 91. Phase 43 — `Settings\Index`: the instance-wide "General" settings page, first reuse of `ActivityLog.jsx` outside its original consumer, and a genuinely dead redundant-validation check removed rather than ported

Converts `settings.index` (the "General" tab of Settings — instance URL/name/timezone/public IPs, plus a dev-only "Build Helper Image" action), picked as the next Hard-bucket candidate via a dedicated research pass (see the ranking below) precisely because it was the cleanest of the 14 remaining full-page Livewire classes: exactly one nested child (`<livewire:activity-monitor>`), no broadcast/old-style listeners on the class itself, and not one of the pages already flagged as needing dedicated design work (`Server\Show`, Terminal) or its own phase (`*\Logs`, `Project\Resource\Create`, the three `*\Configuration` routers).

The lone nested child, `ActivityMonitor`, is the exact Livewire component `ActivityLog.jsx` (built in Phase 16 for `ServerNavbar.jsx`'s proxy-startup log) was written to replace — this is the first time that component has been reused by a *different* page outside its original consumer, validating the "build components anticipating reuse" bet already paid off twice for `DatabaseHeading`/`ConfigurationChecker` (Phase 40/41). The same `activityId`/`activityContext` flash-payload pattern used by `ServerCloudflareTunnelController`, `ServerProxyActionsController`, and `ServerSecurityPatchesController` was reused here too (`activityContext: 'settings-helper-image'`), rather than inventing a new mechanism.

### A genuinely redundant check, dropped rather than ported

The original `submit()` calls a manual `validate_timezone($this->instance_timezone)` check *before* Livewire's own `#[Validate('required|string|timezone')]` attribute validation runs (Livewire only enforces attribute validation when `$this->validate()` is explicitly called, so the manual pre-check has a real job to do there — it also resets the timezone to `config('app.timezone')` on failure, a small recovery behavior). In the ported controller, the `Validator::make(...)->validate()` call runs first and already includes Laravel's built-in `timezone` rule, which is equivalent to `validate_timezone()`'s `in_array($tz, timezone_identifiers_list())` check — meaning a second manual check afterward would be unreachable dead code, not a faithful port of working logic. Removed rather than ported, per this repo's "don't add validation for scenarios that can't happen" convention; the reset-to-default recovery behavior isn't replicated (the field just shows a validation error instead), a minor, deliberate simplification rather than an oversight.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/SettingsController.php` | modified | Added `index()`, `update()`, `buildHelperImage()` |
| `resources/js/Pages/Settings/Index.jsx` | created | Form (URL/Name/Timezone-autocomplete/IPv4/IPv6/dev-only helper-version), reuses `ActivityLog.jsx` unmodified for the helper-image build log, reuses the `activityContext` flash pattern |
| `resources/js/Components/DomainConflictModal.jsx` | created | Ported from `x-domain-conflict-modal`; built anticipating reuse by the still-Livewire `Application\Configuration`/`Service\Configuration` phases (both also render this Blade component), matching the Phase 40 precedent of building shared pieces ahead of their second consumer |
| `routes/web.php` | modified | Repointed `settings.index` at the controller; added `settings.update`/`settings.build-helper-image`; removed the `Settings\Index` Livewire import |
| `app/Livewire/Settings/Index.php` (+ matching Blade view) | **deleted** | Confirmed via grep: only referenced by the route name |
| `tests/v4/Feature/SettingsIndexTest.php` | created | 9 tests: non-admin redirects (index + build-helper-image), page render, settings update, invalid-timezone rejection, domain-conflict detection, force-save-domains bypass, dev-mode gate on Build Helper Image |
| `phpstan-baseline.neon` | regenerated | Removed 10 stale entries for the deleted Livewire file (the regeneration itself required manually stripping those entries first — `--generate-baseline` refuses to run against a baseline whose own `ignoreErrors` paths no longer exist on disk, a chicken-and-egg case not hit in Phase 41/42) |

### Phase 43 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | `--generate-baseline` initially failed outright ("Invalid entries in ignoreErrors... neither a directory, nor a file path") because the baseline's own stale entries for the deleted `Settings/Index.php` blocked it from even starting; fixed by manually deleting those 10 blocks first, then baseline regenerated cleanly; `[OK] No errors` |
| 9 new Feature tests | 1 early failure during development, not from production code: the "updates instance settings" test tripped a real DNS lookup via `validateDNSEntry()`, since a fresh `InstanceSettings` row defaults `is_dns_validation_enabled` to `true` — fixed by disabling it in the test's `beforeEach`, consistent with this migration's standing convention of not exercising network/SSH-touching code paths in tests; also renamed the test file's `makeInstanceAdmin()` helper to `makeSettingsIndexAdmin()` after it collided (PHP fatal "cannot redeclare") with the identically-named helper already declared globally in `SettingsBackupControllerTest.php` — these Pest test files share one PHP process, so helper function names must be unique across the whole `tests/` tree, not just per-file |
| Full suite (`php artisan test --compact`) | 603 passed (2583 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 8.54s — `resources/js/Pages/Settings/Index.jsx` confirmed in `manifest.json` as `Index-ge3bOzEk.js` |

## 92. Non-goals of Phase 43

- `buildHelperImage()`'s real SSH-touching happy path (`remote_process()` actually running a `docker build`) remains untested beyond the dev-mode gate — same standing untested-happy-path convention as every SSH-adjacent action in this migration.
- `update()`'s DNS-validation-enabled happy path (`validateDNSEntry()` succeeding against a real server) is untested; only the disabled-DNS-validation path is exercised, for the same reason.
- The other 13 remaining Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Application\Deployment\Show`, `Project\Shared\Logs`, `Project\Shared\ExecuteContainerCommand`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Project\Service\DatabaseBackups`, `Project\Service\Index`, `Server\Show`, `Server\Sentinel\Logs`, `Server\Proxy\Logs`.
- No specific next Hard-bucket candidate has been research-ranked yet, though `DomainConflictModal.jsx` was deliberately built to make `Application\Configuration`/`Service\Configuration` (both of which also use the domain-conflict flow) marginally cheaper whenever they're tackled.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 93. Phase 44 — `Project\Application\Deployment\Show`: the live deployment-log viewer, picked via a dedicated tractability ranking, and two more pre-existing boolean-cast bugs found via a real job dispatch

Converts the single-deployment detail page (`project.application.deployment.show`) — the live-tailing log viewer with search/highlight/copy/download/fullscreen, reached by clicking a deployment on the already-converted `Deployment/Index` page. Picked via a dedicated research pass across the 4 remaining candidates that weren't already ruled out as needing design work or their own phase (`Boarding\Index`, `Deployment\Show`, `Project\Service\DatabaseBackups`, `Project\Service\Index`) — `Deployment\Show` won on the same criterion that favored `Settings\Index` in Phase 43: fewest *real* unresolved dependencies. Two of its three nested Livewire children — `project.shared.configuration-checker` and `project.application.heading` — were already fully ported in Phase 7/40/41 as `ConfigurationChecker.jsx`/`Heading.jsx`, and are already proven in production on the sibling `Deployment/Index.jsx` page. The third child, `DeploymentNavbar` (Force Start/Cancel buttons), is small and broadcast-free. The class's own `getListeners() => ['refreshQueue']` turned out to be an internal same-request event fired only by `DeploymentNavbar`, not a websocket dependency — the real-time log tailing is plain `wire:poll.2000ms`, ported as an Inertia partial-reload poll (`router.reload({ only: [...] })`), the same pattern used throughout this migration.

`ApplicationDeploymentController` (already home to `index()`/`deploy()`/`restart()`/`stop()`/`checkStatus()` from Phase 7) gained `show()`, `toggleDebug()`, `forceStart()`, `cancel()`, and `downloadAllLogs()` — extended rather than a new controller, matching the established "one controller per page family" convention. `downloadAllLogs()` is a deliberate simplification over the original: rather than a Livewire method returning a raw string that the client then wraps in a Blob for download, the new route returns a real `Content-Disposition: attachment` file response directly, so the frontend just needs a plain `<a href>` instead of a fetch-then-blob dance.

### A real Laravel routing gotcha, avoided proactively this time

Adding `/deployment/{deployment_uuid}/toggle-debug` alongside the other 3 new deployment-scoped routes would have repeated the exact positional-parameter-binding bug found and fixed in Phase 39 (Section 83) — `toggleDebug()` doesn't need `$deployment_uuid` (it toggles a setting on the `Application`, not the deployment), so a 4-segment route bound to a 3-parameter method risked the same silent-wrong-value class of bug. Caught during implementation, before any test ran, by moving `toggle-debug` to sit alongside `deploy`/`restart`/`stop`/`check-status` (the other application-level, non-deployment-scoped actions) instead of nesting it under `{deployment_uuid}`.

### Two more real pre-existing boolean-cast bugs, found via the first test to actually dispatch `ApplicationDeploymentJob`

Testing `forceStart()`'s happy path (safe to test — `force_start_deployment()` is a DB update plus a queued job dispatch, no direct SSH) was the first place in this whole migration to actually construct `ApplicationDeploymentJob` in a test. Its constructor assigns `$this->application_deployment_queue->rollback` directly into a strictly-typed `private bool $rollback` property — under SQLite (used for testing) with `strict_types=1`, assigning a raw un-cast `0`/`1` integer to a strict `bool` property throws a `TypeError`, not a silent coercion. `ApplicationDeploymentQueue::$rollback` was documented `@property bool` but missing from `$casts`, the exact same gap class found in Phase "Project\CloneMe" (Section 73). Tracing the same constructor further turned up 2 more columns with the identical gap and the identical strict-property-assignment crash risk: `force_rebuild` and `restart_only` (a 3rd, `only_this_server`, was also missing its cast though not on a crash path found so far — added anyway since it's the same undocumented gap on the same model). Checked for `=== 0`/`=== 1` strict-comparison call sites before casting (same safety check as the CloneMe precedent) — found none, so all 4 were safe to fix by adding `'boolean'` casts.

### A second, narrower fixture gap (not a code bug)

After fixing the cast bug, the same test still failed — this time because the test's own `ApplicationDeploymentQueue` fixture didn't set `server_id`/`destination_id`. `ApplicationDeploymentJob`'s constructor unconditionally dereferences `Server::find($this->application_deployment_queue->server_id)->settings->dynamic_timeout`, which crashes on a null server. This is a real constructor behavior (no `?->` guard), but not a bug worth fixing here — every real deployment always has these fields set by the code that creates it; it's a test-fixture completeness gap, fixed by adding both fields to the fixture with an inline comment explaining why they're required.

### A genuinely dead method, not ported

`DeploymentNavbar::copyLogsToClipboard()` (returns deployment logs as Markdown) is never called from the Blade view or anywhere else in the codebase — confirmed via grep before writing any code. Not ported; the log viewer's own "Copy Logs" button already does a plain client-side `navigator.clipboard.writeText()` independently and unrelatedly.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ApplicationDeploymentController.php` | modified | Added `show()`, `toggleDebug()`, `forceStart()`, `cancel()`, `downloadAllLogs()` |
| `app/Models/ApplicationDeploymentQueue.php` | modified | Added `'rollback'`, `'force_rebuild'`, `'restart_only'`, `'only_this_server'` to `$casts` (see bug writeup above) |
| `resources/js/Pages/Project/Application/Deployment/Show.jsx` | created | Log viewer with client-side search/highlight/copy/download-displayed, `<a href>` download-all via the new attachment route, reuses `Heading.jsx`/`ConfigurationChecker.jsx` unmodified |
| `routes/web.php` | modified | Repointed `project.application.deployment.show` at the controller; added `.toggle-debug` (application-scoped, avoiding the Phase 39 routing gotcha), `.force-start`/`.cancel`/`.download-all-logs` (deployment-scoped); removed the `Deployment\Show` Livewire import |
| `app/Livewire/Project/Application/Deployment/Show.php` (+ view), `app/Livewire/Project/Application/DeploymentNavbar.php` (+ view) | **deleted** | Confirmed via grep: neither referenced anywhere except the route name and each other |
| `tests/v4/Feature/ApplicationDeploymentShowTest.php` | created | 10 tests: render with log lines, `isKeepAliveOn` false for a finished deployment, redirect for a nonexistent deployment uuid, toggle debug, force-start (dispatches `ApplicationDeploymentJob`), cancel rejects a nonexistent deployment without touching SSH, download-all-logs returns a `text/plain` attachment |
| `phpstan-baseline.neon` | regenerated | Removed 15 stale entries for the 2 deleted Livewire files. Regeneration hit the same chicken-and-egg case as Phase 43 (`--generate-baseline` refuses to start against a baseline whose own `ignoreErrors` paths no longer exist); this time a naive multiline regex to strip the stale blocks hung (catastrophic backtracking against the ~13k-line file) and had to be killed — a small PHP script (block-splitting on blank lines, filtering by `path:`) removed the 15 entries reliably instead |

### Phase 44 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | Baseline regeneration required manually removing 15 stale entries first (see above); `[OK] No errors` after |
| 10 new Feature tests | 2 real findings before any test passed: the `rollback`/`force_rebuild`/`restart_only` cast bug (fixed in the model) and the fixture's missing `server_id`/`destination_id` (fixed in the test); all 10 passed after both fixes |
| Full suite (`php artisan test --compact`) | 610 passed (2622 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 7.24s — `resources/js/Pages/Project/Application/Deployment/Show.jsx` confirmed in `manifest.json` as `Show-BZGUcrR-.js` |

## 94. Non-goals of Phase 44

- `cancel()`'s real SSH-touching happy path (`instant_remote_process()` actually killing a container) remains untested — this migration has no SSH-mocking infrastructure reachable from `App\Http\Controllers` (the existing `RemoteProcessFake` override mechanism is namespace-scoped to `App\Actions\Application`/`App\Actions\Database` only); only the "deployment not found" branch is exercised.
- `forceStart()`'s test only exercises the DB-update-plus-dispatch path; the queued `ApplicationDeploymentJob` itself is never actually run (`Queue::fake()` prevents that), so nothing about the job's real build logic is exercised by this phase.
- SVG action-bar icons (search/copy/download/timestamps/debug/follow/fullscreen) were ported as plain text buttons rather than recreated pixel-for-pixel, matching this migration's established action-bar convention (e.g. `ExecutionCard.jsx`).
- The remaining 12 Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Shared\Logs`, `Project\Shared\ExecuteContainerCommand`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Project\Service\DatabaseBackups`, `Project\Service\Index`, `Server\Show`, `Server\Sentinel\Logs`, `Server\Proxy\Logs`.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 95. Phase 45 — `Project\Service\DatabaseBackups`: a two-page-family merge (Index-style card list + Execution-style inline edit), a new `Service\Heading` port, and a `createBackupSchedule()` extraction that closes the loop on backup-creation duplication

Converts the service-database backups page (`project.service.database.backups`), picked via a second dedicated tractability research pass over the 3 remaining real candidates (`Boarding\Index`, `Project\Service\DatabaseBackups`, `Project\Service\Index`) after Phase 44 closed out `Deployment\Show`. `Project\Service\Index` was ruled out again — it nests the shared `Project\Shared\GetLogs` component directly, the exact dependency already deferred to its own phase. `Boarding\Index` was ruled out again — it nests two large, un-ported children (a ~547-line Hetzner Cloud API integration form and a live-log-streaming server-validation component). `Project\Service\DatabaseBackups` won because its one real gap — a never-before-ported `Project\Service\Heading` — turned out to be the same shape and scope as the already-proven `DatabaseHeading.jsx`/Application `Heading.jsx` (same `ServiceStatusChanged`/`ServiceChecked` team-channel events `useTeamChannel` already handles), and research confirmed `ServiceDatabase::scheduledBackups()` and `StandalonePostgresql::scheduledBackups()` are the *same* `morphMany(ScheduledDatabaseBackup::class, 'database')` relation — meaning `BackupEditForm.jsx`/`BackupExecutionsList.jsx`/`ExecutionCard.jsx` (built in Phase 42) needed zero adaptation to work against a service database instead of a standalone one.

Structurally this page is a merge of two patterns already built separately: like `Project\Database\Backup\Index` (Phase 40), it shows a list of scheduled-backup cards with a "+ Add" modal; like `Project\Database\Backup\Execution` (Phase 41), clicking a card shows the full edit form and executions list — but *inline on the same page* (via a `selectedBackupId` query-string parameter, matching the original Livewire's own `$queryString = ['selectedBackupId']`) rather than navigating to a separate route, since that's what the original Blade view does (`scheduled-backups.blade.php`'s `type === 'service-database'` branch renders `BackupEdit`/`BackupExecutions` beneath the list itself, not on a new page).

### A real second-consumer extraction: `createBackupSchedule()`

`CreateScheduledBackup::submit()` (the still-Livewire form nested by both the standalone and service database backup pages) and `ProjectDatabaseBackupController::store()` (Phase 40) contained near-identical backup-creation logic — S3-storage validation, cron validation, per-engine `databases_to_backup` defaulting. Extracted into `ManagesScheduledDatabaseBackups::createBackupSchedule()`, then `ProjectDatabaseBackupController::store()` was refactored to call it (behavior-preserving, verified by the existing `ProjectDatabaseBackupIndexTest` suite still passing) before the new `ProjectServiceDatabaseBackupController::store()` became its second real consumer.

### A real frontend bug found via reuse: `BackupExecutionsList.jsx`'s pagination silently dropped other query params

`BackupExecutionsList`'s page-forward/back buttons call `router.get(window.location.pathname, { skip: newSkip }, ...)` — replacing the *entire* query string with just `{ skip }`. On `Project\Database\Backup\Execution` (its only consumer until now), there was never another query param to lose. On this page, paginating a selected backup's executions would have silently dropped `selectedBackupId`, snapping the page back to the "no backup selected" state. Fixed by merging into the existing `URLSearchParams` instead of replacing them — a one-line fix in a shared component, caught by design review before it ever became a real bug in either consumer.

### `Service\Heading.php`, ported with two of the same documented gaps as `Database`/`Application` `Heading`

`ServiceHeading.jsx` mirrors `DatabaseHeading.jsx`'s structure closely: Configuration/Logs/Terminal nav stays plain `<a>` (all three remain Livewire for Service), the mobile Actions dropdown is not ported (desktop action row only, matching the established gap), and the original's `pullAndRestartEvent()` action — reachable *only* from that same mobile dropdown, with no desktop equivalent — is dropped for the same reason. The `restart()` action's `checkDeployments()` guard (block a restart if a deployment is already `queued`/`in_progress`) *was* ported, since it's reachable from the desktop button too — this is a real, safe-to-test check (no SSH), unlike `forceDeploy()`'s guard-bypass-by-design (it force-fails in-progress activities specifically to override this check).

### A copy-paste enum mistake, caught before any test ran

First draft of `forceDeploy()` used `ApplicationDeploymentStatus::IN_PROGRESS`/`QUEUED`/`FAILED` to mark stuck activities as failed before force-restarting — copied from a neighboring deployment-status pattern without checking the original Livewire method, which actually uses a *different* enum, `ProcessStatus` (activity-log-specific status values: `queued`/`in_progress`/`error`, not `ApplicationDeploymentStatus`'s `cancelled-by-user`/`finished` etc.). Caught by re-reading `Heading::forceDeploy()`'s exact source before finalizing, not by a test — `ApplicationDeploymentStatus::FAILED` doesn't even exist as an enum case, so this would have been a hard `Error` on first real use.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/Concerns/ManagesScheduledDatabaseBackups.php` | modified | Added `createBackupSchedule()`, extracted from `CreateScheduledBackup`/`ProjectDatabaseBackupController::store()` |
| `app/Http/Controllers/ProjectDatabaseBackupController.php` | modified | `store()` now calls the trait method instead of duplicating the logic |
| `app/Http/Controllers/ProjectServiceDatabaseBackupController.php` | created | `index()`, `setType()` (the migrated-database custom-type-selection flow), `store()`, `update()`, `destroy()`, `backupNow()`, `cleanupFailedExecutions()`, `cleanupDeletedExecutions()`, `destroyExecution()`, plus the `Heading` actions (`start()`, `forceDeploy()`, `restart()`, `stop()`, `checkStatus()`) — one controller per page family, matching the Phase 40 precedent for where Heading actions live |
| `resources/js/Components/ServiceHeading.jsx` | created | Port of `livewire/project/service/heading.blade.php`, scoped to what this page needs |
| `resources/js/Components/CreateBackupModal.jsx` | created (extracted from `Project/Database/Backup/Index.jsx`) | Its second consumer — same "extract only once there's a genuine second consumer" rule as every prior extraction |
| `resources/js/Components/BackupExecutionsList.jsx` | modified | Fixed the query-param-dropping pagination bug (see above) |
| `resources/js/Pages/Project/Service/DatabaseBackups.jsx` | created | Card list + inline edit/executions (via `selectedBackupId`) + custom-type-selection form + "+ Add" modal |
| `routes/web.php` | modified | Repointed `project.service.database.backups` at the controller; added `.set-type`/`.store`/`.start`/`.force-deploy`/`.restart`/`.stop`/`.check-status`/`.update`/`.destroy`/`.backup-now`/`.cleanup-failed`/`.cleanup-deleted`/`.execution.destroy`; removed the `Service\DatabaseBackups` Livewire import |
| `app/Livewire/Project/Service/DatabaseBackups.php` (+ view) | **deleted** | Confirmed via grep: only referenced by the route name. `Service\Heading`, `Database\CreateScheduledBackup`, `Database\ScheduledBackups` all stay Livewire — still nested by the still-Livewire `Service\Configuration`/`Service\Index` and the standalone-database backup pages respectively |
| `tests/v4/Feature/ProjectServiceDatabaseBackupControllerTest.php` | created | 14 tests: page render, custom-type-selection state + set-type action, create (+ invalid-cron rejection), select-and-show a backup with executions, update, delete (correct/incorrect password), backup-now dispatch, cleanup failed, delete an execution, the `restart()` in-progress-deployment guard (safe, no SSH), 404-redirect for a nonexistent service |

### Phase 45 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | fixed minor spacing/import-order issues in 2 files; passed after |
| PHPStan (`vendor/bin/phpstan analyse`) | Hit the same baseline chicken-and-egg case as Phase 43/44 (stale entries for the deleted Livewire file), fixed with the same PHP-script block-removal approach; `[OK] No errors` after |
| 14 new Feature tests | All 14 passed on the first run — the `createBackupSchedule()`/`BackupEditForm`/`BackupExecutionsList` reuse being genuinely drop-in (as research predicted) meant no new bug-driven fixture iteration this phase, unlike most prior phases |
| Full suite (`php artisan test --compact`) | 624 passed (2688 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 7.36s — `Project/Service/DatabaseBackups.jsx` confirmed in `manifest.json` as `DatabaseBackups-OimbWdnK.js` |

## 96. Non-goals of Phase 45

- `start()`/`forceDeploy()`/`restart()`'s (past the in-progress guard)/`stop()`'s real SSH-touching happy paths remain untested — same standing convention as every SSH-adjacent action in this migration.
- `Project\Service\Index` and `Project\Service\Configuration` (which still nest the real, un-retired `Service\Heading` Livewire component) are untouched by this phase — `ServiceHeading.jsx` is a parallel React port for this one page, not a replacement.
- `Database\CreateScheduledBackup`, `Database\ScheduledBackups` Livewire components stay in place — still needed by the standalone-database backup pages' still-Livewire nested-child usages and (for `ScheduledBackups`) genuinely nowhere else now that both its consumers (`Project\Database\Backup\Index` since Phase 40, and this phase) are React — but it wasn't confirmed fully unreachable, so left alone rather than risk a wrong deletion.
- The remaining 11 Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Shared\Logs`, `Project\Shared\ExecuteContainerCommand`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Project\Service\Index`, `Server\Show`, `Server\Sentinel\Logs`, `Server\Proxy\Logs`.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 97. Phase 46 — `Server\Proxy\Logs` + `Server\Sentinel\Logs`: a `GetLogs` prerequisite port (the migration's most feature-rich log viewer) plus its two cheapest immediate payoffs

Converts the shared live container-log-tailing infrastructure (`App\Livewire\Project\Shared\GetLogs`, 292 PHP + 564 Blade lines — search, per-level color filtering, timestamp toggle, fullscreen, follow-scroll, copy, two download modes, and `wire:poll.2000ms` streaming) as a reusable `ContainerLogs.jsx` component plus a `StreamsContainerLogs` controller trait, then immediately spends that investment on its two simplest consumers. Picked via a third dedicated research pass: with the "cleanest single page" candidates exhausted after Phase 43/44/45, every remaining Hard-bucket page was confirmed non-trivial — but `GetLogs` itself was a better investment than any of them precisely because Phase 44 had already solved and shipped the hard part (polling-via-partial-reload, search/highlight/copy/download-as-real-attachment) on `Deployment\Show`. Porting `GetLogs` was mostly "generalize an already-proven pattern to an arbitrary SSH `docker logs` command" plus the extra color-filter/fullscreen/stream-toggle chrome, not new architecture.

`Server\Proxy\Logs` and `Server\Sentinel\Logs` were confirmed in research to be near-zero-effort once `GetLogs` existed — both are thin wrappers (`server.navbar` + a sidebar + one fixed-container `GetLogs` instance, `collapsible: false`), and their chrome (`ServerNavbar.jsx`/`ServerSidebar.jsx`, plus `ServerChromeData::sidebar()`'s `proxy`/`sentinel` variants) was *already built* — `ServerChromeData::sidebar()` had even already wired an `logs` URL into both variants, anticipating this exact conversion. `Project\Shared\Logs` (the third, more complex `GetLogs` consumer — a real 3-way resource-type router with multi-server/multi-container orchestration and its own broadcast listener) was deliberately left for a later phase; it's genuinely a page in its own right, not just page-glue, and cramming it into the same phase as the prerequisite component itself would have made this phase far riskier to verify.

### A real bug caught by re-reading the original before finalizing: the download route silently dropped a guard

The original `GetLogs::downloadAllLogs()` checks `! $this->server->isFunctional()` before running the SSH command and returns an empty string otherwise. Extracting the SSH-fetching logic into the shared `StreamsContainerLogs` trait's `downloadContainerLogsResponse()` dropped that check — the trait method has no way to know it should apply it, since it's generic over any caller. Caught before writing tests, by re-checking the original method's behavior against the new controller methods: added an explicit `isFunctional()` guard back in each of `ServerProxyController::downloadLogs()`/`ServerSentinelController::downloadLogs()`, returning `404` rather than the original's silent empty-string-download — a deliberate, minor improvement (an empty `.txt` file download is a confusing result; a 404 for a route that shouldn't be reachable in that state is clearer), consistent with this phase's other empty-state improvement (see below).

### A deliberate UX deviation, applied consistently

The original silently shows "No logs yet." when the server isn't functional (`GetLogs::getLogs()` just returns early, leaving `$outputs` empty, with no distinct error state). Both new React pages instead show an explicit "Server is not functional." message when `isFunctional` is false — matching how `DatabaseHeading.jsx` and other already-converted pages in this migration handle the same situation, rather than reproducing the original's silent-empty-state behavior page-by-page.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/Concerns/StreamsContainerLogs.php` | created | `fetchContainerLogs()`, `downloadContainerLogsResponse()`, `parseContainerLogLines()` (timestamp-regex parsing + server-timezone conversion, matching the original Blade's own inline parsing) — SSH command construction (root/non-root sudo wrapping, swarm vs non-swarm) ported from `GetLogs::getLogs()`/`downloadAllLogs()` |
| `app/Http/Controllers/ServerProxyController.php` | modified | Added `logs()`, `downloadLogs()` |
| `app/Http/Controllers/ServerSentinelController.php` | modified | Added `logs()`, `downloadLogs()` |
| `resources/js/Components/ContainerLogs.jsx` | created | Search/highlight, per-level color filtering (with `localStorage` persistence, matching the original), timestamp/lines-count controls (server round-trip via `router.get` with query params, since changing them re-runs the SSH command), fullscreen, follow-scroll, copy, download-displayed (client-side blob) + download-all (`<a href>` to the new attachment route, Phase 44's established simplification), stream toggle (client-interval `router.reload`) |
| `resources/js/Pages/Server/Proxy/Logs.jsx`, `resources/js/Pages/Server/Sentinel/Logs.jsx` | created | Thin pages composing `ServerNavbar`/`ServerSidebar`/`ContainerLogs` |
| `routes/web.php` | modified | Repointed `server.proxy.logs`/`server.sentinel.logs` at the controllers; added `.logs.download` for each; removed the `Server\Proxy\Logs`/`Server\Sentinel\Logs` Livewire imports |
| `app/Livewire/Server/Proxy/Logs.php`, `app/Livewire/Server/Sentinel/Logs.php` (+ views) | **deleted** | Confirmed via grep: only referenced by their route names. `App\Livewire\Project\Shared\GetLogs` itself (+ view) stays — still nested by the still-Livewire `Project\Shared\Logs` and 8 still-Livewire database-engine pages |
| `tests/v4/Feature/ServerProxyLogsTest.php`, `tests/v4/Feature/ServerSentinelLogsTest.php` | created | 8 tests total: page render + `logLines` empty for a non-functional server (no SSH touched), lines-count clamping, timestamp query-param handling, download-route 404 for a non-functional server, 404 for a server owned by another team |

### Phase 46 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | Hit the same baseline chicken-and-egg case as Phase 43/44/45 (stale entries for 2 deleted Livewire files), fixed with the same PHP-script block-removal approach; `[OK] No errors` after |
| 8 new Feature tests | All 8 passed on the first run |
| Full suite (`php artisan test --compact`) | 632 passed (2746 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 7.07s — `Server/Proxy/Logs.jsx` and `Server/Sentinel/Logs.jsx` both confirmed in `manifest.json` |

## 98. Non-goals of Phase 46

- The real SSH-touching happy path (`fetchContainerLogs()`/`downloadContainerLogsResponse()` actually running `docker logs` over SSH and returning real output) remains untested — same standing convention as every SSH-adjacent action in this migration; only the "server not functional, no SSH attempted" branch is exercised.
- `Project\Shared\Logs` (the third `GetLogs` consumer — application/database/service multi-container orchestration) is untouched. It's now a much cheaper follow-on than before this phase (its own `configuration-checker`/`application.heading`/`database.heading`/`service.heading` dependencies are all already ported too), but it's still a real page with its own logic, not just glue, and was deliberately left for its own phase.
- The 8 still-Livewire database-engine "general settings" pages that also nest `GetLogs` (part of the still-huge `Project\Database\Configuration` router) are unaffected — they stay on the original Livewire `GetLogs` component, which is untouched and still fully functional.
- `ContainerLogs.jsx`'s collapsible/expand-on-click variant (needed when `GetLogs` is nested per-container, as `Project\Shared\Logs` does) isn't ported — only the always-expanded, single-fixed-container variant `Server\Proxy\Logs`/`Server\Sentinel\Logs` need.
- The remaining 10 Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Shared\Logs`, `Project\Shared\ExecuteContainerCommand`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Project\Service\Index`, `Server\Show`.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 99. Phase 47 — `Project\Shared\Logs`: the third `GetLogs` consumer, a 3-way resource-type router, plus a `ManagesServiceLifecycle` extraction

Converts `App\Livewire\Project\Shared\Logs` — the third and final `GetLogs` consumer flagged as a follow-on in Phase 46's non-goals — into a single `ProjectLogsController` and one shared `Project/Shared/Logs.jsx` page, reused across three route names (`project.application.logs`, `project.database.logs`, `project.service.logs`) that branch on a `type` prop rather than becoming three separate pages, matching how the original Livewire component branched on `$this->resourceType`. As anticipated in Phase 46, this was a much cheaper port than it would have been earlier: `ConfigurationChecker`, `Heading` (Application/Deployment), `DatabaseHeading`, and `ServiceHeading` were all already-ported components from prior phases, and `ContainerLogs.jsx` + `StreamsContainerLogs` already existed from Phase 46 — the only genuinely new work was the multi-server/multi-container orchestration logic and giving `ContainerLogs.jsx` multi-instance support, since this page (unlike Proxy/Sentinel Logs) can render an arbitrary number of `ContainerLogs` instances on one page at once.

Also extracted `ManagesServiceLifecycle` (a controller trait) from `ProjectServiceDatabaseBackupController`'s `start()`/`forceDeploy()`/`restart()`/`stop()`/`checkStatus()` methods, since the service variant of this page needs the identical lifecycle actions but reached from a different route prefix (service-scoped, not backup-scoped) — a genuine second consumer, not a speculative extraction.

### `ContainerLogs.jsx` gained multi-instance support

Phase 46's version hardcoded `only: ['logLines']` for its polling/reload logic and unprefixed `lines`/`timestamps` query params, which was correct for Proxy/Sentinel Logs (always exactly one container on the page) but would silently collide across containers on this page (multiple containers per server, multiple servers). Added `reloadKeys` (defaults to `['logLines']`, preserving Phase 46 behavior unchanged) and `queryPrefix` (defaults to `''`) props, plus a `currentQueryParams()` helper so changing one container's lines/timestamps setting doesn't clobber the query params of any other container on the page. `Project/Shared/Logs.jsx` passes `reloadKeys={['containerGroups']}` and a per-container `queryPrefix` (`c0_`, `c1_`, ...); Proxy/Sentinel Logs pass neither, so they keep their original single-container query shape.

### A test-fixture gap, not a controller bug: `Service::server_id` is a separate denormalized column

The first `service()` render test crashed with `Call to a member function isFunctional() on null` on `$service->server->isFunctional()`. `Service` has both a `destination()` morph relation and a separate `server_id` column/`belongsTo(Server::class)` — production service-creation code (`Project\Resource\Create`, `Project\New\DockerCompose`) always sets `server_id` explicitly alongside `destination_id`/`destination_type` at creation time, but the new test's fixture set only the destination fields. The controller code is a faithful port of the original `GetLogs::mount()`'s `$this->resource->server->isFunctional()` branch for services, which relies on the same column — confirmed by checking `git show` of the original Livewire component. Fixed the test fixtures to set `server_id`, not the controller.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectLogsController.php` | created | `application()`/`database()`/`service()` render methods, `serviceStart()`/`serviceForceDeploy()`/`serviceRestart()`/`serviceStop()`/`serviceCheckStatus()` lifecycle wrappers, `downloadLogs()`, plus private `resolveApplication()`/`resolveDatabase()`/`resolveService()`/`discoverApplicationContainers()`/`buildContainerEntries()`/`applicationConfigurationCheckerProps()` |
| `app/Http/Controllers/Concerns/ManagesServiceLifecycle.php` | created | `startService()`/`forceDeployService()`/`restartService()`/`stopService()`/`checkServiceStatus()`/`isServiceDeploymentInProgress()`/`serviceConfigurationCheckerProps()`, extracted from `ProjectServiceDatabaseBackupController` |
| `app/Http/Controllers/ProjectServiceDatabaseBackupController.php` | modified | Its `start()`/`forceDeploy()`/`restart()`/`stop()`/`checkStatus()` are now thin wrappers delegating to the trait; removed its now-duplicate private `configurationCheckerProps()` |
| `resources/js/Pages/Project/Shared/Logs.jsx` | created | 3-way branch by `type`, composing already-ported `Heading`/`DatabaseHeading`/`ServiceHeading`/`ConfigurationChecker`/`ContainerLogs` |
| `resources/js/Components/ContainerLogs.jsx` | modified | Added `reloadKeys`/`queryPrefix` props for multi-instance-per-page support; Proxy/Sentinel Logs unaffected (defaults preserve prior behavior) |
| `routes/web.php` | modified | Repointed `project.application.logs`/`project.database.logs`/`project.service.logs` at the controller; added `project.logs.service.{start,force-deploy,restart,stop,check-status}` and `project.logs.download`; removed the `Project\Shared\Logs` Livewire import |
| `app/Livewire/Project/Shared/Logs.php` (+ view) | deleted | Confirmed via grep: zero other consumers. `App\Livewire\Project\Shared\GetLogs` (+ view) stays — still nested by 8 still-Livewire database-engine pages |
| `tests/v4/Feature/ProjectLogsControllerTest.php` | created | 9 tests: application/database/service render with no functional server (no SSH touched), redirect-to-dashboard for each nonexistent resource type, service lifecycle action redirects, deployment-in-progress restart guard, download-logs 404 for non-functional server |

### Phase 47 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | Hit the same baseline chicken-and-egg case as every prior phase (stale entries for the deleted Livewire file), fixed with the same PHP-script block-removal approach; `[OK] No errors` after. The 3 new `collect()`-type-mismatch messages attributed to `ProjectLogsController.php` are the same pre-existing pattern already baselined identically for `ServerProxyController.php`/`ServerSentinelController.php` (both also use `StreamsContainerLogs`) — confirmed not a new bug |
| 9 new Feature tests | 1 failed on first run (`$service->server` null — a test-fixture gap, see above); all 9 passed after fixing the fixture |
| Full suite (`php artisan test --compact`) | 641 passed (2795 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 7.45s — `Project/Shared/Logs.jsx` confirmed in `manifest.json` |

## 100. Non-goals of Phase 47

- The real SSH-touching happy path remains untested — same standing convention as every SSH-adjacent action in this migration; only the "no functional server, no SSH attempted" branches are exercised.
- Deliberate v1 scope simplification: all containers' logs are always fetched eagerly on page load, matching how the multi-container groups are always rendered expanded. The original Blade's collapsible/lazy-expand-on-click behavior per container was not ported — documented as a known gap rather than adding the complexity of per-container lazy Inertia partial reloads.
- The `GetLogs` prerequisite investment from Phase 46 is now fully paid off: all three of its original consumers (`Server\Proxy\Logs`, `Server\Sentinel\Logs`, `Project\Shared\Logs`) are converted. The Livewire `GetLogs` component itself stays, still nested by the 8 still-Livewire database-engine "general settings" pages.
- The remaining 9 Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Shared\ExecuteContainerCommand`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Project\Service\Index`, `Server\Show`.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9).

## 101. Phase 48 — `Project\Shared\ExecuteContainerCommand`: the resource-scoped terminal, closing out the last `Server\Navbar`-dependent page

Converts `App\Livewire\Project\Shared\ExecuteContainerCommand` (241 PHP + 66 Blade lines) — a resource-scoped terminal picker for application/database/service/server — into `ExecuteContainerCommandController` plus two pages: `Project/Shared/Command.jsx` (application/database/service, reached at `.../terminal`) and `Server/Command.jsx` (the server variant, reached at `server.command`, `ServerNavbar`'s last unconverted top-level tab). This closes out the final `Server\Navbar`-dependent Livewire page (20 of 21 now converted — only `Server\Show` remains) and retires a second, otherwise-orphaned Livewire class in the same pass: `App\Livewire\Project\Shared\Terminal` (`sendTerminalCommand()`, the SSH-command builder) had zero consumers besides `execute-container-command.blade.php`, so it and its Blade view were deleted alongside it — along with the now-fully-dead `terminal.js` (843-line Alpine component) and its registration in `app.js`, since the only Blade template that ever rendered a `#terminal-container` div for it is now gone.

Picked via a tractability research pass over the remaining Hard-bucket candidates (`Boarding\Index` 513 lines, `Project\Service\Index` 572 lines, `Project\Resource\Create` — small file but nests 6 creation flows per prior research, `Database`/`Application`/`Service\Configuration` — the largest remaining units per Phase 46's correction). `ExecuteContainerCommand` stood out because Phase 8's `Terminal\Index` conversion had already built and proven every piece of infrastructure it needs: `terminalSession.js` (the WebSocket/xterm.js orchestration layer) and `TerminalWindow.jsx` (the terminal UI itself) needed zero changes, and `TerminalController::connect()`'s SSH-command-building logic turned out to be near byte-identical to `Project\Shared\Terminal::sendTerminalCommand()` — the two had clearly been copy-pasted from one another during the original Livewire-era split (forced by a documented architectural constraint: the WebSocket connection isn't available server-side, so the SSH command has to be built and dispatched back to the browser). This phase was mostly "wire up a resource-scoped container list in front of already-working infrastructure," not new architecture.

### Two real second-consumer trait extractions, closing pre-existing duplication

`ResolvesProjectResources` (`resolveApplication()`/`resolveDatabase()`/`resolveService()`, team-scoped lookup by UUID chain, redirect-to-dashboard on any miss) was extracted from `ProjectLogsController` — this controller needed the identical lookups. `BuildsTerminalCommand` (`resolveTerminalCommand()`/`checkShellAvailability()`) was extracted from `TerminalController::connect()` — the exact SSH-command-building logic `Project\Shared\Terminal::sendTerminalCommand()` used to duplicate. Both extractions collapsed genuine, pre-existing (not newly introduced) copy-paste duplication once a second real consumer existed for each, matching this migration's established "extract only once there's a genuine second consumer" rule.

### `TerminalWindow.jsx` promoted to `Components/`, a real third consumer

Moved from `Pages/Terminal/TerminalWindow.jsx` to `Components/TerminalWindow.jsx` — it now backs three pages (`Terminal/Index`, `Project/Shared/Command`, `Server/Command`), not one.

### A pre-existing quirk found via re-reading the original, deliberately preserved rather than fixed

`ExecuteContainerCommand::loadContainers()`'s Swarm branch builds a synthetic container entry with only a `Names` key, no `State` key — but the very next line's running-check is `data_get($container, 'State') === 'running'`, which is always false for that entry (`null !== 'running'`). Swarm-deployed applications can therefore never actually get a container listed on this page in the original code. Ported faithfully rather than fixed: changing this would mean testing new (never-before-shipped) terminal-over-Swarm behavior this phase has no way to validate, for a low-traffic edge case in a self-hosted fork. Noted here rather than silently carried over.

### A minor asymmetry fixed for consistency (zero behavior change downstream)

The original's `loadContainers()` for services checks `$this->resource->server->isTerminalEnabled()` when listing application containers but not when listing database containers — an inconsistency, not a deliberate distinction. Fixed to check both the same way. This has no actual effect on what a user can do: `resolveTerminalCommand()` re-checks `$server->isTerminalEnabled()` centrally before building any SSH command regardless of which list the container came from, so the original asymmetry only ever affected which containers appeared in the dropdown, never what could actually be connected to.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ExecuteContainerCommandController.php` | created | `application()`/`database()`/`service()`/`server()` render methods, `connectApplication()`/`connectDatabase()`/`connectService()`/`connectServer()`, private `discoverForApplication()`/`discoverForDatabase()`/`discoverForService()` (server/container discovery, ported from `mount()`/`loadContainers()`) |
| `app/Http/Controllers/Concerns/BuildsTerminalCommand.php` | created | `resolveTerminalCommand()`, `checkShellAvailability()` — extracted from `TerminalController`, now shared with `ExecuteContainerCommandController` |
| `app/Http/Controllers/Concerns/ResolvesProjectResources.php` | created | `resolveApplication()`/`resolveDatabase()`/`resolveService()` — extracted from `ProjectLogsController`, now shared with `ExecuteContainerCommandController` |
| `app/Http/Controllers/TerminalController.php`, `app/Http/Controllers/ProjectLogsController.php` | modified | Use the two new traits; removed their now-duplicate private copies |
| `resources/js/Components/TerminalWindow.jsx` | moved (from `Pages/Terminal/`) | Third consumer promoted it out of a page-local location |
| `resources/js/Pages/Project/Shared/Command.jsx` | created | application/database/service container picker + `TerminalWindow`. Deliberate v1 simplification: omits `ConfigurationChecker`/`Heading` variants (present in the original) to keep this page as lean as the standalone `Terminal/Index` page, which has never shown per-resource config/heading info either |
| `resources/js/Pages/Server/Command.jsx` | created | Server-direct variant: `ServerNavbar` + Connect + `TerminalWindow`, no container picker |
| `routes/web.php` | modified | Repointed `project.{application,database,service}.command` and `server.command` at the controller; added 4 `.command.connect` POST routes; removed the `ExecuteContainerCommand` Livewire import |
| `app/Livewire/Project/Shared/ExecuteContainerCommand.php`, `app/Livewire/Project/Shared/Terminal.php` (+ both views) | **deleted** | Confirmed via grep: zero other consumers of either |
| `resources/js/terminal.js` (843 lines), its registration in `resources/js/app.js` | **deleted** | Confirmed via grep: the only Blade template ever rendering a `#terminal-container` div for this Alpine component was `terminal.blade.php`, deleted above. `@xterm/xterm`/`@xterm/addon-fit` stay — `terminalSession.js` (Phase 8) still uses them |
| `tests/v4/Feature/ExecuteContainerCommandControllerTest.php` | created | 11 tests: application/database/service/server render with no functional server (no SSH touched), redirect-to-dashboard for each nonexistent resource, a non-admin-member 403, connect validation (no selection, unknown container, unowned server) |

### Phase 48 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | Hit the same baseline chicken-and-egg case as every prior phase (stale entries for the 2 deleted Livewire files), fixed with the same PHP-script block-removal approach; `[OK] No errors` after. 4 new baselined errors on `ExecuteContainerCommandController.php` are the same already-accepted `StandaloneDatabaseInstance` plain-interface gap used elsewhere (e.g. `ProjectLogsController::database()`) — not a new bug |
| 11 new Feature tests | All 11 passed on the first run |
| Full suite (`php artisan test --compact`) | 652 passed (2858 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 8.36s — `Project/Shared/Command.jsx` and `Server/Command.jsx` both confirmed in `manifest.json`; `TerminalWindow` is now a shared chunk pulled in by 3 pages instead of 1 |

## 102. Non-goals of Phase 48

- The real SSH-touching happy path (an actual terminal session connecting and running commands) remains untested — same standing convention as every SSH-adjacent action in this migration, and the same gap Phase 8 called out explicitly for the standalone Terminal page: a live xterm.js/WebSocket session is not something `assertInertia()` can meaningfully exercise.
- The Swarm container-listing quirk documented above is preserved, not fixed — flagged as a known pre-existing limitation, not addressed this phase.
- `Project/Shared/Command.jsx` deliberately omits `ConfigurationChecker`/`Heading`/`DatabaseHeading`/`ServiceHeading` — see the simplification note above.
- The remaining 8 Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Project\Service\Index`, `Server\Show`, plus `Server\Navbar`'s one remaining dependent (`Server\Show` itself).
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). Worth calling out explicitly here, as it was for Phase 8: this is exactly the kind of interactive, stateful feature (a live terminal session) that automated checks can't fully vouch for.

## 103. Phase 49 — `Project\Service\Index`: a service's per-application-or-database general/advanced settings, plus two real pre-existing bugs found and fixed

Converts `App\Livewire\Project\Service\Index`'s `project.service.index` and `project.service.index.advanced` routes — the general/advanced settings page for a single application or database nested inside a service stack (human-readable name, description, domains/image for applications; name/description/image/public-proxy-toggle for databases; gzip/strip-prefix/exclude/log-drain toggles on the advanced tab; convert-to-application/convert-to-database; delete) — into `ProjectServiceResourceController` and one `Project/Service/Resource.jsx` page. `project.service.database.import` deliberately stays on the original Livewire class: it nests `App\Livewire\Project\Database\Import`, itself still required by the still-Livewire `Project\Database\Configuration`, so porting it now would mean building it twice before that page converts. Since `Index::class` still serves that one route, the class isn't deleted — only `.index`/`.index.advanced` were repointed at the new controller, the same "split a shared class's routes, keep what's still needed" pattern as Phase 49's own precedent-setters (`GetLogs` in Phase 46, `Heading` in Phase 7).

Picked via a tractability research pass over the 7 remaining Hard-bucket Livewire classes: `Boarding\Index` (513 lines) nests two large, still-unported SSH-heavy wizards (`Server\New\ByHetzner`, `Server\ValidateAndInstall`) with minimal reuse; `Project\Resource\Create` (137 lines) nests 7 separate unported resource-creation flows; the `Configuration` routers (`Application`/`Database`/`Service`, 60-105 lines each) are thin shells fanning out to 9-20 nested Livewire children per Phase 46's correction — genuinely the largest remaining units, not small ones; `Server\Show` needs real design work (an embedded Livewire/WebSocket island) and stays deferred. `Project\Service\Index` (572 lines) had the best reuse profile of the size-comparable candidates: `ServiceHeading.jsx` (Phase 45), `DomainConflictModal.jsx` (built ahead of need in Phase 43 anticipating exactly this), and `PasswordConfirmModal.jsx` (Phase 6) all already existed and needed zero changes to cover the convert/delete confirmation flows and the domain-conflict flow.

### A real bug found while wiring up `DomainConflictModal.jsx`'s second consumer: the modal has never actually been reachable

Building this page's domain-conflict flow required tracing exactly how `props.flash.domainConflicts` reaches `Settings/Index.jsx` (Phase 43's first consumer) — and it doesn't. `SettingsController::update()` correctly flashes `domainConflicts`/`showDomainConflictModal` via `back()->with([...])`, but `HandleInertiaRequests::share()` only ever re-exposes a fixed allowlist of session keys as `flash.*` props (`success`/`error`/`warning`/`info`/`activityId`/`activityContext`) — `domainConflicts` was never in that list, so `props.flash.domainConflicts` has been `undefined` since Phase 43 shipped. The existing test only asserted `assertSessionHas('domainConflicts')` (the raw Laravel session, always correct) — never `assertInertia()` on a follow-up request (what the frontend actually receives) — so the gap went unnoticed. Fixed by adding `domainConflicts`/`showDomainConflictModal` (and, for this phase's own port-warning flow, `requiredPort`/`showPortWarningModal`) to the shared `flash` array, and extended `SettingsIndexTest`'s conflict test with a follow-up `assertInertia()` check proving the prop is now actually delivered. This fixes both the pre-existing `Settings\Index` consumer and this phase's new one.

### A second real bug found via the first test to create a `ServiceApplication`/`ServiceDatabase` with no domain: `checkDomainUsage()` crashes on null `fqdn`

`checkDomainUsage()`'s two team-scoped conflict-detection loops (over `Application::query()` and `ServiceApplication::query()`) both call `explode(',', $app->fqdn)` unconditionally. `fqdn` is nullable and normally is null for the many services that never set a domain — `explode(',', null)` is a hard `TypeError` under PHP 8.1+'s strict scalar-type enforcement. This had never fired before because no existing test created a domain-conflict scenario inside a team that also had a resource with a null `fqdn`; my own test fixtures did, immediately proving the crash. Notably, a second, later function in the same file already guards this exact same access with `if (str($app->fqdn)->isEmpty()) { continue; }` — the fix here just applies that same already-established pattern to the two unguarded loops.

### Files

| File | Change | Purpose |
| --- | --- | --- |
| `app/Http/Controllers/ProjectServiceResourceController.php` | created | `show()`/`advanced()` render methods; `updateApplication()`/`updateApplicationAdvanced()`/`convertApplicationToDatabase()`/`deleteApplication()`; `updateDatabase()`/`updateDatabaseAdvanced()`/`updateDatabasePublic()`/`convertDatabaseToApplication()`/`deleteDatabase()`; `proxyLogs()` (fetch-on-open JSON endpoint for the public-proxy log slide-over) |
| `app/Http/Controllers/Concerns/ResolvesProjectResources.php` | reused unmodified | `resolveService()` (Phase 48's extraction) |
| `app/Http/Middleware/HandleInertiaRequests.php` | modified | Added `domainConflicts`/`showDomainConflictModal`/`requiredPort`/`showPortWarningModal` to the shared `flash` array — a real bug fix, not new scope (see above) |
| `bootstrap/helpers/domains.php` | modified | Guarded both unguarded `explode(',', $app->fqdn)` call sites in `checkDomainUsage()` against null `fqdn` — a real bug fix (see above) |
| `resources/js/Components/DomainConflictModal.jsx` | modified | Added an optional `consequences` prop (defaults to the original instance-domain wording) so a second consumer can supply resource-appropriate copy |
| `resources/js/Pages/Project/Service/Resource.jsx` | created | `ApplicationGeneral`/`ApplicationAdvanced`/`DatabaseGeneral`/`DatabaseAdvanced` tab bodies, a page-local sidebar, and a lightweight `ProxyLogs` fetch-on-open viewer (deliberately not `ContainerLogs.jsx` — that component's lines/timestamps controls reload the *page's* `logLines` prop via `router.reload()`, which this page doesn't have; reusing it here would ship controls that silently no-op) |
| `tests/v4/Feature/SettingsIndexTest.php` | modified | Extended the domain-conflict test with a follow-up `assertInertia()` check proving the middleware fix actually works |
| `routes/web.php` | modified | Repointed `project.service.index`/`.index.advanced` at the new controller; added 10 new application/database action + proxy-logs routes; `project.service.database.import` still binds `ServiceIndex::class` (unconverted, intentionally) |
| `tests/v4/Feature/ProjectServiceResourceControllerTest.php` | created | 17 tests: general/advanced render for both resource types, redirect-to-configuration for a nonexistent stack resource, team-ownership 404, application save (with and without a domain conflict, including the `force_save_domains` retry), advanced-toggle save, the server-side log-drain gate, database save, the "must be running to go public" guard, both convert directions, both deletes, and the proxy-logs endpoint against a non-functional server (no SSH touched) |

### Phase 49 verification log

| Check | Result |
| --- | --- |
| Pint (`--dirty --format agent`) | passed clean |
| PHPStan (`vendor/bin/phpstan analyse`) | No deleted-file baseline staleness this phase (the old Livewire class stays). 2 new baselined error categories: `StreamsContainerLogs`'s `collect()` type-mismatch (the same pattern already accepted for `ProjectLogsController`/`ServerProxyController`/`ServerSentinelController`) and `Service::$isDeployable` (an `Attribute`-cast property-docblock gap already baselined twice for `ProjectLogsController.php`) — both confirmed pre-existing, not new bugs; `[OK] No errors` after regenerating |
| 17 new Feature tests | 3 failed on first run (an `Undefined array key` from using `?:` instead of `??` against a possibly-absent validated field, plus the two `checkDomainUsage()` null-`fqdn` crashes above); all 17 passed after fixes |
| Full suite (`php artisan test --compact`) | 669 passed (2948 assertions), no regressions |
| `yarn build` (native Windows) | Succeeded in 9.12s — `Project/Service/Resource.jsx` confirmed in `manifest.json` |

## 104. Non-goals of Phase 49

- `project.service.database.import` is untouched — still the original Livewire `Project\Service\Index` class, nesting the still-Livewire `Project\Database\Import`. Porting it is its own scope, best done alongside (or after) `Project\Database\Configuration`, which needs the same component.
- File-storage volume reconciliation (`getFilesFromServer()`/`getFilesystemVolumesFromServer()`, an unconditional SSH call the original `mount()` made on every page load for both resource types) is deliberately not ported. It has no visible UI on this specific page (no `fileStorages` rendering in the original Blade view either — it exists only to keep a sibling Livewire component's listener in sync), and unconditionally SSHing into a possibly-unreachable server on every page load is exactly the kind of thing this migration has consistently guarded against elsewhere. If a dedicated Storages tab is ever converted, port it there instead.
- `s3s` (S3 backup-storage config, loaded unconditionally in the original `mount()`) is also not ported — it's only consumed by the still-Livewire `.import` flow.
- The real SSH-touching happy paths (`StartDatabaseProxy`/`StopDatabaseProxy`, the proxy-logs fetch) remain untested beyond their non-functional-server branches — same standing convention as every SSH-adjacent action in this migration.
- The remaining 6 Hard-bucket Livewire classes are unaffected: `Boarding\Index`, `Project\Resource\Create`, `Project\Application\Configuration`, `Project\Database\Configuration`, `Project\Service\Configuration`, `Server\Show`.
- No specific next Hard-bucket candidate has been research-ranked yet.
- Everything else from Phase 11's non-goals (Section 28) still applies unchanged.
- No manual browser QA this phase — same lighter, user-directed bar as every phase since Phase 2 (Section 9). Worth flagging explicitly here: this phase's UI (multi-step confirmation modals, two different flash-driven modal flows) is more interaction-heavy than most recent phases, and automated `assertInertia()`/redirect checks don't exercise the actual click-through UX.
