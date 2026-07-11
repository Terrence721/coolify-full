# TODO

**Last Updated: July 11, 2026**

A living list of what's done and what's left on this fork. This is a self-hosted-only fork of Coolify — the goal is a clean, no-frills, enterprise-friendly self-hosted deployment platform with no billing/marketing surface area.

## Done

**De-commercialization**
- Removed the "Love Coolify? Support our work" sponsorship popup and its Settings toggle.
- Removed the "Sponsor us" navbar link.
- Trimmed the navbar's bottom menu down to just Theme and Logout (removed What's New, Upgrade, Feedback).
- Fully removed the Stripe/subscription billing subsystem: Stripe actions, webhook handler, subscription pages, the `subscriptions` database table, and every code path that gated features on subscription/payment status (scheduled jobs, server cleanup, admin tools, notifications). Server-count limits still exist as a plain team setting (no billing tie-in) but are effectively unlimited for a self-hosted instance.
- Removed the `stripe/stripe-php` Composer dependency.
- Removed two Hetzner Cloud affiliate-link blocks ("Coolify's affiliate link... supports us (€10) and gives you €20") — one in the shared `Security\CloudProviderTokenForm` Livewire component, one already carried over into the converted `Security\CloudTokens.jsx` page.

**Livewire → React/Inertia migration** (see `docs/livewire-to-react-migration.md` for the full ledger)
- 59 of 84 full-page Livewire components converted to Inertia + React.
- Easy and Medium buckets: 100% done (25/25).
- Hard bucket: 32 of 59 done, including the shared `Server` navbar/sidebar chrome and twelve non-`Server`-navbar-scoped Hard-bucket pages (`Security\PrivateKey\Index`, `Destination\Index`, `Project\Show`, `Project\Edit`, `Storage\Index`, `Project\Index`, `Storage\Show`, `Storage\Resources`, `Project\EnvironmentEdit`, `Team\Member\Index`, `Server\Index`, `Project\CloneMe`). The whole `Storage\*` Livewire area and every `Team\*` single-page Livewire component are now fully retired (except the shared `Team\Create`/`Storage\Create` modals, still used by `GlobalSearch`).
- `Server\Index`'s "Add Server" modal only ports the IP-based creation flow — Hetzner Cloud server creation (`Server\New\ByHetzner`, a ~550-line multi-step wizard with live Hetzner API calls) is intentionally not ported yet; it's still reachable via `GlobalSearch`'s own unconverted Add Server modal. See Section 71 of the migration doc.
- Found and fixed three real pre-existing bugs while converting `Project\CloneMe` on 2026-07-11, all surfaced by the first automated test to exercise `clone_application()`: 12 columns across `ApplicationSetting`/`Application` were documented as `@property bool` but missing from `$casts` (fixed by adding the casts — see Section 73 of the migration doc for the full list and the strict-comparison safety check done first); `clone_application()` passed a `Stringable` to `base64_encode()` under `strict_types=1` (fixed with an explicit `->toString()`).
- Removed `SECURITY.md` and `CODE_OF_CONDUCT.md` on 2026-07-11 — both were leftover upstream-Coolify files pointing real reports at coollabs' own addresses (`security@coollabs.io`, `hi@coollabs.io`), not applicable to this fork. `CONTRIBUTING.md` was left as-is since it already carries an accurate disclaimer distinguishing itself from this fork.

## Still to do

**Migration**
- 27 Hard-bucket pages remain on Livewire, including 5 of 21 `Server\Navbar`-dependent pages (Terminal command, `Server\Show`, plus Dynamic Configurations/Logs within Proxy and Logs within Sentinel). `Server\Show` and Terminal both need real design work before conversion (embedded Livewire island / WebSocket bridge, respectively) — see Section 68 of the migration doc.
- Every SSH-touching action converted so far has an untested happy-path gap (verified only via safe/validation-rejection paths in Pest) — see `docs/smoketest.md` for the manual QA checklist that closes this gap.
- `/terminal` (still Livewire): observed an endless WebSocket reconnect loop during manual QA on 2026-07-10 (handshake authenticates successfully server-side, then the connection closes abnormally, code 1006). Likely just this dev environment lacking a genuinely reachable SSH target, not a code bug — needs real validation once Terminal is converted. See the note in `docs/smoketest.md`'s Terminal checklist.

