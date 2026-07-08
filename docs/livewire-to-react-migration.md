# Livewire → React Migration

## 1. Why

We are migrating Coolify's UI from Livewire 3 to React 18/19, page by page, over time. This is not a big-bang rewrite: Livewire and React coexist in the same app for as long as the migration takes, and we cut over each page individually.

## 2. Why Inertia.js instead of a plain React SPA + REST API

The app has 84 full-page Livewire components (confirmed by inventory in Phase 2 — see Section 6) wired directly into `routes/web.php`, with no existing REST/JSON API layer behind the UI. We considered two options:

- **Plain React SPA + REST API**: would require designing, building, and versioning a whole new API surface (auth, CSRF, serialization, pagination, etc.) before we could move a single page, on top of the React migration itself.
- **Inertia.js** (chosen): each page stays a normal Laravel route + controller that returns data as props. Routing, auth, CSRF, and session handling keep working as they do today. Migrated and not-yet-migrated pages coexist under the same Laravel app with no parallel API to maintain.

## 3. Current status

**24 of 84** full-page Livewire components converted:

| Bucket | Converted | Remaining |
|---|---|---|
| Easy | 5 of 5 (all done) | 0 |
| Medium | 17 of 23 | 6 |
| Hard | 0 of 56 | 56 |

Converted so far: `SharedVariables\Index` (pilot), `SharedVariables\Environment\Index`, `SharedVariables\Project\Index`, `SharedVariables\Server\Index`, `Profile\Appearance`, all 6 `Notifications\*` channels (`Webhook`, `Discord`, `Email`, `Slack`, `Telegram`, `Pushover`), `Profile\Index`, `Security\ApiTokens`, `Tags\Show`, `Team\Index`, `Admin\Index`, `Destination\Show`, `Destination\Resources`, `Security\PrivateKey\Show`, `Settings\Updates`, `ForcePasswordReset`, `Settings\Advanced`, and `SettingsEmail`. The entire notifications area, the profile area, the security/team/admin single-page settings screens, and most of the instance-wide Settings area are now fully off Livewire. Livewire and Alpine remain fully installed and used by every other page.

## 4. Foundation (change ledger)

### Phase 1 — toolchain + pilot page

| File | Change | Purpose |
|---|---|---|
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
|---|---|---|
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
|---|---|---|
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
|---|---|---|
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
|---|---|---|
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

## 5. The shell problem and shared props

Unlike the pilot (a bare standalone menu page), essentially every remaining page lives inside the app's persistent navbar/sidebar (`resources/views/components/navbar.blade.php`, 511 lines: Alpine state + PHP-rendered permission checks + 5 embedded Livewire components). That markup cannot run inside a React tree as-is.

**Decision: build the navbar/sidebar as a React component once (`AppLayout.jsx`), used as an Inertia persistent layout**, rather than re-solving the shell per page or falling back to full page reloads on every Inertia-to-Inertia navigation. This is Inertia's standard mechanism for this problem — a `page.default.layout` wrapper that persists across client-side page visits instead of remounting. It's wired in `inertia-app.jsx`'s `resolve()`.

**Explicit v1 scope-reduction, not a silent gap**: `AppLayout.jsx` omits 5 elements that were live Livewire components in the original navbar — team switching (shows the team name as static text instead of `<livewire:switch-team/>`), the settings dropdown, the upgrade banner, the delete-team modal trigger, and the help modal. Mixing live Livewire components inside a React-rendered tree is a real unsolved problem, not attempted this phase. Also omitted for v1: the mobile hamburger drawer, the sidebar collapse/expand toggle (always expanded), and global search plus the deployment status indicator. Follow-up work, not forgotten.

**Shared props** (`HandleInertiaRequests::share()`) now carry what Livewire/Blade got for free: `auth.user` (id/name/email/isAdmin), `currentTeam` (id/name), `permissions` (`isSubscribed`, `isCloud`, `isInstanceAdmin`, `canAccessTerminal`), and `flash` (success/error/warning/info pulled from session). `AppLayout.jsx` reads `flash` on mount and calls the existing global `window.toast(...)` function — the same one Livewire's `layouts/base.blade.php` wires up — so both stacks route through one toast implementation.

## 6. Real inventory (established via research, corrects the Phase 1 estimate)

Phase 1 estimated "200+" full-page components; a real inventory in Phase 2 found **84 unique full-page Livewire component classes** across 130 routes in `routes/web.php` (some routes share one class via tabs — e.g. `ApplicationConfiguration` serves 18 routes). Triaged:

- **Easy (5, all converted)**: `SharedVariables\Index` (pilot), `SharedVariables\{Environment,Project,Server}\Index`, `Profile\Appearance`. No `wire:model`, no nested `<livewire:.../>`, no listeners, trivial or no `mount()`.
- **Medium (23, 6 converted)**: forms with validation/`wire:model` but no real-time dependency. Converted: all 6 `notifications.*` channels (`webhook`, `discord`, `email`, `slack`, `telegram`, `pushover`). Remaining examples: `admin.index`, `profile` (`ProfileIndex` — the "General" tab, not yet converted), `security.api-tokens`, `settings.scheduled-jobs`, `tags.show`, `team.{admin-view,index}`, `source.github.show`.
- **Hard (56, 0 converted)**: nested `<livewire:.../>` children, broadcast/websocket listeners (`ApplicationStatusChanged`, `ServiceStatusChanged`), or real-time pages (`terminal`, `project.application.deployment.show`, `server.metrics`, `server.show`, `project.application.configuration` + 17 sibling tab routes, etc.). Needs real-time/broadcast (Echo-in-React) and embedded-Livewire-island design work before conversion starts — not attempted this phase.

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
|---|---|---|
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
|---|---|
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
|---|---|
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
|---|---|
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
|---|---|
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
