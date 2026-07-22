# 📝 TODO

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 22, 2026**

> The WSL2 dev-environment showcase that briefly replaced this file lives at [docs/wsl2-environment.md](docs/wsl2-environment.md).

A living list of what's done and what's left on this fork. This is a self-hosted-only fork of Coolify — the goal is a clean, no-frills, enterprise-friendly self-hosted deployment platform with no billing/marketing surface area.

## At a glance

This file is long and detailed on purpose — every claim below is backed by evidence (line numbers, exact commands, verification steps), not just asserted. That detail is useful if you want to check a specific claim, but it's not built for skimming, so here's the skim version first.

**Done, in full:**

| Item | Detail |
|---|---|
| Livewire→React migration | 84/84 pages, complete 2026-07-14 — see "Livewire → React/Inertia migration" below |
| PHPStan baseline hardening | 1,306 → 60, 65 phases — see "PHPStan baseline reductions" below |
| De-commercialization | Billing/Stripe fully removed |
| Security hardening | CodeQL + Psalm, 11 real CVEs patched, 2 real findings fixed — see "GitHub repo-level security features" below |
| Laravel backend audit | Team-scoping, Sanctum tokens, cross-device reachability — see "Laravel backend improvements" below |

**Actually still open, right now:** 7 items — see the overview table at the top of **Still to do** below.

If you only read one section of this file, read this one and that table — everything else is the evidence trail behind them.

## ✅ Done

### De-commercialization

