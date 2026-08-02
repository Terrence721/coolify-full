# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 2, 2026**

> [!CAUTION]
> This is a simulation of real-world code review.

Every finding below went through a real GitHub Pull Request: a branch, a review comment on the diff, and a real merge — see [issue #70](https://github.com/Terrence721/coolify-full/issues/70) for the live tracking card. This page is a readable historical index, not the live mechanism.

**How areas get picked**: reviewed areas aren't chosen in file-tree or alphabetical order. Security/data-exposure risk (redaction gaps, auth checks) is triaged first, then reliability bugs in core resource lifecycle paths (deploy/start/stop, backups, cleanup jobs), then correctness bugs in lower-traffic paths, with maintainability-only findings last. This explicit ordering was formalized 2026-08-01 — findings through the twentieth (`StopDatabase.php`) used unstructured scanning rather than this tiered process. The twenty-first finding (`CleanupHelperContainersJob.php`) is the first one made under it: the security tier was explicitly scanned and cleared (every API redaction wrapper, all 4 Git webhook signature-verification paths) before moving to the reliability tier that produced the finding.

---

### [`TerminalController.php:22,46`](https://github.com/Terrence721/coolify-full/blob/d25f580d43decef742c79d6f905caa492becb89f/app/Http/Controllers/TerminalController.php#L22-L46)

**medium · Maintainability** — Fixed via [PR #72](https://github.com/Terrence721/coolify-full/pull/72) ([`b49242cbe`](https://github.com/Terrence721/coolify-full/commit/b49242cbe8efdbc83fc9a788d8e2bb1f854b693b))

The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` and `connect()`. If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other. Suggested fix: extract into a shared `eligibleServers(): Collection` method.

---

### [`ServiceExtraFieldsResolver.php:389-413`](https://github.com/Terrence721/coolify-full/blob/a97a0cb9aba0a20720ee1ec8ee495e5790d1482f/app/Services/ServiceExtraFieldsResolver.php#L389-L413)

**high · Reliability** — Fixed via [PR #73](https://github.com/Terrence721/coolify-full/pull/73) ([`27941b8d6`](https://github.com/Terrence721/coolify-full/commit/27941b8d6be339f558410331620667cf43e53d14))

The `kong` switch case builds dashboard user/password fields but labels the group `'Supabase'` instead of `'Kong'` — a real, user-facing mislabel. It's also missing a `break;`, so PHP falls through into the next case (`minio`) unconditionally, running MiniO's whole field block for a Kong service too and producing a spurious, mostly-empty "MinIO" group alongside the mislabeled one. Suggested fix: rename to `'Kong'` and add the missing `break;`.

---

### [`GetContainersStatus.php:346-370`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194339984)

**medium · Maintainability** — Fixed via [PR #74](https://github.com/Terrence721/coolify-full/pull/74) ([`fb880789a`](https://github.com/Terrence721/coolify-full/commit/fb880789a86b6c7c42dcaca5d85f18d4eca7f47a))

Both the exited-service and exited-database branches computed `containerName`/`projectUuid`/`url` etc. purely to feed a `notify()` call that's been commented out since the original upstream import — dead work on a hot polling path. `ContainerStopped` is still real, wired infrastructure elsewhere, so this wasn't abandoned code to delete outright. Suggested fix: remove the unused computation rather than silently re-enable a disabled notification.

---

### [`ApplicationDeploymentJob.php:2011`](https://github.com/Terrence721/coolify-full/commit/34648d53fe8f312d50eb707ff9e2bc4e53658a79#commitcomment-194351145)

**high · Reliability** — Fixed via [PR #75](https://github.com/Terrence721/coolify-full/pull/75) ([`d5eeec0cb`](https://github.com/Terrence721/coolify-full/commit/d5eeec0cb904259bad3e0d63f54a0126b6daf09f)) — **no automated test coverage or live Swarm verification**, disclosed on the PR (this job has zero existing tests; no Swarm cluster exists in this dev environment)

`health_check()` was a complete no-op for Docker Swarm — `// Implement healthcheck for swarm` — inherited from the original upstream import. `$newVersionIsHealthy` defaulted `false` and was never set for Swarm deployments, so they got zero application-level health verification (traced all 3 read sites of the flag; none are reachable from the Swarm path with a default `force:false`, so no active failures today, just a missing capability). Suggested fix: poll `docker stack services`/`docker service ps` for replica convergence, reusing the existing healthcheck retry/interval config.

---

### [`Environment.php:97-102`](https://github.com/Terrence721/coolify-full/commit/34648d53fe8f312d50eb707ff9e2bc4e53658a79#commitcomment-194359875)

**medium · Maintainability** — Fixed via [PR #76](https://github.com/Terrence721/coolify-full/pull/76) ([`b251b1b07`](https://github.com/Terrence721/coolify-full/commit/b251b1b07b2f8aeafa7185fe68061cb068466d53)) — writing a regression test for this surfaced a much bigger, separate finding: `database/schema/testing-schema.sql` has zero `FOREIGN KEY` constraints anywhere and hasn't been regenerated since the original import, so no FK is enforced in the whole Pest suite. Tracked as [issue #77](https://github.com/Terrence721/coolify-full/issues/77)

`booted()`'s `deleting()` hook looped over `environment_variables()` — a `HasMany` query builder (called with parens), not a `Collection`. `HasMany` isn't `Traversable`, so the loop ran zero iterations, always — confirmed empirically via a live tinker test, not just inspection. Deletion works today purely via a real DB-level `ON DELETE CASCADE` foreign key; this code never protected anything. Suggested fix: remove the dead hook entirely.

---

### [`UpdatePackage.php:49-70`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194372949)

**medium · Reliability** — Fixed via [PR #78](https://github.com/Terrence721/coolify-full/pull/78) ([`500133f02`](https://github.com/Terrence721/coolify-full/commit/500133f02d9c8f7656bd5c71b417bfa22c64b517)) — also fixed a real test-isolation bug the new regression test surfaced: the shared `remote_process()` test fake unconditionally shadowed the real function for every `App\Actions\Server` class for the rest of the test process, breaking `ConfigureCloudflared`'s tests; gated behind an explicit opt-in flag

The package-manager switch handled `zypper`/`dnf`/`apt`/`pacman` but had no `apk` case, inherited from the original upstream import. `CheckUpdates.php` fully detects and parses Alpine (`apk`) updates, and the Patches UI's own tooltip advertises apk as supported — traced end-to-end, `packageManager` passes straight from `CheckUpdates`'s response into `UpdatePackage`, so an Alpine server correctly listed pending updates, then failed every update attempt with "OS not supported". Suggested fix: add the missing `apk` case.

---

### [`StartLogDrain.php:164-171`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194392974)

**medium · Reliability** — Fixed via [PR #79](https://github.com/Terrence721/coolify-full/pull/79) ([`a677e183e`](https://github.com/Terrence721/coolify-full/commit/a677e183e529211f693b315eab77e78258e6ecf6))

The README.md written to a server's log-drain config directory was hardcoded to "New Relic Log Drain"/"New Relic Log Forwarder" regardless of `$type` — the block sat outside the type if/elseif chain, inherited from the original upstream import. A server configured for Highlight, Axiom, or a custom drain got a real, incorrect README on disk. Suggested fix: branch the README content by type, matching how `$envContent` already does.

---

### [`InstallPrerequisites.php:25`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194398516)

**high · Reliability** — Fixed via [PR #80](https://github.com/Terrence721/coolify-full/pull/80) ([`858e39498`](https://github.com/Terrence721/coolify-full/commit/858e39498e2044cd43ade06252faab9d87577f18))

The OS if/elseif chain handled `debian`/`rhel`/`sles`/`arch` but had no `alpine` branch, inherited from the original upstream import — even though `alpine` is one of the 5 `SUPPORTED_OS` entries and `Server::validateOS()` correctly recognizes it. A fresh Alpine server flagged as missing prerequisites by `ValidatePrerequisites` (OS-agnostic) would then hit `InstallPrerequisites`'s `else` branch and throw "Unsupported OS type", contradicting `validateOS()`'s own answer one step earlier — could never be automatically onboarded. Suggested fix: add an `alpine` branch using `apk`, matching `UpdatePackage.php`'s already-correct handling.

---

### [`ValidateServer.php:73`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194406982)

**medium · Reliability** — Fixed via [PR #82](https://github.com/Terrence721/coolify-full/pull/82) ([`131043ac1`](https://github.com/Terrence721/coolify-full/commit/131043ac116b4aaf9c9618da91c5e44cd5d8e08b))

The final validation branch showed "Docker Engine is not installed" whenever `validateDockerEngineVersion()` failed — but the earlier `docker_installed`/`docker_compose_installed` check had already passed by that point, so Docker genuinely was installed; the version check only fails when it's below the minimum required version. A user on an older Docker version was told to install Docker, not upgrade it. Suggested fix: a distinct message naming the actual minimum version.

---

### [`ValidateAndInstallServerJob.php:153`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194408241) & [`ServerValidationService.php:80`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194408241)

**medium · Reliability** — Fixed via [PR #83](https://github.com/Terrence721/coolify-full/pull/83) ([`1b6b96a70`](https://github.com/Terrence721/coolify-full/commit/1b6b96a708ada892d93b7f7358c5cd1a8d9ea576))

Same wording bug as `ValidateServer.php` above, found while checking whether that fix was complete, in 2 more places. The job version is inherited from the original upstream import; the service version is **not inherited** — written fresh during this fork's own Phase 78 migration, so the wording got copy-pasted into new code rather than questioned.

---

### [`CleanupDocker.php:165`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194422494)

**high · Reliability** — Fixed via [PR #85](https://github.com/Terrence721/coolify-full/pull/85) ([`0473fb400`](https://github.com/Terrence721/coolify-full/commit/0473fb40016eea09eae0284e78fe79a49348679f))

`cleanupApplicationImages()` located the currently-running image via `docker inspect` against a container named after the application's bare UUID, inherited from the original upstream import. The real container name almost never matches: `generateApplicationContainerName()` appends a deploy-time timestamp unless `is_consistent_container_name_enabled` is on (off by default), and that timestamp isn't persisted anywhere retrievable afterward. The lookup silently failed, so the "protect the currently running image from deletion" logic never engaged — the in-use image could be deleted like any other old one, risking breakage after a rollback. Suggested fix: look the container up via Coolify's own `coolify.applicationId`/`coolify.pullRequestId` labels instead of guessing its name.

---

### [`IsHorizonQueueEmpty.php:25`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194483106)

**medium · Reliability** — Fixed via [PR #86](https://github.com/Terrence721/coolify-full/pull/86) ([`271a6eed5`](https://github.com/Terrence721/coolify-full/commit/271a6eed50e0e94e1c5eeaf43e51864b284c5b8e))

`handle()` filtered Horizon's recent jobs by `in_array('server:'.gethostname(), $tags)`, inherited from the original upstream import. No job in this codebase tags itself that way — `ApplicationDeploymentJob::tags()` (the only `tags()` override that exists) returns `'App\Models\ApplicationDeploymentQueue:<id>'`, a completely different format. The filter could never match any real job, so `handle()` always returned `true` ("queue is empty") regardless of how many jobs were actually running. Currently unreferenced anywhere in `app/`, but a real, exported `AsAction` with its own dedicated test suite — clearly intended as a genuine safety check that would silently give a false "safe to proceed" answer if ever wired up. Suggested fix: drop the hostname/tag scoping entirely (Coolify is single-instance, so it never corresponded to anything real) and just check for any non-completed, non-failed recent job.

---

### [`StartService.php:81`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194489436)

**medium · Reliability** — Fixed via [PR #88](https://github.com/Terrence721/coolify-full/pull/88) ([`7da83a5bb`](https://github.com/Terrence721/coolify-full/commit/7da83a5bbc53cba5f06dfbd91b2559a7becb1a4c))

`shouldStopBeforeStarting()` returned `$stopBeforeStart && !$pullLatestImages`, inherited from the original upstream import — a caller passing both `stopBeforeStart: true` and `pullLatestImages: true` got the stop silently cancelled. Reachable via the real, OpenAPI-documented `POST /api/v1/services/{uuid}/restart?latest=true` endpoint (`RestartService` always passes `stopBeforeStart: true`, forwarding the request's `latest` param straight through). `StopService::handle()` is the only place that cancels stale in-progress/queued `Activity` records for a service, so skipping it left a genuinely stuck prior deployment stuck forever after a restart-with-latest-images via the API. Container recreation itself was unaffected (`docker compose up --force-recreate --build` always runs regardless) — this was specifically about the lost activity-cleanup side effect. Suggested fix: honor `stopBeforeStart` unconditionally rather than letting `pullLatestImages` silently override it.

---

### [`DeleteUserTeams.php:27`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194498145)

**high · Reliability** — Fixed via [PR #89](https://github.com/Terrence721/coolify-full/pull/89) ([`48164fc7d`](https://github.com/Terrence721/coolify-full/commit/48164fc7d013e63d3efababa7e4b5c9bdd0156f9))

`getTeamsPreview()` read `$this->user->teams`, a dynamic property access Laravel caches on the model instance after the first load, inherited from the original upstream import. `execute()` calls `getTeamsPreview()` again internally as a safety re-check right before its destructive operations (team deletion, ownership transfer, member removal) — but since it's the same `$this->user` object both times, the second call returned the exact same cached snapshot as the first, never re-querying the database. The only real caller, `AdminDeleteUser` (an interactive console command), prints a preview, blocks on a confirmation prompt, then calls `execute()` on the same instance — any team membership/ownership change made during that pause was invisible to the re-check, risking an ownership transfer to someone no longer eligible or deleting a team no longer solely-owned by the user being deleted. Suggested fix: query fresh (`$this->user->teams()->get()`) instead of the cached relation property.

---

### [`AdminDeleteUser.php:100`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194504772)

**high · Reliability** — Fixed via [PR #90](https://github.com/Terrence721/coolify-full/pull/90) ([`c40795983`](https://github.com/Terrence721/coolify-full/commit/c40795983e255c097496532657a38b5864fe4f4))

`Cache::lock($lockKey, 600)` acquires a 10-minute lock to prevent two operators running `admin:delete-user` for the same user concurrently, inherited from the original upstream import, then never refreshes it. Between phases the command blocks on interactive `confirm()`/`ask()` prompts of unbounded human duration — a careful operator reading multi-page deletion previews before confirming something irreversible can easily exceed 10 cumulative minutes. The lock then silently expires while the command is still mid-flight (and its `DB::beginTransaction()` is still open), letting a second operator start a fully concurrent deletion for the same user — exactly what the lock exists to prevent. Suggested fix: refresh the lock's TTL after each completed phase.

---

### [`CleanupDatabase.php:45-49,53-57`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194511253)

**high · Reliability** — Fixed via [PR #91](https://github.com/Terrence721/coolify-full/pull/91) ([`9a2d0f7ee`](https://github.com/Terrence721/coolify-full/commit/9a2d0f7ee11f0368b3456ba36ff8a2f5647a4660))

The `activity_log`/`application_deployment_queues` cleanup blocks chained `->orderBy('created_at', 'desc')->skip(10)` before both `->count()` and `->delete()`, inherited from the original upstream import, intending to always keep the 10 most-recent-of-the-old rows as a buffer. Neither worked: `->skip(N)->count()` compiles to a single-row aggregate query with `OFFSET`, which always discards that row and returns 0 regardless of real data; Postgres's `DELETE` grammar only special-cases `->limit()`, never `->offset()`, silently deleting every matching row instead. `cleanup:database --yes` runs **daily** via the scheduler — every day this logged "Delete 0 entries" for both tables while actually deleting everything past the retention window, with no buffer ever enforced. Suggested fix: resolve the ids to keep explicitly and filter via `whereNotIn()` instead of `skip()`.

---

### [`DatabasesController.php:32-46`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194526871)

**critical · Security** — Fixed via [PR #92](https://github.com/Terrence721/coolify-full/pull/92) ([`d6b0457ee`](https://github.com/Terrence721/coolify-full/commit/d6b0457ee46e739bc27842ec3ec05babd41bcede))

`removeSensitiveData()` redacted the password field for 6 of 8 database engines but silently omitted **MySQL and MariaDB**, inherited from the original upstream import — `mysql_password`, `mysql_root_password`, `mariadb_password`, and `mariadb_root_password` weren't gated behind `read:sensitive` at all, so any token with plain `read` ability got them back in plaintext on `GET /api/v1/databases` (`/{uuid}`). Confirmed empirically end-to-end: a real `StandaloneMysql` row's `mysql_root_password` came back verbatim in a real API response. The codebase's own `$allowedFields` list for creating/updating a database already includes all 4 fields alongside every other engine's — a copy-paste gap in the redaction list specifically, not an intentional omission. Suggested fix: add the 4 missing fields to the sensitive-hidden list.

---

### [`ServersController.php:29-38`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194543403)

**critical · Security** — Fixed via [PR #94](https://github.com/Terrence721/coolify-full/pull/94) ([`a22ba9957`](https://github.com/Terrence721/coolify-full/commit/a22ba9957600d72d971cfe11de25d180aca4c029))

`removeSensitiveDataFromSettings()` only hid `sentinel_token`, inherited from the original upstream import — `logdrain_axiom_api_key` and `logdrain_newrelic_license_key`, both real `ServerSetting` columns with no `$hidden` anywhere on the model (and not even `'encrypted'`-cast, unlike `sentinel_token`), leaked to any token with plain `read` ability on `GET /api/v1/servers` (`/{uuid}`). Same bug class as the MySQL/MariaDB leak in `DatabasesController` (PR #92), found by checking its sibling API controllers for the same gap. Confirmed empirically end-to-end. Suggested fix: add both fields to the `makeHidden()` call.

---

### [`StopApplication.php:60-69`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194571403) / [`StopService.php:38-53`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194571403)

**high · Reliability** — Fixed via [PR #95](https://github.com/Terrence721/coolify-full/pull/95) ([`37da7abd4`](https://github.com/Terrence721/coolify-full/commit/37da7abd49abc9e0a861f702a8f72699ca24774a))

`StopApplication::handle()`'s if/else coupled two unrelated concerns, inherited from the original upstream import: the default `resetRestartCount = true` path (web "Stop" button, API stop endpoint) reset restart-count bookkeeping but never set `status` to `exited` — only the crash-loop auto-stop path (which explicitly passes `resetRestartCount: false`) did. `StopService::handle()` had the same gap in every path — it never updated its child `ServiceApplication`/`ServiceDatabase` `status` columns at all. Both `Application::status` and `Service::getStatusAttribute()` are read everywhere the UI/API report resource state, so a stopped resource kept reporting its pre-stop status until the next independent `GetContainersStatus` poll caught up. For Services this wasn't just a stale badge: `ServicesController::action_deploy()`/`action_stop()` gate on this same live status, so a stop followed shortly by a legitimate deploy call could be incorrectly rejected with a 400. Suggested fix: always set `status: exited` after a successful stop in both actions, independent of `resetRestartCount`.

---

### [`StopDatabase.php:29-34`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194595305)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #96](https://github.com/Terrence721/coolify-full/pull/96) ([`a7ce8ee67`](https://github.com/Terrence721/coolify-full/commit/a7ce8ee67097da1b77d055df5ca727e2b6325669))

Same bug class as `StopApplication.php`/`StopService.php` above — `StopDatabase::handle()` stopped and removed the container, then reset restart-count bookkeeping, but never wrote `status` back to `exited`, inherited from the original upstream import. `status` is a real, persisted column, read live by `DatabasesController::action_deploy()`/`action_stop()`, which gate on it to reject an already-running/already-stopped request. Since nothing corrected the column until the next independent `GetContainersStatus` poll, a stop followed shortly by a legitimate start call could be wrongly rejected with a 400 while the container was, in reality, already stopped. Suggested fix: write `status: exited` alongside the restart-count reset, matching `StopApplication.php`'s already-landed fix.

---

### [`CleanupHelperContainersJob.php:27-75`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194600020)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #97](https://github.com/Terrence721/coolify-full/pull/97) ([`862fe3ddd`](https://github.com/Terrence721/coolify-full/commit/862fe3ddd6538de9a3638f9455f63867457f4931))

`handle()`'s only "is this container still needed?" source is `ApplicationDeploymentQueue`, inherited from the original upstream import — it force-removed any `coolify-helper`-image container that wasn't a matching active deployment, including `backup-of-*` (`DatabaseBackupJob`'s S3 upload) and `s3-restore-*` (the S3 restore flow in `ManagesDatabaseImport`), neither of which ever writes to that table. This job runs for every functional server after **any** resource delete anywhere in the instance (`CleanupStuckedResources`, queued from `DeleteResourceJob`'s `finally` block), not scoped to the server being cleaned up — so an unrelated delete on one team's server could kill a backup upload or restore in progress on a completely different team's server, silently marking the backup `s3_uploaded: false` or erroring the restore mid-transfer. Suggested fix: skip `backup-of-*`/`s3-restore-*` containers the same way active-deployment containers are already skipped.

---

### [`ResourcesController.php:43-70`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194603095)

**critical · Security** — Fixed via [PR #98](https://github.com/Terrence721/coolify-full/pull/98) ([`c3d509c45`](https://github.com/Terrence721/coolify-full/commit/c3d509c45b31717667353fe9736d2cfc5187df3f))

`resources()` (`GET /api/v1/resources`, gated only by plain `read` ability) called raw `$resource->toArray()` on every Application/Service/database row on the team, with zero redaction, inherited from the original upstream import — unlike every other controller returning these same model types. No model in this codebase has a `$hidden` property; all redaction is opt-in and per-controller, so this was the one place that skipped it entirely. Any token with only `read` got every application's and database's secrets in the clear for the whole team in one call — `docker_compose_raw`, `manual_webhook_secret_*`, `http_basic_auth_password` on Applications; every engine's real password columns on databases. A broader version of the exact bug class already fixed twice (`DatabasesController` PR #92, `ServersController` PR #94) — here it's zero redaction across every resource type at once, not a couple of missing fields in a list. Suggested fix: apply the same per-type redaction the dedicated controllers already use, dispatched on `$resource->type()`.

---

### [`DeployController.php:681-714`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194606479)

**critical · Security** — Fixed via [PR #99](https://github.com/Terrence721/coolify-full/pull/99) ([`53c7ee3fa`](https://github.com/Terrence721/coolify-full/commit/53c7ee3fae687da773f8dfb70abee4eeefd93aa2))

`get_application_deployments()` returned raw `ApplicationDeploymentQueue` rows with zero redaction, inherited from the original upstream import — unlike its two siblings in the same controller (`deployments()`, `deployment_by_uuid()`), which both route through `removeSensitiveData()` to hide the `logs` column unless the token carries `read:sensitive`. All 3 routes share the identical `api.ability:read` gate, so there's no ability difference explaining the exemption — this endpoint just forgot to call the controller's own already-correct redaction helper. `logs` is real build/deploy console output written during deployment by `ExecuteRemoteCommand` — it routinely contains printed env values, git URLs with embedded access tokens, and registry auth output, exactly why the other two endpoints already gate it. Any token with only `read` could pull every one of an application's deployment logs verbatim. Same bug class as findings already fixed 3 times (`DatabasesController` PR #92, `ServersController` PR #94, `ResourcesController` PR #98), but a new instance: a forgotten call to existing redaction, not a gap in a field list. Suggested fix: route the response through `removeSensitiveData()` the same way the other two endpoints do.

---

### [`ApplicationDeploymentController.php:75,142,167,237`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194610985)

**critical · Security** — Fixed via [PR #100](https://github.com/Terrence721/coolify-full/pull/100) ([`928af5d90`](https://github.com/Terrence721/coolify-full/commit/928af5d90))

`show()`, `forceStart()`, `cancel()`, and `downloadAllLogs()` all correctly resolve `$application` scoped to the caller's own team via `resolveApplication()`, but each then independently looked up its `ApplicationDeploymentQueue` by `deployment_uuid` alone — a globally-unique column with no ownership check against the resolved application. `show()`/`downloadAllLogs()` pass the deployment to `decode_remote_command_output()`, which re-derives the application from `$deployment->application_id` directly, ignoring the route's team-scoped application entirely — so a caller could view another team's real deployment console output, the same exposure class fixed in `DeployController` PR #99. `cancel()` would issue a real `docker rm -f`/`kill -9` over SSH against the foreign deployment's server (a cross-tenant DoS with an actual remote side effect), and `forceStart()` would dispatch `ApplicationDeploymentJob` against the foreign deployment. An attacker only needed their own project/environment/application URL segments plus another team's `deployment_uuid`. Same bug class as the API redaction gaps already fixed 4 times, but a new shape: a missing ownership check on a secondary lookup inside an otherwise-correctly-scoped web controller. Fix: scope each lookup with `->where('application_id', $application->id)`.

---

### [`StopApplication.php:20-79`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194613279)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #101](https://github.com/Terrence721/coolify-full/pull/101) ([`d0b578a1f`](https://github.com/Terrence721/coolify-full/commit/d0b578a1f))

`handle()` writes the main `status` column to `exited` after stopping every server, but for multi-server applications (`additional_servers`) never touches the `additional_destinations` pivot's own `status` column. `Application::status()`'s getter compares the main status against each additional server's pivot status and reports `"degraded:*"` on any mismatch — the only writer of that pivot column is `ComplexStatusCheck`, invoked from `GetContainersStatus`, which `StopApplication` never calls. Consequence: stopping a multi-server application leaves it visibly reporting `degraded:unhealthy` in both the UI and `GET /api/v1/applications/{uuid}` until an unrelated poll happens to correct it, even though every server is actually stopped. Same bug lineage as the status-write gaps already fixed 3 times for `StopApplication`/`StopService`/`StopDatabase` (PR #95/#96), but those only covered the top-level `status` column — this is the multi-server/pivot dimension of the same accessor, left uncovered. Fix: call `ComplexStatusCheck::run($application)` when the application has additional servers, right after the existing status write.

---

### [`SafeWebhookUrl.php` + `SendWebhookJob.php:72`, `SendMessageToDiscordJob.php:46`, `SendMessageToSlackJob.php:69,111`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194617711)

**critical · Security (SSRF)** — Fixed via [PR #102](https://github.com/Terrence721/coolify-full/pull/102) ([`de10c945a`](https://github.com/Terrence721/coolify-full/commit/de10c945a))

`SafeWebhookUrl` blocks loopback/link-local hosts to "prevent SSRF to cloud metadata endpoints" — but only when the configured host string is itself a literal IP; it never resolves hostnames before checking. None of the three outbound send paths (`SendWebhookJob`, `SendMessageToDiscordJob`, `SendMessageToSlackJob`'s Slack and Mattermost branches) disabled redirect-following on their `Http::post()` calls, so Guzzle's default (follow up to 5 redirects) applied. Consequence: a team member with only notification-settings access can set a webhook URL to a domain they control that passes validation, then 302-redirects to `169.254.169.254` (cloud instance metadata) or an internal address at send time — the server follows it, issuing a blind SSRF POST with attacker-influenced payload, fully bypassing the class's own documented protection. Directly exploitable via each notification page's existing "Send Test" button, no wait for a real event. Fix: `Http::withoutRedirecting()` on all 4 call sites. DNS rebinding (a hostname resolving safely at validation time, unsafely at request time) is a separate, larger problem not addressed here — would need IP-pinning via a custom resolver.

---

### [`PushServerUpdateJob.php:351` (`aggregateMultiContainerStatuses()`)](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194633661)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #105](https://github.com/Terrence721/coolify-full/pull/105) ([`f6339ee70`](https://github.com/Terrence721/coolify-full/commit/f6339ee70))

`aggregateMultiContainerStatuses()` writes to an application's main `status` column unconditionally for every server it processes — but `loadApplications()` deliberately loads a multi-server application even when `$this->server` is only one of its `additional_servers`, not the main destination. Consequence: a routine Sentinel heartbeat push from a non-main server silently overwrites the main `status` column with that additional server's own per-container status, clobbering whatever the main server had just correctly reported and breaking the "degraded" comparison `Application::status()`'s accessor depends on. One method earlier in the exact same job, `updateAdditionalServersStatus()` → `ComplexStatusCheck`, already correctly splits main-vs-additional writes (main column for the main server, `additional_destinations` pivot row per additional server — the same fix already landed for `StopApplication.php`, PR #101) — `aggregateMultiContainerStatuses()` runs right after it and undoes that separation. Fix: check whether `$this->server` is actually the application's main destination (using data the job already caches) before writing; if not, write to that server's own pivot row instead.

---

### [`DeleteService.php:21-24` + `ServiceApplication.php:128-132`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194635772)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #106](https://github.com/Terrence721/coolify-full/pull/106) ([`d44c80e91`](https://github.com/Terrence721/coolify-full/commit/d44c80e91))

`DeleteService::handle()` only cleaned up the Service's own `environment_variables()` inside the `$deleteVolumes && $server->isFunctional()` guard, while the Service row itself is force-deleted unconditionally in the `finally` block. Consequence: deleting a service with "delete volumes" unchecked, or when its server happens to be unreachable at delete time (regardless of the checkbox), silently skips cleanup — since the relation is a polymorphic `MorphMany` with no DB foreign key, nothing else cascades it, and no other job anywhere purges orphaned rows. These commonly hold real secrets (DB passwords, API keys). Separately, `ServiceApplication`'s `deleting` hook already cleans up `persistentStorages()`/`fileStorages()` but had no equivalent for its own `environment_variables()` — always orphaned, every delete. Contrast with the sibling non-service path in `DeleteResourceJob.php:80`, which deletes `environment_variables()` unconditionally — confirming this is a real inconsistency, not intended behavior. `ServiceDatabase` has no `environment_variables()` relation at all, so nothing to fix there. Fix: the Service's own cleanup now runs unconditionally; `ServiceApplication`'s `deleting` hook cleans up its own env vars the same way it already does for storages.

---

### [`EnvironmentController.php:298-379`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194673104)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #107](https://github.com/Terrence721/coolify-full/pull/107) ([`ae1c9c7d7`](https://github.com/Terrence721/coolify-full/commit/ae1c9c7d7))

Cloning a Service via `EnvironmentController::clone()` replicated the Service row and its `docker_compose_raw`, but the loops meant to copy each application/database's volume data (`clone_volume_data=true`) and replicate each database's `scheduledBackups` ran **before** `$newService->parse()` was called. `parse()` is the sole creator of a Service's `ServiceApplication`/`ServiceDatabase` child rows (it dispatches to `serviceParser()`, which builds them from `docker_compose_raw`) — nothing else creates them. So at the point those loops ran, `$newService->applications` and `$newService->databases` were always empty, making the loops permanent no-ops: `VolumeCloneJob` never dispatched for a cloned service's app/db volumes regardless of the `clone_volume_data` checkbox, and scheduled backups were silently dropped on every service clone. The equivalent standalone-database clone path in the same file already does this correctly (replicate → the resource exists → then copy volumes/backups) — that's the pattern this fix now matches.

Debugging this took a couple of wrong turns before landing on the real fix. A real end-to-end test (POST to `project.clone-me.store` against a service built from a real, parseable `docker_compose_raw`) surfaced an unrelated `NotFoundHttpException` (404); temporary checkpoints across the controller ruled out the reordered loops or a routing/auth problem, since none of them ever fired — the failure was happening before the controller even ran. Wrapping the test fixture's own `$service->parse()` call in try/catch (building the *source* service, not the clone) exposed the real cause: `serviceParser()` unconditionally dispatches `ServerFilesFromServerJob` per volume, which under the `sync` queue driver runs synchronously and makes a real SSH call against a server that doesn't exist in tests. An initial guess that this was bind-mount-specific was also wrong (the dispatch fires for every volume type); the actual fix was adding `Bus::fake()` to the one new test missing it — pre-existing, unrelated `serviceParser()` behavior, not something this change introduced. Fix: call `$newService->parse()` immediately after replicating `environment_variables`, then match each source app/database to its newly-parsed counterpart by `name` before copying volume data or replicating scheduled backups.