**Cleanup opportunities (lower priority, not blocking)**
- Found and fixed one real regression from this migration via a CI failure on 2026-07-10: `App\Livewire\Team\Storage\Show.php` (a separate, completely dead Livewire class with zero routes/consumers) hardcoded `view('livewire.storage.show')`, the Blade view deleted in Phase 30 — it only "worked" by coincidence of the shared view path, not a real dependency. Deleted outright. No sweep was done for similar dead-code references to views deleted in other phases (e.g. Phase 25's `x-security.navbar`, Phase 26's `Destination\New\Docker`) — only the one CI surfaced was checked. If PHPStan/CI turns up another, same fix: confirm zero real consumers, delete outright.
- Chrome DevTools' Issues tab flagged 14 instances of "A form field element should have an id or name attribute" on the converted Server/Metrics page during manual QA on 2026-07-10 — likely `<input>`/`<select>` elements across several converted React pages missing an explicit `id`/`name` (relying only on a wrapping `<label>` for the accessible name). Worth a dedicated accessibility pass across `resources/js/Pages/` once the migration itself is further along.
- `App\Contracts\StandaloneDatabaseInstance` is a plain interface, and PHPStan/Larastan doesn't resolve `@property` PHPDoc declared on a plain interface (only on classes) — so any polymorphic `MorphTo` relation resolving to a standalone database model (e.g. `ScheduledDatabaseBackup::database()`, used in `StorageController::resources()`) shows `->name`/`->environment`/`->uuid` etc. as "undefined property" to static analysis, even though every real instance has them. Already an accepted, pre-existing gap tracked via `phpstan-baseline.neon` rather than per-file suppressions (see the interface's own docblock) — a real fix would mean converting the interface into an abstract base class so PHPStan can see the declared properties, which touches all 8 database engine models and everything that depends on the contract. Out of scope for the Livewire→Inertia migration; worth a dedicated pass if you want PHPStan fully clean without any baseline entries.
- A few still-Livewire components became unreachable from the UI during the navbar trim (`Upgrade`, `Help`/Feedback modal is still used elsewhere so it's fine, `SettingsDropdown`'s "What's New" changelog trigger) — worth a decision on whether to delete them outright or leave them for a future settings/about page.
- `Team::serverLimit()` / `custom_server_limit` still exist as general infrastructure (no longer settable via any billing flow) — could be simplified further if per-team server limits aren't a feature you want at all.
- `App\Models\Application::parseContainerLabels()` has the same `Stringable`-into-`base64_encode()` bug pattern fixed elsewhere on 2026-07-11 (see above), at two call sites. Not fixed — only reachable when `mb_detect_encoding()` fails on already-decoded labels, a narrower trigger than the one that surfaced the bug during the `Project\CloneMe` conversion.
- `Application::health_check_enabled` and `Application::custom_healthcheck_found` are also documented as `@property bool` but missing from `$casts` (same gap as the 12 columns fixed on 2026-07-11). Deliberately not fixed — `health_check_enabled` has a real `=== false` strict-comparison call site (`app/Models/Application.php:1491`) that currently never evaluates true against a raw int, so adding the cast would be a genuine behavior change needing its own dedicated verification, not a side effect of a Project page conversion.
- Once the Livewire→React migration is 100% complete, flip `'livewire' => true` off in `config/debugbar.php` (see conversation history — intentionally left on until then, since it's still useful for the pages that haven't converted yet).

**Verification standing habit**
- Every change in this repo is expected to go through: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse`, `php artisan test --compact`, and `yarn build` before being considered done. See `docs/command.md` for the exact commands.