| Removed | Detail |
|---|---|
| Sponsorship popup | "Love Coolify? Support our work" popup + its Settings toggle |
| Navbar sponsor link | "Sponsor us" link |
| Navbar bottom menu | Trimmed to just Theme and Logout (removed What's New, Upgrade, Feedback) |
| Stripe/subscription billing subsystem | Actions, webhook handler, subscription pages, the `subscriptions` table, and every feature-gate tied to subscription/payment status. Server-count limits still exist as a plain team setting, effectively unlimited for a self-hosted instance |
| `stripe/stripe-php` | Composer dependency removed |
| Hetzner Cloud affiliate links | 2 blocks removed (shared `Security\CloudProviderTokenForm` Livewire component + the converted `Security\CloudTokens.jsx` page) |

### Livewire → React/Inertia migration

**Complete as of Phase 79 (2026-07-14)** — 84 of 84 full-page Livewire components converted. Full status statement and per-phase design decisions live in `docs/livewire-to-react-migration.md`; not duplicated here. Dates below are commit dates, matched to each phase by page name; the 8 marked `~` predate this fork's one-phase-one-commit discipline and are inferred from the surrounding same-day commit cluster.

| Phase | Page(s) converted | Bucket | Date |
|---|---|---|---|
| 1 | `SharedVariables\Index` (pilot) | Easy | ~2026-07-08 |
| 2 | `SharedVariables\{Environment,Project,Server}\Index`, `Profile\Appearance`; persistent layout + shared props foundation | Easy + Medium | ~2026-07-08 |
| 3 | Notifications: Discord, Email, Slack, Telegram, Pushover | Medium | ~2026-07-08 |
| 4 | `Profile\Index`, `Security\ApiTokens`, `Tags\Show`, `Team\Index`, `Admin\Index` | Medium | ~2026-07-08 |
| 5 | `Destination\Show`, `Security\PrivateKey\Show`, `Settings\Updates`, `ForcePasswordReset`, `Settings\Advanced`, `SettingsEmail` | Medium | 2026-07-08 |
| 6 | `Team\AdminView`, `SettingsOauth`, `Settings\ScheduledJobs` | Medium | 2026-07-08 |
| 7 | `Project\Application\Deployment\Index` + Echo-in-React foundation | Hard | ~2026-07-09 |
| 8 | `Terminal\Index` | Hard | 2026-07-09 |
| 9 | `Security\CloudTokens` | Hard | 2026-07-09 |
| 10 | `Security\CloudInitScripts` | Hard | 2026-07-09 |
| 11 | `Server\Navbar` foundation + 3 pilot pages | Hard | ~2026-07-09 |
| 12 | `Server\Advanced` | Hard | 2026-07-09 |
| 13 | `Server\CaCertificate\Show` | Hard | 2026-07-09 |
| 14 | `Server\LogDrains` | Hard | 2026-07-09 |
| 15 | `Server\Resources` | Hard | 2026-07-09 |
| 16 | `Server\Security\Patches` | Hard | 2026-07-09 |
| 17 | `Server\CloudflareTunnel` | Hard | 2026-07-09 |
| 18 | `Server\PrivateKey\Show` | Hard | 2026-07-09 |
| 19 | `Server\Destinations` | Hard | 2026-07-09 |
| 20 | `Server\DockerCleanup` | Hard | 2026-07-09 |
| 21 | `Server\CloudProviderToken\Show` | Hard | ~2026-07-09 |
| 22 | `Server\Charts` (Metrics) | Hard | ~2026-07-10 |
| 23 | `Server\Proxy\Show` | Hard | 2026-07-10 |
| 24 | `Server\Sentinel\Show` | Hard | 2026-07-10 |
| 25 | `Security\PrivateKey\Index` | Hard | 2026-07-10 |
| 26 | `Destination\Index` | Hard | 2026-07-10 |
| 27 | `Project\Show` + `Project\Edit` | Hard | 2026-07-10 |
| 28 | `Storage\Index` | Hard | 2026-07-10 |
| 29 | `Project\Index` | Hard | 2026-07-10 |
| 30 | `Storage\Show` + `Storage\Resources` | Hard | 2026-07-10 |
| 31 | `Project\EnvironmentEdit` | Hard | 2026-07-10 |
| 32 | `Team\Member\Index` | Hard | 2026-07-10 |
| 33 | `Server\Index` | Hard | 2026-07-10 |
| 34 | `Project\CloneMe` | Hard | 2026-07-11 |
| 35 | `Project\Resource\Index` | Hard | 2026-07-11 |
| 36 | `Dashboard` | Hard | 2026-07-11 |
| 37 | `Server\Proxy\DynamicConfigurations` | Hard | 2026-07-11 |
| 38 | `Source\Github\Change` | Hard | 2026-07-11 |
| 39 | `SharedVariables\{Team,Project,Environment,Server}\Show` | Hard | 2026-07-11 |
| 40 | `Project\Database\Backup\Index` | Hard | 2026-07-11 |
| 41 | `Project\Database\Backup\Execution` | Hard | 2026-07-11 |
| 42 | `Settings\Backup` | Hard | 2026-07-11 |
| 43 | `Settings\Index` | Hard | 2026-07-11 |
| 44 | `Project\Application\Deployment\Show` | Hard | 2026-07-11 |
| 45 | `Project\Service\DatabaseBackups` | Hard | 2026-07-11 |
| 46 | `Server\Proxy\Logs` + `Server\Sentinel\Logs` | Hard | 2026-07-11 |
| 47 | `Project\Shared\Logs` | Hard | 2026-07-11 |
| 48 | `Project\Shared\ExecuteContainerCommand` | Hard | 2026-07-12 |
| 49 | `Project\Service\Index` (general/advanced tabs) | Hard | 2026-07-12 |
| 50 | `Project\Shared\Metrics` | Hard | 2026-07-12 |
| 51 | `Project\Resource\Create` (wizard shell) | Hard | 2026-07-12 |
| 52 | `PublicGitRepository`, `GithubPrivateRepository`, `GithubPrivateRepositoryDeployKey` | Hard | 2026-07-13 |
| 53 | Sources page + "New GitHub App" modal | Hard | 2026-07-13 |
| 54 | Database Configuration router + 6 shared tabs | Hard | 2026-07-13 |
| 55 | Service Configuration router + 4 shared tabs | Hard | 2026-07-13 |
| 56 | Environment Variables (databases + services) | Hard | 2026-07-13 |
| 57 | Persistent Storage (databases + services) | Hard | 2026-07-13 |
| 58 | Scheduled Tasks (services) | Hard | 2026-07-13 |
| 59 | Service General tab | Hard | 2026-07-13 |
| 60 | Database Healthcheck tab | Hard | 2026-07-13 |
| 61 | Import Backup (databases + service databases) | Hard | 2026-07-13 |
| 62 | 8 per-engine database General tabs | Hard | 2026-07-14 |
| 63 | Application Tags/Danger Zone/Resource Limits/Resource Operations/Scheduled Tasks | Hard | 2026-07-14 |
| 64 | `Project\Application\Heading` (action bar) | Hard | 2026-07-14 |
| 65 | Application Environment Variables + Persistent Storage | Hard | 2026-07-14 |
| 66 | Application Webhooks | Hard | 2026-07-14 |
| 67 | Application Swarm tab | Hard | 2026-07-14 |
| 68 | Application Rollback tab | Hard | 2026-07-14 |
| 69 | Application General tab | Hard | 2026-07-14 |
| 70 | Application Preview Deployments tab | Hard | 2026-07-14 |
| 71 | Application Advanced tab | Hard | 2026-07-14 |
| 72 | Application Healthcheck tab | Hard | 2026-07-14 |
| 73 | Application Servers tab | Hard | 2026-07-14 |
| 74 | Application Git Source tab | Hard | 2026-07-14 |
| 75 | Theme switcher + What's New changelog (`AppLayout`) | Hard | 2026-07-14 |
| 76 | `GlobalSearch` command palette | Hard | 2026-07-14 |
| 77 | `Boarding\Index` (onboarding wizard) | Hard | 2026-07-14 |
| 78 | `Server\Show` (last full-page Livewire component) | Hard | 2026-07-14 |
| 79 | `auth/verify-email.blade.php` (last legacy chrome page — migration complete) | Infra/chrome | 2026-07-14 |

**Frontend test coverage by stage** — component tests (Vitest + Testing Library, see "Frontend component testing" further down) target the surface once it's stable, not while it's still moving:

| Stage | Work in that stage | Why component tests waited |
|---|---|---|
| Phases 1–79 (the migration itself) | 84 React pages converted, phase by phase | Verification loop (Pint, PHPStan, full Pest suite, `yarn build`) held fixed across all 79 phases for direct comparability — not changed mid-flight |
| Phases 80–81 (post-migration stabilization) | Orphaned Livewire classes pruned, vestigial `wire:*` markup stripped, `livewire/livewire` + Alpine.js dependencies removed | Component surface still being actively deleted/restructured — tests written here would need rewriting once the prune landed |
| PHPStan baseline reduction (65 phases) | Larastan baseline 1,306 → 60 | PHP-only scope (`phpstan.neon.dist` doesn't analyze `resources/js/`) — a separate, sequential initiative, not a dependency of the JS-testing decision |
| Smoke testing (current) | Manual QA surfacing real component defects (toast notifications non-functional, Alpine-remnant password toggle/2FA/tooltip) | Concrete, evidence-based trigger — component tests now target the specific surface already proven regression-prone, not written speculatively ahead of it |

### PHPStan baseline reductions

This table has the shape of the whole 65-phase effort; the milestones table right after it pulls out the entries that actually found something (a real bug, or a structural fix big enough to collapse dozens of duplicate baseline entries at once) rather than routine typing.

| Phase | Commit | Baseline (before → after) | Focus | Highlight |
|---|---|---|---|---|
| 1 | `e023b89ca` | 2996 → 2833 | Baseline regen after early return-type fixes | No new typing — pure regen |
| 2 | `09a4c1c06` | 2833 → 2808 | `TerminalController` / `ApplicationDeploymentController` | 25 stale CI-breaking ignores removed; 9 real findings fixed |
| 3 | `2ce2a2c37` | — | Stale baseline entry cleanup | Pure ignore-list hygiene |
| 4 | `1dc03ede7` | 1572 → 1572 | `phpstan.neon.dist` analysis paths | CI-only fix (empty-dir path missing on fresh checkout) |
| 5 | `b4a8e1d6c` | 1570 → 1606 (+36) | Extract `ManagesApiResourceStorages` trait | Accepted tradeoff — loose `Model $resource` typing |
| 6 | `1e38eae09` | 1609 → 1323 | 6 shared traits (`HasStandaloneDatabaseCommon`, `HasSafeStringAttribute`, etc.) | **Largest single-phase drop of the whole initiative** |
| 7 | `6fd50e0e3` | 1323 → 1257 | `HasMetrics` / `EnvironmentVariableAnalyzer` array shapes | Typing only |
| 8 | `1a22da5b8` | 1257 → 1244 | camelCase `Attribute`-accessor access (13 call sites) | Hidden magic-property gap fixed |
| 9 | `7d80d50d0` | 1244 → 1062 | `Application` / `Server` / `PrivateKey` + 6 traits | **Real bug fixed** (missing source-provider guard); largest drop by count |
| 10 | `aa1babb3b` | 1062 → 1029 | `ManagesApiResourceEnvs`/`Storages` via `data_get()` | Typing only |
| 11 | `510eb46e8` | 1029 → 957 | `ManagesResourceEnvironmentVariables`/`Tags` | **Real bug fixed** — fatal error replaced with graceful fallback |
| 12 | `bfb53b59f` | 957 → 929 | `LocalFileVolume` | Refactor — extracted shared `resolveStorageContext()` |
| 13 | `07e19e27a` | 929 → 919 | `LocalPersistentVolume` | **Real fatal bug** — protected-property access crashing every call |
| 14 | `723d9e553` | 919 → 901 | `Application.php` defensive narrowing | Dead code removed (unnecessary null-coalescing) |
| 15 | `da4e3c2b6` | 901 → 895 | `GeneratesGitCommands` | Dead code removed |
| 16 | `5e51295a2` | 895 → 880 | `SwarmDocker` relations | Typing only |
| 17 | `a8dd8dc67` | 880 → 866 | `GetContainersStatus` / database-engine actions | Dead branch removed; characterization test added first |
| 18 | `38456aad7` | 866 → 866 | `GetContainersStatusTest` CI flake | CI-only fix (faked a broadcast event) |
| 19 | `c69978104` | 866 → 866 | `Application.php` `Attribute` generics | Documentation-completeness pass, no net change |
| 20 | `3d9051023` | 866 → 833 | `Application.php` generics regressions | Pruned 19 now-stale ignores |
| 21 | `537ec51f1` | 833 → 852 (+19) | Fix Phase 20's vendor-drift false negative | Always `composer install` before trusting a local run |
| 22 | `4c901d19d` | 852 → 852 | `EmailChannel` / `StartDatabaseProxy` | **Real bug fixed** — null `strtolower()` crash, TDD'd |
| 23 | `75212a7e6` | 852 → 804 | `HasStandaloneDatabaseCommon` accessors | Shared-trait leverage (×8 database engines) |
| 24 | `b7e330f23` | 804 → 788 | `Server.php` batch | Code smell fixed (`static::` → `self::`) |
| 25 | `7c5263640` | 788 → 764 | `Service.php` batch | **2 real bugs** — non-deterministic hash, fatal `$SERVICE_*` resolver |
| 26 | `47bd8b8f6` | 764 → 737 | `ServiceApplication`/`ServiceDatabase` | **Real bug fixed** — missing boolean casts, `strict_types` risk |
| 27 | `3c1e3edfe` | 737 → 710 | `EnvironmentVariable`/`SharedEnvironmentVariable` | Caught its own wrong `Attribute` annotation before baselining |
| 28 | `5f5a668fd` | 710 → 697 | `Environment.php` relations | Typing only |
| 29 | `13d0c6d04` | 697 → 709 (+12) | `Project.php` relations | Deliberate increase — accepted interface gap |
| 30 | `eb3c1cafa` | 709 → 680 | `Team.php` + `HasNotificationSettings` | **Real bug fixed** — `Collection::merge()` type mismatch |
| 31 | `d80aea499` | 680 → 665 | `User.php` | Code smell fixed (`static::` → `self::`) |
| 32 | `1dfc108a9` | 665 → 650 | `StandaloneDocker` | Worked around a `Builder` generic-invariance wall |
| 33 | `b6443e257` | 650 → 582 | 8 `Standalone*` engines + shared trait | **2 real bugs** (dead SSL branch, accessor visibility) |
| 33b | `6c8a74211` | 582 → 570 | `HasStandaloneDatabaseCommon`'s `once()` | CI-only inference bug Phase 33 had papered over |
| 34 | `84899e65b` | 570 → 559 | `S3Storage` | Dead code removed (verified-NOT-NULL guards) |
| 35 | `051f9eb25` | 559 → 548 | `ApplicationDeploymentQueue` | PHPStan contradicted its own `dumpType` — trusted the dumper |
| 36 | `0e084406a` | 548 → 541 | `ApplicationPreview` | 2 findings — dead code + a `Carbon`-into-`string` gap |
| 37 | `ea075a51d` | 541 → 537 | `Service.php` remaining methods | 3 minor findings (dead code ×2, explicit call) |
| 38 | `8ddc3604b` | 537 → 531 | `CloudProviderToken` | Clean pass, no bugs, nothing baselined |
| 39 | `78a5a191d` | 531 → 519 | `CalculatesExcludedStatus` trait | Shared-trait leverage (×4 classes, 12 collapsed) |
| 40 | `8d28533b0` | 519 → 515 | `StorageController.php` | `MorphTo`/`SoftDeletes` nullability documented |
| 41 | `91f88653b` | 515 → 492 | `Application.php` + 3 traits | **2 real bugs** (TDD'd) — nullability + `instanceof` narrowing |
| 42 | `6a084c314` | 492 → 484 | `Server.php` + `HasSentinel`/`HasDockerContainers` | **Real fatal-shaped bug** (TDD'd) — `services()` iterated nothing |
| 43 | `b2b547105` | 484 → 461 | `ManagesResourceLimits`/`Operations` (×3 controllers) | Shared-concern leverage, real union typing, no bugs |
| 44 | `ddec30b22` | 461 → 446 | `ExecuteRemoteCommand`/`SshRetryable` traits | Dead code — an unreachable `instanceof` safety check |
| 45 | `2298b4243` | 446 → 420 | `StreamsContainerLogs`/`ManagesServiceLifecycle` (×5) | **Real bug fixed** (TDD'd) — property set on a `Collection`, never persisted |
| 46 | `5577db04e` | 420 → 390 | `ManagesScheduledDatabaseBackups` (×3 controllers) | Union-typed a `MorphTo`'s real target, no bugs found |
| 47 | `2bc5d889f` | 390 → 386 | `SettingsScheduledJobsController.php` | Dead code (tautological check, redundant `&&` chain) |
| 48 | `212e1e0a8` | 386 → 368 | `ManagesDatabaseImport` (×2 controllers) | Union-typed `$resource`; same gap as Phase 40 |
| 49 | `6c5a437e8` | 368 → 361 | `ManagesDatabaseGeneralForm` + controller | 10 methods typed; a real `instanceof` narrowing gap found |
| 50 | `3cfe828de` | 361 → 225 | `StandaloneDatabaseInstance`: interface → abstract class | **Structural fix, largest drop since Phase 6** — fixed for all 27 consumers |
| 51 | `619cc8d2f` | 225 → 195 | `ManagesResourceStorages`/`Danger` (×3 controllers) | Real union typing, same `instanceof` pattern as Phase 49 |
| 52 | `d082d1ae0` | 195 → 184 | `SslCertificate.php` + 6 `*NotificationSettings` | **Real dead code removed** — 3 of 4 relation methods, never called |
| 53 | `8c363b33f` | 184 → 169 | 5 small unrelated models | Matched each fix to an already-typed sibling's convention |
| 54 | `8be5ecd3c` | 169 → 152 | `@use HasFactory<...Factory>` generics (17 models) | Verified on `Application.php` first; 5 models skipped (no real factory) |
| 55 | `4b015bf96` | 152 → 129 | `HasMetrics` trait (×10) + `parseEnvFormatToArray()` | Hardened against a malformed Sentinel API response |
| 56 | `103d4e01c` | 129 → 121 | `ManagesApiResourceStorages` + 3 more files | **Real, TDD'd production bug** — would've force-disabled every team's servers |
| 57 | `131b1a361` | 121 → 105 | `ClearsGlobalSearchCache` (×11) + a controller | Always-true check collapsed 11+ duplicate entries |
| 58 | `cb0c7769f` | 105 → 96 | 8 `Start*` actions' docblocks + a controller | Wrong `@param` shape, copy-pasted across all 8 engines |
| 59 | `3894266f4` | 96 → 97 (+1) | `TagsController.php` + `DatabaseBackupJob.php` | Deliberate increase — `instanceof` fix revealed 5 findings, correctly baselined |
| 60 | `1d9233c7a` | 97 → 86 | 6 files (cleanup command, factory, etc.) | **2 real bugs** — a check that could never fire; a broken factory |
| 61 | `e47e79162` | 86 → 85 | `HasMetrics.php` | A private wrapper blocked narrowing — replaced with direct `instanceof` |
| 62 | `e73fe6089` | 85 → 75 | 8 small models + 2 controllers (mixed batch) | 10 small fixes; rest confirmed an accepted false-positive family |
| 63 | `00420909d` | 75 → 73 | `ManagesDatabaseGeneralForm.php` | Picked up one of Phase 55's deferred `collect()` cases |
| 64 | `d8c4ccb25` | 73 → 65 | `ApplicationPreview.php` | **Real, TDD'd production bug** — unguarded nullable crashed `json_decode()` |
| 65 | `7d14d46f8` | 65 → 60 | 5 models (+ 5 new factories) | Last fixable category closed — 5 real, tested factories built |

### PHPStan baseline milestones

Every phase touched the baseline, but only these actually found something — a real, previously-invisible bug, or a structural fix big enough to collapse dozens of duplicate entries at once. Everything else was routine typing; this table is the "what actually mattered" subset of the full phase table above.

| Phase | Commit | Milestone |
|---|---|---|
| 6 | `ac32869a0` | Largest single-phase baseline drop of the whole initiative (1609 → 1323, −286): typing 6 widely-shared traits (`HasStandaloneDatabaseCommon`, `HasSafeStringAttribute` ×18 consumers, etc.) collapsed dozens of duplicate per-consumer entries into one. |
| 9 | `8a4b0d1d2` | Largest drop by absolute count (1244 → 1062, −182). Real bug fixed: a missing guard before calling `githubApi()` on an unsupported source-provider type now fails gracefully instead of type-erroring. |
| 11 | `a0d9e5710` | Real bug fixed: `envStore()`/`envUpdate()` now return a graceful error/404 when a resource has no `environment_variables()` relation, instead of a fatal method-call-on-generic-`Model` error PHPStan's typing had been masking. |
| 13 | `0a9d13249` | Real fatal bug: `customizeName()` and the `mountPath()`/`hostPath()` setters read `Stringable`'s **protected** `$value` property directly from an unrelated class — every call would have thrown a fatal `Error`. Fixed with the public `->toString()`. |
| 22 | — | Real bug fixed, TDD'd: `EmailChannel::send()` crashed with `strtolower(): ... null given` on a nullable `smtp_encryption` column — every sibling `smtp_*` field already null-coalesced, this one didn't. |
| 25 | `7c5263640` | 2 real bugs, both TDD'd: `isConfigurationChanged()` hashed full model instances instead of `pluck('value')`, making the "sorted for determinism" config hash actually order-dependent; and `ServiceExtraFieldsResolver`'s `$SERVICE_*` closures never captured `$service` and read a protected `Stringable::$value` — would fatal the instant a field value started with `$SERVICE_`. |
| 26 | `47bd8b8f6` | Real bug fixed, TDD'd: neither `ServiceApplication` nor `ServiceDatabase` cast `is_log_drain_enabled` to boolean — a fresh-from-DB instance returned SQLite's raw `int(1)`/`int(0)`, and declaring the accessor `: bool` would have thrown under `strict_types=1`. |
| 30 | `eb3c1cafa` | Real bug fixed: `Team::sources()`'s `Collection<GithubApp>::merge(Collection<GitlabApp>)` used the wrong `Collection` class (Eloquent's model-invariant one instead of `Illuminate\Support\Collection`) — found twice, independently, once in `sources()` and once in a `deleting` event closure. |
| 33 | `b6443e257` | 2 real bugs: a permanently-dead SSL-cert-param branch on 3 database engines that never actually had an `ssl_mode` column; and `databaseType()` was `public` instead of `protected`, so Larastan couldn't recognize it as a valid computed Attribute property despite working fine at runtime. |
| 41 | `91f88653b` | 2 real bugs, TDD'd: `loadComposeFile()`'s stale-domain pruning always took its truthy branch (a `Collection` is always truthy in PHP) so `docker_compose_domains` could get permanently stuck at `"[]"` instead of reverting to `null`; and 4 call sites string-compared `getMorphClass()` instead of using `instanceof`, blocking type narrowing. |
| 42 | `6a084c314` | Real fatal-shaped bug, TDD'd: `Server::status()` called the `services()` relation **method** directly in a `foreach` instead of `->get()`-ing it first — a relation builder isn't `Traversable`, so the loop body silently never executed, and a downed server's Service-based resources never got marked `'exited'`. |
| 45 | `2298b4243` | Real bug fixed, TDD'd: `forceDeployService()` tried to mark stuck activities as errored via `$activity->properties->status = ...` — `properties` is a `Collection`-cast attribute, so this set a dynamic property on the Collection object itself and never persisted. Fixed by reassigning the attribute via `->put()`. |
| 50 | `3cfe828de` | Structural fix, largest drop since Phase 6 (361 → 225, −136): converted `App\Contracts\StandaloneDatabaseInstance` from a plain interface to an abstract class, since Larastan never resolves PHPDoc through an interface. Migrated all 8 database engines and 27 consuming files; caught a real runtime `TypeError` in a test fixture still typed against the deleted interface (63 failing tests surfaced it immediately). |
| 52 | `d082d1ae0` | Real dead code removed: `SslCertificate`'s `application()`/`service()`/`database()` relation methods were unused everywhere — every real caller reads the raw `resource_type`/`resource_id` columns directly — so they were deleted rather than typed. |
| 56 | `103d4e01c` | The most severe bug found this session, TDD'd: `ServerLimitCheckJob::handle()` read `$this->team->limits`, a property that has never existed on `Team` (the real column is `custom_server_limit`) — Eloquent's magic getter silently returned `null`, and PHP's `-` operator treated it as `0`, so **every team with at least one server would have had all of them force-disabled** on every run. The job is never actually dispatched anywhere in the current codebase (confirmed via grep), so it never fired in production — but the fix has lasting value if it's ever wired up. |
| 60 | `1d9233c7a` | 2 real bugs: `CleanupStuckedResources.php` called the `destination()` relation **method** instead of the property on 4 of 7 engine blocks, so an orphaned-destination cleanup branch could never fire for those engines (masked by an adjacent, differently-worded check catching the same case); and `StandaloneRedisFactory` still wrote a `redis_password` column a migration had dropped over a year earlier — `StandaloneRedis::factory()->create()` had been silently broken the whole time, never exercised by any existing test. |
| 64 | `d8c4ccb25` | Real, TDD'd production bug: `ApplicationPreview::generate_preview_fqdn_compose()` passed a nullable `docker_compose_domains` column straight into `json_decode()` with no null guard — under this codebase's `strict_types=1`, the very first preview-fqdn generation for any fresh docker-compose application would crash before generating a single domain, across all 6 real call sites. |

**What's left in the 60-entry baseline (2026-07-19 audit, updated after Phase 65):** all 60 are confirmed PHPStan/Larastan false positives, individually verified, not assumed — nothing neglected.

| Category | Entries | Why it's a false positive |
|---|---|---|
| Trait per-class-context artifact | 27 (`instanceof.alwaysFalse` ×12, `catch.neverThrown` ×11, `booleanOr.alwaysFalse` ×3, +1) | `ClearsGlobalSearchCache.php` is shared by 13 models — PHPStan analyzes the trait once per consuming class with `$this` narrowed to that concrete type, so a check relevant to a *different* consumer gets flagged "always false" even though the trait needs that branch for its other real consumers. |
| `Illuminate\Support\Collection`'s non-covariant `TValue` template | 11 (`return.type` ×10, `argument.type` ×1) | `dumpType()` (Phase 62) confirmed the declared and actual shapes print as structurally identical, yet PHPStan still rejects it — a documented upstream limitation, not fixable without loosening real types. Mostly `SettingsScheduledJobsController.php` + `TerminalController::getAllActiveContainers()`. |
| Engine-specific dynamic column access | 11 (`property.notFound`) | `StandaloneDatabaseInstance::$mariadb_database`/`$mysql_database`/`$postgres_db`/`$enable_ssl`/`$ssl_mode` etc. — the base/abstract type doesn't declare every engine's specific columns, but the concrete runtime subclass always does. Established Phase 50/52. |
| Magic-property "remembering" artifact | 3 (`identical.alwaysFalse`) | `ManagesApiResourceStorages.php:410` — `dumpType()` confirmed PHPStan's own internal type-flow computes `*NEVER*` for `$request->type` there, from an interaction between an earlier ternary and a later `$request->has()` call. Real PHP behavior is unaffected. |
| In-memory scratch-credential pattern | 3 (`property.notFound`) | `ServiceDatabase::$postgres_user`/`$mysql_root_password`/`$mariadb_root_password` in `DatabaseBackupJob.php`, verified intentional (no `->save()` calls, read back within the same job run only). Phase 59. |
| Context-narrow polymorphic guard | 1 (`function.alreadyNarrowedType`) | `ApplicationDeploymentJob.php`'s `method_exists($this, 'addRetryLogEntry')` is true in *this* context, but `SshRetryable` has a second real consumer (`SshRetryHandler`) where the method genuinely doesn't exist. Phase 57. |

**Livewire → React/Inertia migration** (see `docs/livewire-to-react-migration.md` for the full ledger) — **84 of 84 full-page Livewire components converted, migration complete as of Phase 79 (2026-07-14).** Easy/Medium buckets 100% (25/25); Hard bucket via the 3 big Configuration routers (Service/Database/Application) plus `Server\Show`.

| Date | Milestone |
|---|---|
| 2026-07-10 | Found and fixed a real regression via a CI failure: `Team\Storage\Show.php` (a dead Livewire class, zero consumers) hardcoded a Blade view path deleted in Phase 30 — deleted outright. |
| 2026-07-11 | Removed leftover upstream `SECURITY.md`/`CODE_OF_CONDUCT.md` (pointed at coollabs' own addresses, not this fork). |
| 2026-07-11 | Converting `Project\CloneMe` found 3 real bugs: 12 `@property bool` columns missing from `$casts`, plus a `Stringable` passed to `base64_encode()` under `strict_types=1`. |
| 2026-07-12 | `Server\Proxy\DynamicConfigurations` + 2 more components converted — first page to reuse the `useTeamChannel` Echo hook. |
| 2026-07-12 | `Source\Github\Change` converted (435 PHP + 422 Blade lines — GitHub App manifest-flow registration, JWT generation). |
| 2026-07-12 | 4 `SharedVariables` "Show" pages consolidated into one DRY controller. Found a real Laravel routing bug: a typed scalar route param binds positionally (not by name) when a route has more URI segments than the method declares — fixed by reading `$request->route(...)` directly. |
| 2026-07-12 | Fixed a real bug in `Project\Resource\Index`: `Service::serverStatus()` had no null-safety on `$this->server`, unlike `Application::serverStatus()`'s already-correct guard. |
| 2026-07-12 | `Project\Database\Backup\Index` converted. Found a real fatal bug: `StartDatabase`/`RestartDatabase` return a plain string (not an `Activity`) when the server isn't functional, but the code unconditionally did `$activity->id` — now guarded with `instanceof Activity`. |
| 2026-07-12 | `Project\Database\Backup\Execution` converted. Found a real bug: `BackupExecutions` listened on `team.{$userId}` instead of `team.{$teamId}` — the broadcast likely never fired in production; fixed to use the existing team-scoped hook. |
| 2026-07-12 | `Settings\Backup` converted. PHPStan caught a real regression before any test ran: an early-return in the new controller skipped an unconditional side effect (auto-disabling an unhealthy server's backup schedule) the original Livewire `mount()` always ran. |
| 2026-07-12 | `Settings\Index` converted; removed an already-unreachable manual timezone-validation check (Laravel's built-in rule already covers it). |
| 2026-07-12 | `Project\Application\Deployment\Show` converted. Found 4 more boolean-cast bugs on `ApplicationDeploymentQueue` (`rollback`/`force_rebuild`/`restart_only`/`only_this_server` missing from `$casts`). |
| 2026-07-12 | `Project\Service\DatabaseBackups` converted. Found a real bug: pagination replaced the entire query string, silently dropping `selectedBackupId`; also caught a copy-paste enum mistake (`ApplicationDeploymentStatus` instead of `ProcessStatus`) before any test ran. |
| 2026-07-12 | `Server\Proxy\Logs`/`Server\Sentinel\Logs` converted into a shared `ContainerLogs.jsx` + `StreamsContainerLogs` trait. Found the extraction had dropped `downloadAllLogs()`'s `isFunctional()` guard — restored, with a 404 instead of a silent empty download. |
| 2026-07-12 | `Project\Shared\Logs` converted (3rd and final `GetLogs` consumer). Found a test-fixture gap: `Service::server_id` wasn't set, causing a null `$service->server`. |
| 2026-07-12 | `Project\Shared\ExecuteContainerCommand` converted, closing out every `Server\Navbar`-dependent page except `Server\Show` itself. Surfaced a Swarm container-listing quirk (a synthetic container with no `State` key, so its own liveness check could never pass) — fixed later once a test-isolation blocker was resolved. |
| 2026-07-12 | `Project\Service\Index` converted. Found 2 real bugs: `HandleInertiaRequests::share()` never actually exposed `domainConflicts`/`showDomainConflictModal` as Inertia props (an earlier-built modal had never been reachable in production), and `checkDomainUsage()` crashed on a null `fqdn` in 2 of its 4 conflict-detection loops. |
| 2026-07-12 | `Project\Shared\Metrics` converted. Found a real suite-wide test-isolation bug: a global `beforeEach()` meant to flush static caches before every test was silently shadowed by most test files' own local `beforeEach()` — fixed by moving the flush into `Tests\TestCase::setUp()`, which can't be shadowed the same way. |
| 2026-07-12 | Dev environment moved off a Windows-path Docker bind mount into WSL2's native filesystem — `yarn build` went from 3+ hours to ~2 seconds, the full Pest suite from ~150–170s to ~31s (root cause: every file crossing Docker Desktop's WSL2 9P bridge, rescanned by Windows Defender on each crossing). |
| 2026-07-12 | `Project\Resource\Create`'s wizard shell + 3 nested creation flows converted. Found 6 real bugs: 5 `Stringable`-vs-`string` mismatches under `strict_types=1`, plus an always-truthy check on a `Stringable` that silently never filtered blank env values. |
| 2026-07-12 | Repo hygiene: removed 17 leftover `phpstan-*.txt` dumps, 4 `rector-fix-*.php` scratch configs, `jean.json`, the upstream-only `other/` folder, `scripts/cloud_upgrade.sh`, `conductor-setup.sh`, and `fix-property-not-found.sh` (all committed-by-accident scratch/upstream artifacts). `backlog/` was re-synced against the codebase, then removed entirely on 2026-07-17 once it turned out to be pre-fork boilerplate, not real tracked work. |
| 2026-07-12 | Found a real, user-facing gap in `AppLayout.jsx`: the React sidebar had no way to log out at all (Logout only ever lived in the still-Livewire settings dropdown) — added a working `POST /logout` button, plus fixed Laravel Debugbar's dev-only toolbar visually covering it. |
| 2026-07-13 to 2026-07-14 | Phases 52–74: the bulk of the remaining Hard-bucket conversions (3 GitHub-dependent creation flows, the Sources page, and all three big Configuration routers — Service, Database, Application — retired tab by tab). Full per-phase detail lives in `docs/livewire-to-react-migration.md`, not duplicated here. |
| 2026-07-14 (Phase 62) | `Database\Configuration` router fully retired (all 12 tabs, 21 files deleted). 3 real pre-existing bugs fixed, plus a test-environment gap (`RAY_ENABLED` unset in `.env.testing`, causing hangs). |
| 2026-07-14 (Phases 75–76) | `SettingsDropdown` found fully orphaned on both stacks and deleted rather than ported. `GlobalSearch` converted — found the original's PHP search logic was mostly unreachable (the `<input>` only ever used Alpine's `x-model`, never `wire:model`); the real behavior was Alpine's own client-side filtering, ported as-is. Also fixed a live `route()` `ReferenceError` (no Ziggy in this codebase) and a blank-page bug on server creation (`Inertia::location()` needed for a Livewire-page redirect handoff). |
| 2026-07-14 (Phase 74) | `Application\Configuration` router fully retired (16 tabs, 820 lines deleted in one phase). 6 real bugs fixed across Phases 63–74, including a `next_queuable()` `strict_types=1` crash and an uninitialized `Collection` property. |
| 2026-07-14 (Phase 77) | `Boarding\Index` (onboarding wizard) converted — last full-page Livewire component besides `Server\Show`. Two overlapping SSH-validation engines collapsed into one orchestrator (`BoardingController::validateServer()`). |
| 2026-07-14 (Phase 78) | `Server\Show` converted — **84 of 84 full-page Livewire components now React.** Found a real, silent bug in `useTeamChannel.js` (a broadcast's custom `broadcastAs()` name didn't match the hook's hardcoded assumption, so the listener would never have fired); also replicated a server-side `#[Locked]`-checkbox guard the naive port would have otherwise dropped. |
| 2026-07-14 (Phase 79) | `auth/verify-email.blade.php` converted — **the Livewire→React migration is complete.** Closed out `layouts/app.blade.php`, `navbar.blade.php`, and 9 Livewire classes in one pass. Permanent, user-confirmed consequence: Hetzner Cloud server creation became unreachable from the UI (rebuilt as a new React flow the next day, 2026-07-15). |
| 2026-07-14 (Phase 80) | Full post-completion audit (prompted by the user raising the bar to "pristine, zero known issues") found 8+ more dead Livewire classes Phase 79's own sweep had missed — all deleted. `app/Livewire/` and `resources/views/livewire/` are now both empty. |
| 2026-07-14 (Phase 81) | `livewire/livewire` removed from Composer entirely; `config/livewire.php` deleted; 50 of 61 dead Blade components + 12 dead `View\Components` classes deleted via a purpose-built reachability script. Found 2 more real, previously-silent bugs: `ClearsGlobalSearchCache` calling a deleted method (silently swallowed by a broad `catch`), and a dead import in `Server.php`. |
| 2026-07-14 | A dozen post-Phase-78 cleanup passes, each re-verifying before deleting and catching real bugs along the way: `Application::health_check_enabled`/`custom_healthcheck_found` missing from `$casts` (silently meant healthcheck was always treated as enabled); `parseContainerLabels()` had 3 bugs, not 1 (2 crashes plus a silent no-op label transformation); `EnvironmentVariable::set_environment_variables()`'s null/empty guard had a De Morgan error (`&&` should've been `\|\|`), letting a `null` value crash an unguarded `trim()`; `parsers.php` pushed a PostgreSQL-only regex operator into a query the SQLite test DB can't run; 4 sibling `Project\Database\*Backup*` Livewire classes turned out to have zero consumers and were deleted together; `Team::serverLimit()`/`serverLimitReached()`/`limits()` removed entirely (dead per this fork's unlimited-self-hosted design) along with every call site. |
| 2026-07-15 | Chrome DevTools' "form field missing id/name" gap (388 elements across 80 files) fully fixed, file by file to avoid duplicate-ID bugs in list-rendered fields. |
| 2026-07-15 | Hetzner Cloud server creation rebuilt from scratch as a new React flow (`CreateHetznerServer` action, new controller, `Hetzner.jsx` wizard, 10 new Pest tests) after Phase 79 removed the last reachable path to the old Livewire version. |
| 2026-07-15 | `/terminal` WebSocket reconnect-loop root cause found and fixed via a real headless-browser session: `terminal-server.js`'s `handleCommand()` indexed `command[0]` (a string) instead of using the whole string, silently taking just its first character — every terminal connection had failed with "Invalid SSH command" 100% of the time since the file's first commit, predating this migration entirely. |
| 2026-07-15 | Flipped `'livewire' => false` in `config/debugbar.php` now that the migration is complete. |

### Backend bug fixes (chronological log)

Not the same section as "Laravel backend improvements" further down (that's the dedicated, user-requested audit checklist) — this is a dated log of real bugs found and fixed opportunistically, mostly from the user pasting live Horizon/error logs.

| Date | Bug found & fixed |
|---|---|
| 2026-07-15 | Broad `catch (\Throwable)` swallowing errors silently — 219 occurrences found via an automated brace-matched scan, 179 unlogged; every one fixed to log first (or left alone if already logged/rethrown). **Correction, 2026-07-19**: re-verification found 8 blocks in `DatabaseBackupJob.php` the original scan had missed entirely (2 were genuinely silent, fixed) and one false-positive flag (`report()` already logs equivalently). Final: 233 `catch (Throwable)` blocks app-wide, zero silently unlogged. |
| 2026-07-15 | God objects (models): `Application`/`Server`/`Service` split into `HasXxx` traits (`Server` 1,686→918 lines, `Application` 2,762→1,763, `Service` 1,743→619 via a new `ServiceExtraFieldsResolver` class). Shared logic between `Application`/`Service` consolidated into `HasResourceStatus`/`HasResourceCleanup`/`HasResourceLinks`. |
| 2026-07-15 | God objects (API controllers): `Api/ApplicationsController`/`DatabasesController`/`ServicesController` had zero test coverage for 70 routes — wrote 176 characterization tests first, then extracted 3 shared traits. Found 3 real bugs as a side effect: a missing boolean cast, a `TypeError` on every real API request (`team_id` treated as int, actually a string column), and `validateIncomingRequest()` falling off the end without a `return null;` — fatally erroring on every valid write request through all 10 controllers that call it, the most severe bug found this session. |
| 2026-07-15 | Terminal WebSocket now only opens on explicit user request: removed a redundant unconditional connect-on-mount, and guarded a `cleanup()` call that warned on every page unmount even with no socket ever created. |
| 2026-07-15 | `EventServiceProvider`'s automatic event discovery caused a recurring `mb_split()` crash (~2×/minute, 197 times logged) in the worker/CLI context — fixed by explicitly registering listeners instead of relying on discovery. |
| 2026-07-17 | Two real Horizon-worker crashes fixed from a live log paste: `ServerCheckJob` called nonexistent methods on a `SchemalessAttributes` wrapper (crashed on every proxy-status check cycle); `CheckAndStartSentinelJob` passed a nullable string into `json_decode()` under `strict_types=1` (crashed on any server that had never run Sentinel). A third reported error (`mb_split()` on `artisan list`) was confirmed to be an unrelated host-PHP-CLI quirk, not a regression. |
| 2026-07-19 | `CleanupStaleMultiplexedConnections` crashed on a real TOCTOU race — `Storage::get()` returned `null` when a mux socket file was torn down mid-loop, passed into `substr()` under `strict_types=1`, aborting the entire cleanup job (all 3 steps after it silently never ran). Fixed with a null check + the same cleanup path its sibling checks already used. |
| 2026-07-21 | Found via the `/admin` impersonation smoke test: a real 500 (`RouteNotFoundException: Route [verification.notice] not defined`), not a test artifact. Root cause, two parts: (1) three user-creation paths never set `email_verified_at` — the first/root user (`CreateNewUser`), team-invited users (`TeamController::sendInvitation`), and OAuth signups (`OauthController::callback`); (2) in non-cloud mode, `DecideWhatToDoWithUser`'s own unverified-email redirect only runs when `isCloud()`, so unverified users fell straight through to Laravel's stock `verified` middleware, which hardcodes a redirect to `route('verification.notice')` — a name this app never registered (it used `verify.email` instead, predating that Laravel convention). Affected the very first (root) user on any genuinely fresh self-hosted install, any team-invited member's first login, and any OAuth signup's first login — the seeders happened to mask this by setting `email_verified_at` directly. Fixed by renaming the route to `verification.notice` (a *second* route registered as an "alias" for the same URI doesn't work — Laravel's `RouteCollection` keys `allRoutes` by method+URI, so it silently evicts the first from the name lookup) and auto-verifying new users in non-cloud mode (OAuth users always, since the provider already confirmed the email). 6 new tests (`EmailVerificationOnUserCreationTest.php`), TDD-proved via `git stash`/revert (4 of 6 failed with the exact real symptoms, restored clean). See issue #37. |
| 2026-07-21 | Found via the `/tags` redeploy smoke test: a raw Postgres 500 (`zero-length delimited identifier`), traced to dev-data drift, not a code bug — `storefront-web`'s `destination_id` pointed at a deleted `StandaloneDocker` row and `destination_type` had drifted to `NULL`. Worth knowing regardless: `applications` uses `nullableMorphs('destination')`, and Eloquent's `morphTo()` crashes on a null type instead of resolving to `null` (its "empty morph" placeholder builds a self-referential query with an empty owner key — Postgres rejects the empty identifier outright). Restored the real seeded destination. See issue #38. |
| 2026-07-21 | Challenged directly ("how do we know this wasn't production-reachable?") on the #38 finding above — the original investigation checked several likely write paths but wasn't exhaustive, and that push-back surfaced a real, separate, currently-reachable bug: `StandaloneDocker::attachedTo()` (the guard `DestinationController::destroy()` uses to block deleting a still-in-use destination) checked `applications()` and `databases()` but never `services()`, despite `Service` having its own `destination_id`/`destination_type` and `StandaloneDocker::services()` already existing, just never called from `attachedTo()`. A destination used only by a Service could be deleted through the normal UI, leaving that Service with a dangling `destination_id` — real, reachable, no manual DB manipulation required. One-line fix (added the missing check); doesn't fully explain #38's specific `destination_type = NULL` (this path leaves `destination_type` correctly set, just pointing at a gone row) — that origin is still most likely explained by manual testing-session DB manipulation, not proven. 4 new tests (`StandaloneDockerAttachedToTest.php`), the service case failed against the pre-fix code before the fix was applied. See issue #39. |
| 2026-07-22 | Rewriting `RELEASE.md` (upstream's CDN/cloud/Discord release process, none of which applies to this fork) surfaced a real functional bug, not just stale docs: `UpdateCoolify`, `CheckForUpdatesJob`, and `CheckHelperImageJob` still hit `cdn.coollabs.io`'s real, live version feed, and `UpdateCoolify::update()` would download and execute upstream's own `upgrade.sh` on a managed server via SSH if a newer version were ever detected. Inert in dev (`UpdateCoolify` short-circuits via `isDev()`), but the app's whole purpose is managing real self-hosted servers — if this ever ran for real, it would silently try to "upgrade" a server running this fork using upstream's install script, which isn't compatible with this codebase. All three `handle()` methods are now no-ops with a comment explaining why; 3 new tests (`CheckForUpdatesJobTest`, `CheckHelperImageJobTest`, `UpdateCoolifyTest`) prove no HTTP calls are made and no settings change. `CONTRIBUTING.md` got the same upstream-process-instead-of-this-fork's rewrite in the same commit. |
| 2026-07-22 | Found via the `/settings/updates` smoke test (issue #21): the page's description text still claimed its cron frequency was used to "check for new Coolify versions," which no longer happens since this session's self-update disable (issue #47) — checked live via real browser session, "Check Manually" correctly shows "No new version available" with zero HTTP calls or errors logged, and the Auto Update toggle persists correctly on Save. Fixed the stale copy in `resources/js/Pages/Settings/Updates.jsx`, plus a related stale comment/log message in `app/Jobs/PullChangelog.php` still saying "CDN" from before issue #41 repointed it at this fork's own GitHub Releases API. |
| 2026-07-22 | Dev-environment infra, not app code, but tracked here since it's a real fix, not just documentation: the Docker Desktop/WSL2 post-reboot race (`DEVELOPING_IN_CONTAINERS_WINDOWS.md`'s documented `coolify` empty-mount issue) turned out to not be fully solved by the existing `autoheal` sidecar. Tested against two real reboots — including one *after* enabling Docker Desktop's "start on sign-in" setting, which fixes a different problem (Docker not running yet) but not this one. Both times, the race hit multiple containers at once, including `coolify-autoheal` itself (exited `127`) plus `mailpit`/`minio`/`vite`/`testing-host` — none of which had any `restart:` policy set at all, a real silent gap independent of this specific bug. Fixed, two parts: (1) `restart: unless-stopped` added to the 4 unprotected services; (2) `scripts/dev-up.sh`, a host-side detect-and-fix script — run once after logging back in following a reboot. A container-native auto-fix (`mount-doctor`, `restart: always`, no bind mount of its own besides the Docker socket) was built first, on the theory that a socket-only container would be immune to the race; a Windows Task Scheduler entry was considered and deliberately rejected in favor of it, for portability. A third real reboot disproved the theory: `mount-doctor` itself came up `Exited (127)`, alongside `coolify-autoheal`/`coolify-realtime`/`coolify-testing-host`. `docker inspect` on all four showed the real mechanism is broader than "directory mount attaches empty": *file* bind mounts (`docker.sock`, soketi's individual `.js` file mounts) fail OCI container-creation outright on reboot ("mounting a directory onto a file"), rather than merely attaching empty like directory mounts do — and `mount-doctor` needs `docker.sock` to do anything, so it's a victim of the exact failure it exists to repair. Nothing running inside a container can recover from a failure that happens before that container can be created; `mount-doctor` was removed, and `dev-up.sh` (host-side, immune to this class of race) is the real fix. A follow-up attempt to synthetically test the failure path (hiding `artisan` via `docker exec`) was itself a mistake — bind mounts have no separate container-side copy, so it moved the real host file; caught immediately, fully restored, confirmed byte-identical via `git status`. See issue #45. |

### Laravel backend improvements

Not related to the Livewire→React migration — a separate, dedicated backend-quality pass, prompted by the user asking for a general Laravel-improvement audit of the repo (audited 2026-07-15). Findings are evidence-based (line/method counts, live `pg_indexes` queries, actual config values); a few initial suspicions (mass-assignment protection, `APP_DEBUG` defaulting unsafe in production, missing API rate limiting) were investigated and ruled out as non-issues. One further finding from this audit is deliberately left open, not a bug — see "Laravel backend improvements — still open" in **Still to do**.

| Item | Date | Finding & fix |
|---|---|---|
| PHPStan baseline: 1,306 → 60 suppressed errors | — | 65 phases/commits, all 60 remaining entries confirmed as analysis-tool limitations — see "PHPStan baseline reductions" above, not duplicated here. |
| `Application`/`Service` team-scoping: 3-hop relation join, not indexed | 2026-07-19 | `whereRelation('environment.project.team', ...)` on every team-scoped query, unlike `Server`/`Project`'s direct indexed `team_id`. Added an indexed `team_id` column to both tables + backfill + a `saving()` sync hook; converted 18 more `Service::whereRelation(...)` call sites too. `ServiceApplication`/`ServiceDatabase`/the 8 standalone-database models have the same pattern one level deeper — deliberately out of scope (separate future ticket). One known, deliberately-unfixed edge case: the sync hook doesn't fire if a `Project`'s `team_id` changes after creation (confirmed no real code path does this today). |
| Sanctum API tokens never expire | 2026-07-19 | Tokens already expire via an explicit per-token `$expiresAt` — the real gap was the "Expires in" dropdown defaulting to `''` (= "Never"). Changed the default to 90 days. Verified live via a headless-browser session. |
| App reachable from a machine other than the one running Docker | 2026-07-19 | 3 of 4 suspected blockers (Docker port binding, Sanctum stateful domains, CORS) ruled out with direct evidence. Real blocker: Vite's dev server hardcodes `localhost` into asset URLs via `VITE_HOST` — documented fix (`VITE_HOST=<LAN-IP>`), dev-only; production build unaffected. |
| GitHub repo-level security features | 2026-07-19 | Secret scanning + Dependabot enabled. CodeQL doesn't support PHP, so scoped to JS only + added Psalm taint analysis for PHP (clean run). `composer audit` surfaced 11 real CVE advisories across 8 dependencies, all patched same day. CodeQL itself needed 3 follow-up fixes: a concurrency/cancellation fix, a 54-minute hang traced to one specific query (excluded), and 2 real `js/insecure-randomness` findings fixed (`Math.random()` → `crypto.randomUUID()`, including a Node 16 compatibility catch). |

### Frontend component testing

| Item | Detail |
|---|---|
| Added | 2026-07-20 |
| Tooling | Vitest + React Testing Library |
| Coverage | 117 tests across 14 suites — `Toast.jsx`, `useTeamChannel.js`, `Notifications/Email.jsx`, `ServerNavbar.jsx`, `AppLayout.jsx`, `useAppearance.js`'s `applyZoom()`, `Project/Resource/Create.jsx`, `app.js` (password toggle, info tooltip, 2FA challenge), `GlobalSearchModal.jsx`, `LayoutPopups.jsx`, `WhatsNewButton.jsx`, `RollbackTab.jsx`, `ConfigurationChecker.jsx`, `DomainConflictModal.jsx` |
| Scope | jsdom-based, complements Pest's backend suite; runs independently of issue #11's still-open browser-testing gap, without resolving it |
| Full detail | Scrum issue #32 (setup, per-suite rationale, verification) |
| CI | Vitest + Prettier format-check wired into `.github/workflows/quality.yml` as their own jobs (2026-07-21) — both fully clean, no baseline debt. ESLint held out of CI until item 7's `set-state-in-effect` findings are resolved. See `docs/command.md`'s "CI parity" section and issue #34. |

## 🚧 Still to do

**Overview — 7 items open right now:**

| # | Item | Status |
|---|---|---|
| 1 | Manual SSH-touching smoke-test checklist | In progress — 11 tracked sub-issues (#5), 2 of 11 done, 1 more partially done (#21). See "Migration follow-up" and `docs/smoketest.md` |
| 2 | Zero Laravel API Resource classes | Deliberate style choice, not a bug — optional refactor, Backlog (#9) |
| 3 | Fresh-clone end-to-end boot test | Deferred on purpose, destructive — Planned (#6) |
| 4 | Pest browser-testing plugin can't run in this dev setup | Backlog (#11) — Vitest + Testing Library (added 2026-07-20) covers component logic but not real-browser/console behavior. See "Frontend component testing" |
| 5 | Low-level audit: every top-level folder/file still necessary | Not yet started — Backlog (#2) |
| 6 | `Application`/`Service` compose-file parsing might be unifiable | Not a known bug, no urgency — not yet on the board |
| 7 | ESLint's `set-state-in-effect` findings (20) need per-effect review | Prettier baseline (110 files) and 84 of 104 ESLint findings resolved 2026-07-21 — this is what's left, not mechanical, needs individual review — Planned (#33). Vitest + Prettier now run in CI (`.github/workflows/quality.yml`'s `vitest`/`prettier` jobs, added 2026-07-21, #34); ESLint follows once this item closes |

### Migration follow-up

The migration itself is complete (see **Done** above) — this heading exists for one remaining gap only.

| Item | Status |
|---|---|
| Every SSH-touching action converted so far has an untested happy-path gap (verified only via safe/validation-rejection paths in Pest) | In progress — manual QA checklist in `docs/smoketest.md`, split across 11 tracked sub-issues (issue #5), 2 of 11 done, 1 more partially done (#21) |

### Laravel backend improvements — still open

Not related to the Livewire→React migration — a separate, dedicated backend-quality pass, prompted by the user asking for a general Laravel-improvement audit of the repo (audited 2026-07-15). 4 of 5 findings are resolved — see "Laravel backend improvements" in the **Done** section above. This one is deliberately left open, not incomplete:

| Item | Status | Detail |
|---|---|---|
| Zero Laravel API Resource classes | Open | Deliberate convention (custom `serializeApiResponse()` helper instead of `JsonResource`) — optional future refactor, not a bug. |

### Fresh-clone end-to-end boot test (deferred 2026-07-13 — destructive, run when losing dev data is acceptable)

The first-boot automation (`php artisan dev --init`) is covered by feature tests and an idempotent live run, but has **not** been proven with a true from-scratch boot the way a real cloner would experience it — doing so wipes every named volume (all dev projects/servers/applications, the dev database, backups, MinIO data). Steps, when ready:

```bash
# 0. Commit/push everything first. This destroys all local dev data.
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v

# 1. Simulate a fresh cloner's .env (APP_KEY intentionally empty in the template)
cp .env.development.example .env

# 2. First boot — init runs composer install, migrate, dev --init automatically
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# 3. Wait for the app to report healthy, then verify
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
curl -s http://localhost:8000/api/health
```

Expected: `APP_KEY` now populated in `.env` (written by `key:generate` through the bind mount), login works at `http://localhost:8000` with `test@example.com` / `password`, and no `coolify-proxy` container yet (the app creates it at runtime — see `DEVELOPING_IN_CONTAINERS_WINDOWS.md` §2). Caveats: cached `coolify:dev`/`coolify-realtime:dev`/`coolify-testing-host:dev` images make this test skip the image-build path — to also prove a from-scratch **build**, remove those images first and expect the possible `NGINX_VERSION` pin issue documented in `docs/command.md`'s WSL2-migration section, step 6.

### Cleanup opportunities (lower priority, not blocking)

Everything found and fixed during this migration's various cleanup passes is recorded in the **Done** section above (each dated, most cross-referenced to a migration-doc section). This table is what's left, plus a few resolved items kept for the record.

| Item | Status | Detail |
|---|---|---|
| `Email.test.jsx` was shipping in the production bundle | Resolved 2026-07-20 | Found while adding a 6th Vitest suite: `inertia-app.jsx`'s `import.meta.glob('./Pages/**/*.jsx')` swept up any `.test.jsx` file sitting under `Pages/` — `Email.test.jsx` is the only test file that happens to live there, so it alone was bundled into the real production build (438KB/112KB gzipped, one of the largest chunks in the whole build). Fixed by excluding `**/*.test.jsx` from the glob; confirmed via `yarn build` that the test chunk is gone and the real `Email.jsx` page bundle (9.91KB) is unaffected. |
| `Application`/`Service` compose-file parsing might be unifiable | Open | Surfaced 2026-07-15 during the god-objects extraction — diverges more than it first looked; not a known bug, just worth a look if this area gets touched again. |
| `StandaloneDatabaseInstance` plain-interface PHPStan gap | Resolved (Phase 50) | Converted to an abstract class extending `BaseModel`; migrated all 8 engines + 27 consumers. Dropped the baseline by 136 in one phase — see the PHPStan milestones table above. |
| Zoom/page-width settings not applied on React pages | Resolved 2026-07-19 | Ported `checkZoom()`/`pageWidth` logic (recovered from git history pre-deletion) into a new `useAppearance.js` hook, wired into `AppLayout.jsx`. Verified live via a headless-browser session (computed `font-size` and `<main>` class both change correctly). |
| Toast notifications non-functional across the entire React app | Resolved 2026-07-20 | `window.toast()` was only ever defined in an Alpine-based Blade component never included on Inertia pages — every call site had been silently no-op'ing since the migration completed. New `Components/Toast.jsx` React port, mounted in `AppLayout.jsx`. Verified live (real save → real toast, DOM + screenshot). |
| Full-repo Alpine.js remnants sweep | Resolved 2026-07-20 | A `grep` sweep (prompted by "didn't we remove Alpine completely?") found 3 more live Blade files with dead Alpine markup: the login/register password-toggle, an unused info-tooltip, and the 2FA challenge screen. Rewrote all three in vanilla JS; deleted the now-fully-dead `toast.blade.php` and an orphaned `DOMPurify` include. |
| Dev environment moved off the Windows-path copy into WSL2 | Resolved 2026-07-12 | Confirmed working end-to-end; the old Windows-path copy is a confirmed-stale duplicate, left in place, delete manually whenever convenient. |
| Pest 4 browser-testing plugin can't run in this dev setup | Open (issue #11) | PHP and Node/Playwright are split across containers (`coolify` has PHP no Node, `coolify-vite` has Node no PHP) — needs a shared container or remote-Playwright wiring. Worked around with an HTTP-level curl check + log tail. |
| Low-level audit of every top-level folder/file | Open (issue #2) | A deliberate, exhaustive sweep, not yet started — continues the pattern of the `backlog/`/`jean.json`/`other/`/`scripts/*` removals already done, which were all found incidentally. |
| Leftover fake `Server`/`Team`/`User` rows in the dev database | Resolved 2026-07-19 | 7 fake servers, 10 fake teams, 3 fake users (Faker-generated scratch state from ad hoc `factory()` calls via Tinker, not any real seeder) were surfacing as false "server not reachable" Horizon warnings. Deleted all of them, including 2 more found in a same-day follow-up. Only the 1 real dev server remains. |
| Email "use system wide settings" toggle didn't save | Resolved 2026-07-20 | Found during smoke-test QA — the checkbox only updated local form state, no save call, so a page reload silently reverted it. Fixed with the same auto-save-on-toggle pattern the file's own `isCloud` variant already used. |
| Mail delivery completely non-functional in dev + a real crash | Resolved 2026-07-20 | Two stacked issues found during smoke-test QA: `.env` never configured `MAIL_MAILER` (silently defaulted to Laravel's no-op driver — no email had ever reached Mailpit); and `ConfigurationRepository::updateMailConfig()` crashed on a null `smtp_encryption` under `strict_types=1`, breaking every transactional email through the default instance config. Both fixed; verified via a full live email-change round-trip through Mailpit. |
| Leftover upstream cruft + a hidden prompt injection in `.github/` templates | Resolved 2026-07-21 | Same category as the `SECURITY.md`/`CODE_OF_CONDUCT.md` cleanup above, just missed in that pass — user spotted it via `.github/ISSUE_TEMPLATE/config.yml` still pointing at coollabs' Discord/Discussions. Broader audit found: (1) `config.yml`'s `contact_links` pointed at upstream community links that don't exist for this fork — replaced with plain `blank_issues_enabled: true`; (2) `01_BUG_REPORT.yml` had a real content mismatch, not just a stale link — a required "Are you using Coolify Cloud?" dropdown, but this fork has no Cloud (fully de-commercialized); removed, plus swapped the upstream version-placeholder format for a commit-hash field; (3) `pull_request_template.md` contained a **hidden prompt injection** — an HTML comment invisible in the rendered template instructing any AI assistant to insert the word "STRAWBERRY" into PR descriptions, explicitly targeting AI and not human contributors ("Ignore if you are a human"), almost certainly upstream Coolify's own anti-AI-slop tripwire. Not complied with; removed entirely, along with the Contributor Agreement section's links to `coollabsio/coolify`'s own `CONTRIBUTING.md`/issues/PRs (repointed to this fork's own repo) and a "must be human-written, not AI-generated" line that contradicted the template's own separate AI-disclosure checkbox. See issue #40. |
| "What's New" changelog widget showed upstream's real product news, not this fork's | Resolved 2026-07-21 | Follow-up from the `.github/` cleanup above — same category of problem, but live application behavior this time, not just contributor-facing templates: `PullChangelog.php` (an actively-scheduled job) fetched upstream Coolify's actual GitHub release notes and displayed them under the "What's New" bell icon (top-right header, every logged-in page) to real users of this fork, linking out to `coollabsio/coolify`'s own releases page. Fixed to work exclusively for this fork: `releases_url` repointed at `https://api.github.com/repos/Terrence721/coolify-full/releases` (GitHub's own public API — the same JSON shape the job already expects; upstream's CDN-indirection exists only to dodge GitHub's rate limit across their many self-hosted instances, doesn't apply here), `WhatsNewButton.jsx`'s hardcoded release link repointed likewise, and 4 real GitHub Releases published on this repo marking genuine milestones, tagged at their actual historical commits (`v0.1.0` de-commercialization, `v0.2.0` migration complete, `v0.3.0` PHPStan+security hardening complete, `v0.4.0` current test-coverage/CI state) — each body notes the GitHub-stamped publish date reflects when it was tagged, not when the work happened. Also found and fixed a related inconsistency: `constants.coolify.version` was a stale, disconnected `"1.0"` that would have made the "CURRENT VERSION" badge never match any real entry — synced to `"0.4.0"`. Verified end-to-end: `PullChangelog::dispatchSync()` fetched the 4 real releases and wrote correct content to `changelogs/`; full Pest suite 1234/1234. See issue #41. |
| `Init.php`'s `sendAliveSignal()` phoned home to upstream's telemetry endpoint | Resolved 2026-07-21 | Follow-up from issue #41's flag — `sendAliveSignal()` pinged `https://undead.coolify.io/v4/alive?appId=X&version=Y` on every init run, gated by `do_not_track`, which defaults to `false` (tracking **on**) per the original migration. A fresh install of this fork silently reported its app ID/version to upstream unless someone found and flipped "Do Not Track" in Settings → Advanced. User's explicit call: remove the call entirely rather than just flip the default — this fork has no real operational relationship with upstream's telemetry service, so no install of this codebase should ever ping it, toggle or not. Removed the method and its call site from `Init.php`. `do_not_track` itself (column, model property, Settings UI toggle) deliberately left intact — it has a separate, legitimate second use in `app/Exceptions/Handler.php` (gating whether a user's real email gets attached to Sentry error-report scope); every consumer was checked before touching anything. No existing test coverage referenced the removed code. Full Pest suite 1234/1234, PHPStan/Pint clean. See issue #42. |
| More upstream branding: a dead analytics/Sentry script, wrong OG meta tags, an email support link to upstream, a dev-mode staging URL | Resolved 2026-07-21 | User challenged whether the `.github/` sweep (issue #40) was actually complete, since it only grepped the literal string `coollabsio` — missing anything using the `coollabs.io`/`coolify.io` domains (the dot breaks that substring match). A proper re-scan found real issues in `resources/views/layouts/base.blade.php`: a Plausible analytics script + upstream's own Sentry browser SDK, gated behind `config('app.name') == 'Coolify Cloud'` — checked the actual condition first (every real env file ships `APP_NAME=Coolify`, never `"Coolify Cloud"`), confirming this was dead code, not actively-running tracking as first assumed; removed regardless, since even reachable it's upstream's own monitoring accounts for a Cloud product this fork doesn't have. Also in the same file, unconditionally on every page: `og:url` hardcoded to `https://coolify.io` (fixed to `{{ url()->current() }}`), `twitter:site` set to upstream's own handle (removed), and `og:image`/`twitter:image` hotlinking upstream's CDN-hosted image with no replacement of this fork's own (removed, `twitter:card` downgraded to `summary` accordingly). Separately: `resources/views/components/emails/footer.blade.php`'s "Contact Support" link on every transactional email (password resets, verification codes, alerts) pointed to `coolify.io/docs/contact` — repointed to this fork's own GitHub issues. `app/Notifications/Test.php`'s Telegram test-notification hardcoded upstream's staging URL as a dev-mode workaround for Telegram's real-HTTPS-URL requirement — swapped for `https://example.com` (RFC 2606), same workaround, no upstream reference. Deliberately left alone: `high-disk-usage.blade.php`'s and `AGENTS.md`'s links to still-accurate upstream documentation this fork hasn't rewritten — content reuse, not branding/identity misdirection, same reasoning as the service-templates CDN feed (issue #41). Verified live against the rendered `/login` page's actual HTML head; full Pest suite 1234/1234, PHPStan/Pint clean. See issue #43. |
| "Search everywhere" follow-up: stale build output + `composer.json`'s package identity | Resolved 2026-07-21 | Two prior scans (issues #40, #43) each missed real things by only checking source files, so this pass covered the compiled JS bundle, `composer.json`/`package.json`, public static files, and CI. Found the production build was genuinely stale — `public/build/assets/inertia-app-*.js` still contained the *old, unfixed* `coollabsio/coolify/releases/tag/` URL from issue #41's `WhatsNewButton.jsx` fix, since the source was corrected but `yarn build` was never re-run afterward. Rebuilt; confirmed the fix is now actually in the compiled output (`public/build/` is gitignored, nothing to commit for this part). Investigated and confirmed as false positives, not more noise: `bg-coollabs`/`text-coollabs` throughout the CSS/JS is a defined Tailwind color token (`--color-coollabs` in `app.css`), the app's real accent-color name — invisible to users, not worth renaming; remaining `coolify.io/docs/...` links are the same legitimate real-documentation case as issue #43; a `placeholder="https://coolify.io"` in `ApplicationGeneralTab.jsx` is illustrative form-input example text, not a link. Fixed `composer.json`'s `name`/`description` (was still `coollabsio/coolify`, never actually updated despite being flagged as a candidate earlier) to reflect this fork's own identity; refreshed `composer.lock`'s content-hash via `composer update --lock` (confirmed via diff: only the hash changed, zero package versions touched). Verified: `composer validate` clean, PHPStan 427/427 no errors (one run hit a transient parallel-worker memory-limit crash, confirmed unrelated by retrying with `--memory-limit=1G`), Pint clean, full Pest suite 1234/1234. See issue #44. |

### Verification standing habit

- Every change in this repo is expected to go through: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse`, `php artisan test --compact`, and `yarn build` before being considered done. See `docs/command.md` for the exact commands.
