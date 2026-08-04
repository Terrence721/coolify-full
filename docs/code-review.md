# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 3, 2026**

> [!CAUTION]
> This is a simulation of real-world code review.

Every finding below went through a real GitHub Pull Request: a branch, a review comment on the diff, and a real merge — see [issue #70](https://github.com/Terrence721/coolify-full/issues/70) for the live tracking card. This page is a readable historical index, not the live mechanism.

**How areas get picked**: reviewed areas aren't chosen in file-tree or alphabetical order. Security/data-exposure risk (redaction gaps, auth checks) is triaged first, then reliability bugs in core resource lifecycle paths (deploy/start/stop, backups, cleanup jobs), then correctness bugs in lower-traffic paths, with maintainability-only findings last. This explicit ordering was formalized 2026-08-01 — findings through the twentieth (`StopDatabase.php`) used unstructured scanning rather than this tiered process. The twenty-first finding (`CleanupHelperContainersJob.php`) is the first one made under it: the security tier was explicitly scanned and cleared (every API redaction wrapper, all 4 Git webhook signature-verification paths) before moving to the reliability tier that produced the finding.

---

### [`TerminalController.php:22,46`](https://github.com/Terrence721/coolify-full/blob/d0dbf1215407ea4c564f98ba8c454142018bbf9a/app/Http/Controllers/TerminalController.php#L22-L46)

**medium · Maintainability** — Fixed via [PR #72](https://github.com/Terrence721/coolify-full/pull/72) ([`d9456b466`](https://github.com/Terrence721/coolify-full/commit/d9456b466e40fcef84d05566d22c993b4832d595))

The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` and `connect()`. If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other. Suggested fix: extract into a shared `eligibleServers(): Collection` method.

---

### [`ServiceExtraFieldsResolver.php:389-413`](https://github.com/Terrence721/coolify-full/blob/ba2201d8e47d84b74d679c4c45a4498b5415a4e6/app/Services/ServiceExtraFieldsResolver.php#L389-L413)

**high · Reliability** — Fixed via [PR #73](https://github.com/Terrence721/coolify-full/pull/73) ([`855b29f79`](https://github.com/Terrence721/coolify-full/commit/855b29f79e71804a60da1b1092a184891657d5a3))

The `kong` switch case builds dashboard user/password fields but labels the group `'Supabase'` instead of `'Kong'` — a real, user-facing mislabel. It's also missing a `break;`, so PHP falls through into the next case (`minio`) unconditionally, running MiniO's whole field block for a Kong service too and producing a spurious, mostly-empty "MinIO" group alongside the mislabeled one. Suggested fix: rename to `'Kong'` and add the missing `break;`.

---

### [`GetContainersStatus.php:346-370`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194339984)

**medium · Maintainability** — Fixed via [PR #74](https://github.com/Terrence721/coolify-full/pull/74) ([`ce01fcb9e`](https://github.com/Terrence721/coolify-full/commit/ce01fcb9e4e492e9143be5feff6f50f8da8df196))

Both the exited-service and exited-database branches computed `containerName`/`projectUuid`/`url` etc. purely to feed a `notify()` call that's been commented out since the original upstream import — dead work on a hot polling path. `ContainerStopped` is still real, wired infrastructure elsewhere, so this wasn't abandoned code to delete outright. Suggested fix: remove the unused computation rather than silently re-enable a disabled notification.

---

### [`ApplicationDeploymentJob.php:2011`](https://github.com/Terrence721/coolify-full/commit/34648d53fe8f312d50eb707ff9e2bc4e53658a79#commitcomment-194351145)

**high · Reliability** — Fixed via [PR #75](https://github.com/Terrence721/coolify-full/pull/75) ([`23c1489e0`](https://github.com/Terrence721/coolify-full/commit/23c1489e0c51d5f6e8c96391814f89d3f11dc0a9)) — **no automated test coverage or live Swarm verification**, disclosed on the PR (this job has zero existing tests; no Swarm cluster exists in this dev environment)

`health_check()` was a complete no-op for Docker Swarm — `// Implement healthcheck for swarm` — inherited from the original upstream import. `$newVersionIsHealthy` defaulted `false` and was never set for Swarm deployments, so they got zero application-level health verification (traced all 3 read sites of the flag; none are reachable from the Swarm path with a default `force:false`, so no active failures today, just a missing capability). Suggested fix: poll `docker stack services`/`docker service ps` for replica convergence, reusing the existing healthcheck retry/interval config.

---

### [`Environment.php:97-102`](https://github.com/Terrence721/coolify-full/commit/34648d53fe8f312d50eb707ff9e2bc4e53658a79#commitcomment-194359875)

**medium · Maintainability** — Fixed via [PR #76](https://github.com/Terrence721/coolify-full/pull/76) ([`2ed16466b`](https://github.com/Terrence721/coolify-full/commit/2ed16466b757b47193524545f2400dcff599ea49)) — writing a regression test for this surfaced a much bigger, separate finding: `database/schema/testing-schema.sql` has zero `FOREIGN KEY` constraints anywhere and hasn't been regenerated since the original import, so no FK is enforced in the whole Pest suite. Tracked as [issue #77](https://github.com/Terrence721/coolify-full/issues/77)

`booted()`'s `deleting()` hook looped over `environment_variables()` — a `HasMany` query builder (called with parens), not a `Collection`. `HasMany` isn't `Traversable`, so the loop ran zero iterations, always — confirmed empirically via a live tinker test, not just inspection. Deletion works today purely via a real DB-level `ON DELETE CASCADE` foreign key; this code never protected anything. Suggested fix: remove the dead hook entirely.

---

### [`UpdatePackage.php:49-70`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194372949)

**medium · Reliability** — Fixed via [PR #78](https://github.com/Terrence721/coolify-full/pull/78) ([`e4735ed78`](https://github.com/Terrence721/coolify-full/commit/e4735ed78a4898b96f6acbad4a51b6080c92bafa)) — also fixed a real test-isolation bug the new regression test surfaced: the shared `remote_process()` test fake unconditionally shadowed the real function for every `App\Actions\Server` class for the rest of the test process, breaking `ConfigureCloudflared`'s tests; gated behind an explicit opt-in flag

The package-manager switch handled `zypper`/`dnf`/`apt`/`pacman` but had no `apk` case, inherited from the original upstream import. `CheckUpdates.php` fully detects and parses Alpine (`apk`) updates, and the Patches UI's own tooltip advertises apk as supported — traced end-to-end, `packageManager` passes straight from `CheckUpdates`'s response into `UpdatePackage`, so an Alpine server correctly listed pending updates, then failed every update attempt with "OS not supported". Suggested fix: add the missing `apk` case.

---

### [`StartLogDrain.php:164-171`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194392974)

**medium · Reliability** — Fixed via [PR #79](https://github.com/Terrence721/coolify-full/pull/79) ([`0ee021de3`](https://github.com/Terrence721/coolify-full/commit/0ee021de3d031fefb43705cd220659480dac0a4e))

The README.md written to a server's log-drain config directory was hardcoded to "New Relic Log Drain"/"New Relic Log Forwarder" regardless of `$type` — the block sat outside the type if/elseif chain, inherited from the original upstream import. A server configured for Highlight, Axiom, or a custom drain got a real, incorrect README on disk. Suggested fix: branch the README content by type, matching how `$envContent` already does.

---

### [`InstallPrerequisites.php:25`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194398516)

**high · Reliability** — Fixed via [PR #80](https://github.com/Terrence721/coolify-full/pull/80) ([`a60ba158b`](https://github.com/Terrence721/coolify-full/commit/a60ba158bf5d19960829c4233534f71d77a1052c))

The OS if/elseif chain handled `debian`/`rhel`/`sles`/`arch` but had no `alpine` branch, inherited from the original upstream import — even though `alpine` is one of the 5 `SUPPORTED_OS` entries and `Server::validateOS()` correctly recognizes it. A fresh Alpine server flagged as missing prerequisites by `ValidatePrerequisites` (OS-agnostic) would then hit `InstallPrerequisites`'s `else` branch and throw "Unsupported OS type", contradicting `validateOS()`'s own answer one step earlier — could never be automatically onboarded. Suggested fix: add an `alpine` branch using `apk`, matching `UpdatePackage.php`'s already-correct handling.

---

### [`ValidateServer.php:73`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194406982)

**medium · Reliability** — Fixed via [PR #82](https://github.com/Terrence721/coolify-full/pull/82) ([`a93ae0a33`](https://github.com/Terrence721/coolify-full/commit/a93ae0a33d4140078c3a68c6f41c19ae2eb02fbe))

The final validation branch showed "Docker Engine is not installed" whenever `validateDockerEngineVersion()` failed — but the earlier `docker_installed`/`docker_compose_installed` check had already passed by that point, so Docker genuinely was installed; the version check only fails when it's below the minimum required version. A user on an older Docker version was told to install Docker, not upgrade it. Suggested fix: a distinct message naming the actual minimum version.

---

### [`ValidateAndInstallServerJob.php:153`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194408241) & [`ServerValidationService.php:80`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194408241)

**medium · Reliability** — Fixed via [PR #83](https://github.com/Terrence721/coolify-full/pull/83) ([`2395a8218`](https://github.com/Terrence721/coolify-full/commit/2395a82188490d849fcf62b858152893ccd107e2))

Same wording bug as `ValidateServer.php` above, found while checking whether that fix was complete, in 2 more places. The job version is inherited from the original upstream import; the service version is **not inherited** — written fresh during this fork's own Phase 78 migration, so the wording got copy-pasted into new code rather than questioned.

---

### [`CleanupDocker.php:165`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194422494)

**high · Reliability** — Fixed via [PR #85](https://github.com/Terrence721/coolify-full/pull/85) ([`b9e826e6d`](https://github.com/Terrence721/coolify-full/commit/b9e826e6df2dada60d5015216751f482ad238c06))

`cleanupApplicationImages()` located the currently-running image via `docker inspect` against a container named after the application's bare UUID, inherited from the original upstream import. The real container name almost never matches: `generateApplicationContainerName()` appends a deploy-time timestamp unless `is_consistent_container_name_enabled` is on (off by default), and that timestamp isn't persisted anywhere retrievable afterward. The lookup silently failed, so the "protect the currently running image from deletion" logic never engaged — the in-use image could be deleted like any other old one, risking breakage after a rollback. Suggested fix: look the container up via Coolify's own `coolify.applicationId`/`coolify.pullRequestId` labels instead of guessing its name.

---

### [`IsHorizonQueueEmpty.php:25`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194483106)

**medium · Reliability** — Fixed via [PR #86](https://github.com/Terrence721/coolify-full/pull/86) ([`0266ab5bf`](https://github.com/Terrence721/coolify-full/commit/0266ab5bfcef0f4f7dc76138fd9cb90598509649))

`handle()` filtered Horizon's recent jobs by `in_array('server:'.gethostname(), $tags)`, inherited from the original upstream import. No job in this codebase tags itself that way — `ApplicationDeploymentJob::tags()` (the only `tags()` override that exists) returns `'App\Models\ApplicationDeploymentQueue:<id>'`, a completely different format. The filter could never match any real job, so `handle()` always returned `true` ("queue is empty") regardless of how many jobs were actually running. Currently unreferenced anywhere in `app/`, but a real, exported `AsAction` with its own dedicated test suite — clearly intended as a genuine safety check that would silently give a false "safe to proceed" answer if ever wired up. Suggested fix: drop the hostname/tag scoping entirely (Coolify is single-instance, so it never corresponded to anything real) and just check for any non-completed, non-failed recent job.

---

### [`StartService.php:81`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194489436)

**medium · Reliability** — Fixed via [PR #88](https://github.com/Terrence721/coolify-full/pull/88) ([`3ec01300e`](https://github.com/Terrence721/coolify-full/commit/3ec01300e6fab965cda89c31886b4e32ec26f5f4))

`shouldStopBeforeStarting()` returned `$stopBeforeStart && !$pullLatestImages`, inherited from the original upstream import — a caller passing both `stopBeforeStart: true` and `pullLatestImages: true` got the stop silently cancelled. Reachable via the real, OpenAPI-documented `POST /api/v1/services/{uuid}/restart?latest=true` endpoint (`RestartService` always passes `stopBeforeStart: true`, forwarding the request's `latest` param straight through). `StopService::handle()` is the only place that cancels stale in-progress/queued `Activity` records for a service, so skipping it left a genuinely stuck prior deployment stuck forever after a restart-with-latest-images via the API. Container recreation itself was unaffected (`docker compose up --force-recreate --build` always runs regardless) — this was specifically about the lost activity-cleanup side effect. Suggested fix: honor `stopBeforeStart` unconditionally rather than letting `pullLatestImages` silently override it.

---

### [`DeleteUserTeams.php:27`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194498145)

**high · Reliability** — Fixed via [PR #89](https://github.com/Terrence721/coolify-full/pull/89) ([`850a0d3be`](https://github.com/Terrence721/coolify-full/commit/850a0d3be9cc8aba84d6945d52e2a06a949674ab))

`getTeamsPreview()` read `$this->user->teams`, a dynamic property access Laravel caches on the model instance after the first load, inherited from the original upstream import. `execute()` calls `getTeamsPreview()` again internally as a safety re-check right before its destructive operations (team deletion, ownership transfer, member removal) — but since it's the same `$this->user` object both times, the second call returned the exact same cached snapshot as the first, never re-querying the database. The only real caller, `AdminDeleteUser` (an interactive console command), prints a preview, blocks on a confirmation prompt, then calls `execute()` on the same instance — any team membership/ownership change made during that pause was invisible to the re-check, risking an ownership transfer to someone no longer eligible or deleting a team no longer solely-owned by the user being deleted. Suggested fix: query fresh (`$this->user->teams()->get()`) instead of the cached relation property.

---

### [`AdminDeleteUser.php:100`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194504772)

**high · Reliability** — Fixed via [PR #90](https://github.com/Terrence721/coolify-full/pull/90) ([`711e2daad`](https://github.com/Terrence721/coolify-full/commit/711e2daad5301fcd2d9bcb288fe6dd179f44f0b4))

`Cache::lock($lockKey, 600)` acquires a 10-minute lock to prevent two operators running `admin:delete-user` for the same user concurrently, inherited from the original upstream import, then never refreshes it. Between phases the command blocks on interactive `confirm()`/`ask()` prompts of unbounded human duration — a careful operator reading multi-page deletion previews before confirming something irreversible can easily exceed 10 cumulative minutes. The lock then silently expires while the command is still mid-flight (and its `DB::beginTransaction()` is still open), letting a second operator start a fully concurrent deletion for the same user — exactly what the lock exists to prevent. Suggested fix: refresh the lock's TTL after each completed phase.

---

### [`CleanupDatabase.php:45-49,53-57`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194511253)

**high · Reliability** — Fixed via [PR #91](https://github.com/Terrence721/coolify-full/pull/91) ([`833d06783`](https://github.com/Terrence721/coolify-full/commit/833d06783683e1e0a7048d28856e708b6a759401))

The `activity_log`/`application_deployment_queues` cleanup blocks chained `->orderBy('created_at', 'desc')->skip(10)` before both `->count()` and `->delete()`, inherited from the original upstream import, intending to always keep the 10 most-recent-of-the-old rows as a buffer. Neither worked: `->skip(N)->count()` compiles to a single-row aggregate query with `OFFSET`, which always discards that row and returns 0 regardless of real data; Postgres's `DELETE` grammar only special-cases `->limit()`, never `->offset()`, silently deleting every matching row instead. `cleanup:database --yes` runs **daily** via the scheduler — every day this logged "Delete 0 entries" for both tables while actually deleting everything past the retention window, with no buffer ever enforced. Suggested fix: resolve the ids to keep explicitly and filter via `whereNotIn()` instead of `skip()`.

---

### [`DatabasesController.php:32-46`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194526871)

**critical · Security** — Fixed via [PR #92](https://github.com/Terrence721/coolify-full/pull/92) ([`f535f951a`](https://github.com/Terrence721/coolify-full/commit/f535f951afe28e9e417a7d6f6de941ac0d0a762e))

`removeSensitiveData()` redacted the password field for 6 of 8 database engines but silently omitted **MySQL and MariaDB**, inherited from the original upstream import — `mysql_password`, `mysql_root_password`, `mariadb_password`, and `mariadb_root_password` weren't gated behind `read:sensitive` at all, so any token with plain `read` ability got them back in plaintext on `GET /api/v1/databases` (`/{uuid}`). Confirmed empirically end-to-end: a real `StandaloneMysql` row's `mysql_root_password` came back verbatim in a real API response. The codebase's own `$allowedFields` list for creating/updating a database already includes all 4 fields alongside every other engine's — a copy-paste gap in the redaction list specifically, not an intentional omission. Suggested fix: add the 4 missing fields to the sensitive-hidden list.

---

### [`ServersController.php:29-38`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194543403)

**critical · Security** — Fixed via [PR #94](https://github.com/Terrence721/coolify-full/pull/94) ([`fcb5e8a65`](https://github.com/Terrence721/coolify-full/commit/fcb5e8a65865c49705eda6f084b33eb56f2f7e5f))

`removeSensitiveDataFromSettings()` only hid `sentinel_token`, inherited from the original upstream import — `logdrain_axiom_api_key` and `logdrain_newrelic_license_key`, both real `ServerSetting` columns with no `$hidden` anywhere on the model (and not even `'encrypted'`-cast, unlike `sentinel_token`), leaked to any token with plain `read` ability on `GET /api/v1/servers` (`/{uuid}`). Same bug class as the MySQL/MariaDB leak in `DatabasesController` (PR #92), found by checking its sibling API controllers for the same gap. Confirmed empirically end-to-end. Suggested fix: add both fields to the `makeHidden()` call.

---

### [`StopApplication.php:60-69`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194571403) / [`StopService.php:38-53`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194571403)

**high · Reliability** — Fixed via [PR #95](https://github.com/Terrence721/coolify-full/pull/95) ([`53ad55826`](https://github.com/Terrence721/coolify-full/commit/53ad558265d27787bfcd1d64e816bcaaeaaf4ecb))

`StopApplication::handle()`'s if/else coupled two unrelated concerns, inherited from the original upstream import: the default `resetRestartCount = true` path (web "Stop" button, API stop endpoint) reset restart-count bookkeeping but never set `status` to `exited` — only the crash-loop auto-stop path (which explicitly passes `resetRestartCount: false`) did. `StopService::handle()` had the same gap in every path — it never updated its child `ServiceApplication`/`ServiceDatabase` `status` columns at all. Both `Application::status` and `Service::getStatusAttribute()` are read everywhere the UI/API report resource state, so a stopped resource kept reporting its pre-stop status until the next independent `GetContainersStatus` poll caught up. For Services this wasn't just a stale badge: `ServicesController::action_deploy()`/`action_stop()` gate on this same live status, so a stop followed shortly by a legitimate deploy call could be incorrectly rejected with a 400. Suggested fix: always set `status: exited` after a successful stop in both actions, independent of `resetRestartCount`.

---

### [`StopDatabase.php:29-34`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194595305)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #96](https://github.com/Terrence721/coolify-full/pull/96) ([`37fb0d8ec`](https://github.com/Terrence721/coolify-full/commit/37fb0d8ec1ab110718bedba697fd909babf0a3fb))

Same bug class as `StopApplication.php`/`StopService.php` above — `StopDatabase::handle()` stopped and removed the container, then reset restart-count bookkeeping, but never wrote `status` back to `exited`, inherited from the original upstream import. `status` is a real, persisted column, read live by `DatabasesController::action_deploy()`/`action_stop()`, which gate on it to reject an already-running/already-stopped request. Since nothing corrected the column until the next independent `GetContainersStatus` poll, a stop followed shortly by a legitimate start call could be wrongly rejected with a 400 while the container was, in reality, already stopped. Suggested fix: write `status: exited` alongside the restart-count reset, matching `StopApplication.php`'s already-landed fix.

---

### [`CleanupHelperContainersJob.php:27-75`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194600020)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #97](https://github.com/Terrence721/coolify-full/pull/97) ([`ffd2f7f27`](https://github.com/Terrence721/coolify-full/commit/ffd2f7f2731c1051ede2018f9f514ba1215e8f06))

`handle()`'s only "is this container still needed?" source is `ApplicationDeploymentQueue`, inherited from the original upstream import — it force-removed any `coolify-helper`-image container that wasn't a matching active deployment, including `backup-of-*` (`DatabaseBackupJob`'s S3 upload) and `s3-restore-*` (the S3 restore flow in `ManagesDatabaseImport`), neither of which ever writes to that table. This job runs for every functional server after **any** resource delete anywhere in the instance (`CleanupStuckedResources`, queued from `DeleteResourceJob`'s `finally` block), not scoped to the server being cleaned up — so an unrelated delete on one team's server could kill a backup upload or restore in progress on a completely different team's server, silently marking the backup `s3_uploaded: false` or erroring the restore mid-transfer. Suggested fix: skip `backup-of-*`/`s3-restore-*` containers the same way active-deployment containers are already skipped.

---

### [`ResourcesController.php:43-70`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194603095)

**critical · Security** — Fixed via [PR #98](https://github.com/Terrence721/coolify-full/pull/98) ([`3afb33d49`](https://github.com/Terrence721/coolify-full/commit/3afb33d4993d364c18280f566eef641b0c0afb8a))

`resources()` (`GET /api/v1/resources`, gated only by plain `read` ability) called raw `$resource->toArray()` on every Application/Service/database row on the team, with zero redaction, inherited from the original upstream import — unlike every other controller returning these same model types. No model in this codebase has a `$hidden` property; all redaction is opt-in and per-controller, so this was the one place that skipped it entirely. Any token with only `read` got every application's and database's secrets in the clear for the whole team in one call — `docker_compose_raw`, `manual_webhook_secret_*`, `http_basic_auth_password` on Applications; every engine's real password columns on databases. A broader version of the exact bug class already fixed twice (`DatabasesController` PR #92, `ServersController` PR #94) — here it's zero redaction across every resource type at once, not a couple of missing fields in a list. Suggested fix: apply the same per-type redaction the dedicated controllers already use, dispatched on `$resource->type()`.

---

### [`DeployController.php:681-714`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194606479)

**critical · Security** — Fixed via [PR #99](https://github.com/Terrence721/coolify-full/pull/99) ([`c90bd0be5`](https://github.com/Terrence721/coolify-full/commit/c90bd0be5719e8a56e56015059980b9e1e1678b5))

`get_application_deployments()` returned raw `ApplicationDeploymentQueue` rows with zero redaction, inherited from the original upstream import — unlike its two siblings in the same controller (`deployments()`, `deployment_by_uuid()`), which both route through `removeSensitiveData()` to hide the `logs` column unless the token carries `read:sensitive`. All 3 routes share the identical `api.ability:read` gate, so there's no ability difference explaining the exemption — this endpoint just forgot to call the controller's own already-correct redaction helper. `logs` is real build/deploy console output written during deployment by `ExecuteRemoteCommand` — it routinely contains printed env values, git URLs with embedded access tokens, and registry auth output, exactly why the other two endpoints already gate it. Any token with only `read` could pull every one of an application's deployment logs verbatim. Same bug class as findings already fixed 3 times (`DatabasesController` PR #92, `ServersController` PR #94, `ResourcesController` PR #98), but a new instance: a forgotten call to existing redaction, not a gap in a field list. Suggested fix: route the response through `removeSensitiveData()` the same way the other two endpoints do.

---

### [`ApplicationDeploymentController.php:75,142,167,237`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194610985)

**critical · Security** — Fixed via [PR #100](https://github.com/Terrence721/coolify-full/pull/100) ([`cd8f54235`](https://github.com/Terrence721/coolify-full/commit/cd8f54235))

`show()`, `forceStart()`, `cancel()`, and `downloadAllLogs()` all correctly resolve `$application` scoped to the caller's own team via `resolveApplication()`, but each then independently looked up its `ApplicationDeploymentQueue` by `deployment_uuid` alone — a globally-unique column with no ownership check against the resolved application. `show()`/`downloadAllLogs()` pass the deployment to `decode_remote_command_output()`, which re-derives the application from `$deployment->application_id` directly, ignoring the route's team-scoped application entirely — so a caller could view another team's real deployment console output, the same exposure class fixed in `DeployController` PR #99. `cancel()` would issue a real `docker rm -f`/`kill -9` over SSH against the foreign deployment's server (a cross-tenant DoS with an actual remote side effect), and `forceStart()` would dispatch `ApplicationDeploymentJob` against the foreign deployment. An attacker only needed their own project/environment/application URL segments plus another team's `deployment_uuid`. Same bug class as the API redaction gaps already fixed 4 times, but a new shape: a missing ownership check on a secondary lookup inside an otherwise-correctly-scoped web controller. Fix: scope each lookup with `->where('application_id', $application->id)`.

---

### [`StopApplication.php:20-79`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194613279)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #101](https://github.com/Terrence721/coolify-full/pull/101) ([`2fa1e6f47`](https://github.com/Terrence721/coolify-full/commit/2fa1e6f47))

`handle()` writes the main `status` column to `exited` after stopping every server, but for multi-server applications (`additional_servers`) never touches the `additional_destinations` pivot's own `status` column. `Application::status()`'s getter compares the main status against each additional server's pivot status and reports `"degraded:*"` on any mismatch — the only writer of that pivot column is `ComplexStatusCheck`, invoked from `GetContainersStatus`, which `StopApplication` never calls. Consequence: stopping a multi-server application leaves it visibly reporting `degraded:unhealthy` in both the UI and `GET /api/v1/applications/{uuid}` until an unrelated poll happens to correct it, even though every server is actually stopped. Same bug lineage as the status-write gaps already fixed 3 times for `StopApplication`/`StopService`/`StopDatabase` (PR #95/#96), but those only covered the top-level `status` column — this is the multi-server/pivot dimension of the same accessor, left uncovered. Fix: call `ComplexStatusCheck::run($application)` when the application has additional servers, right after the existing status write.

---

### [`SafeWebhookUrl.php` + `SendWebhookJob.php:72`, `SendMessageToDiscordJob.php:46`, `SendMessageToSlackJob.php:69,111`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194617711)

**critical · Security (SSRF)** — Fixed via [PR #102](https://github.com/Terrence721/coolify-full/pull/102) ([`1296f2cc8`](https://github.com/Terrence721/coolify-full/commit/1296f2cc8))

`SafeWebhookUrl` blocks loopback/link-local hosts to "prevent SSRF to cloud metadata endpoints" — but only when the configured host string is itself a literal IP; it never resolves hostnames before checking. None of the three outbound send paths (`SendWebhookJob`, `SendMessageToDiscordJob`, `SendMessageToSlackJob`'s Slack and Mattermost branches) disabled redirect-following on their `Http::post()` calls, so Guzzle's default (follow up to 5 redirects) applied. Consequence: a team member with only notification-settings access can set a webhook URL to a domain they control that passes validation, then 302-redirects to `169.254.169.254` (cloud instance metadata) or an internal address at send time — the server follows it, issuing a blind SSRF POST with attacker-influenced payload, fully bypassing the class's own documented protection. Directly exploitable via each notification page's existing "Send Test" button, no wait for a real event. Fix: `Http::withoutRedirecting()` on all 4 call sites. DNS rebinding (a hostname resolving safely at validation time, unsafely at request time) is a separate, larger problem not addressed here — would need IP-pinning via a custom resolver.

---

### [`PushServerUpdateJob.php:351` (`aggregateMultiContainerStatuses()`)](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194633661)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #105](https://github.com/Terrence721/coolify-full/pull/105) ([`eb6676fe7`](https://github.com/Terrence721/coolify-full/commit/eb6676fe7))

`aggregateMultiContainerStatuses()` writes to an application's main `status` column unconditionally for every server it processes — but `loadApplications()` deliberately loads a multi-server application even when `$this->server` is only one of its `additional_servers`, not the main destination. Consequence: a routine Sentinel heartbeat push from a non-main server silently overwrites the main `status` column with that additional server's own per-container status, clobbering whatever the main server had just correctly reported and breaking the "degraded" comparison `Application::status()`'s accessor depends on. One method earlier in the exact same job, `updateAdditionalServersStatus()` → `ComplexStatusCheck`, already correctly splits main-vs-additional writes (main column for the main server, `additional_destinations` pivot row per additional server — the same fix already landed for `StopApplication.php`, PR #101) — `aggregateMultiContainerStatuses()` runs right after it and undoes that separation. Fix: check whether `$this->server` is actually the application's main destination (using data the job already caches) before writing; if not, write to that server's own pivot row instead.

---

### [`DeleteService.php:21-24` + `ServiceApplication.php:128-132`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194635772)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #106](https://github.com/Terrence721/coolify-full/pull/106) ([`44bdd3219`](https://github.com/Terrence721/coolify-full/commit/44bdd3219))

`DeleteService::handle()` only cleaned up the Service's own `environment_variables()` inside the `$deleteVolumes && $server->isFunctional()` guard, while the Service row itself is force-deleted unconditionally in the `finally` block. Consequence: deleting a service with "delete volumes" unchecked, or when its server happens to be unreachable at delete time (regardless of the checkbox), silently skips cleanup — since the relation is a polymorphic `MorphMany` with no DB foreign key, nothing else cascades it, and no other job anywhere purges orphaned rows. These commonly hold real secrets (DB passwords, API keys). Separately, `ServiceApplication`'s `deleting` hook already cleans up `persistentStorages()`/`fileStorages()` but had no equivalent for its own `environment_variables()` — always orphaned, every delete. Contrast with the sibling non-service path in `DeleteResourceJob.php:80`, which deletes `environment_variables()` unconditionally — confirming this is a real inconsistency, not intended behavior. `ServiceDatabase` has no `environment_variables()` relation at all, so nothing to fix there. Fix: the Service's own cleanup now runs unconditionally; `ServiceApplication`'s `deleting` hook cleans up its own env vars the same way it already does for storages.

---

### [`EnvironmentController.php:298-379`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194673104)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #107](https://github.com/Terrence721/coolify-full/pull/107) ([`c5dcd0d59`](https://github.com/Terrence721/coolify-full/commit/c5dcd0d59))

Cloning a Service via `EnvironmentController::clone()` replicated the Service row and its `docker_compose_raw`, but the loops meant to copy each application/database's volume data (`clone_volume_data=true`) and replicate each database's `scheduledBackups` ran **before** `$newService->parse()` was called. `parse()` is the sole creator of a Service's `ServiceApplication`/`ServiceDatabase` child rows (it dispatches to `serviceParser()`, which builds them from `docker_compose_raw`) — nothing else creates them. So at the point those loops ran, `$newService->applications` and `$newService->databases` were always empty, making the loops permanent no-ops: `VolumeCloneJob` never dispatched for a cloned service's app/db volumes regardless of the `clone_volume_data` checkbox, and scheduled backups were silently dropped on every service clone. The equivalent standalone-database clone path in the same file already does this correctly (replicate → the resource exists → then copy volumes/backups) — that's the pattern this fix now matches.

Debugging this took a couple of wrong turns before landing on the real fix. A real end-to-end test (POST to `project.clone-me.store` against a service built from a real, parseable `docker_compose_raw`) surfaced an unrelated `NotFoundHttpException` (404); temporary checkpoints across the controller ruled out the reordered loops or a routing/auth problem, since none of them ever fired — the failure was happening before the controller even ran. Wrapping the test fixture's own `$service->parse()` call in try/catch (building the *source* service, not the clone) exposed the real cause: `serviceParser()` unconditionally dispatches `ServerFilesFromServerJob` per volume, which under the `sync` queue driver runs synchronously and makes a real SSH call against a server that doesn't exist in tests. An initial guess that this was bind-mount-specific was also wrong (the dispatch fires for every volume type); the actual fix was adding `Bus::fake()` to the one new test missing it — pre-existing, unrelated `serviceParser()` behavior, not something this change introduced. Fix: call `$newService->parse()` immediately after replicating `environment_variables`, then match each source app/database to its newly-parsed counterpart by `name` before copying volume data or replicating scheduled backups.

---

### [`StopApplication.php` / `StopDatabase.php` / `StopService.php` + `Server.php` (`findCached()`)](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194677103)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #108](https://github.com/Terrence721/coolify-full/pull/108) ([`f23619f0c`](https://github.com/Terrence721/coolify-full/commit/f23619f0c))

All three "stop" actions read `$destination->server` with no null guard before calling `$server->isFunctional()`. When a server is deleted via "delete all resources," the controller queues `DeleteResourceJob` for every resource, then soft-deletes the `Server` row **synchronously**, then queues `DeleteServer` to hard-delete it later. `DeleteResourceJob` runs asynchronously — typically in a separate queue-worker process — so by the time it calls these actions, the plain `belongsTo(Server::class)` lookup resolves to `null` (soft-deleted rows are excluded by default). `$server->isFunctional()` on `null` throws an uncaught `Error`, not caught by these actions' `catch (\Exception $e)` blocks; it propagates to `DeleteResourceJob`'s outer `catch (\Throwable $e)` — logged and rethrown — but its `finally` block **unconditionally** force-deletes the resource anyway. Net effect: the DB rows disappear cleanly while the real Docker containers on the remote machine are never stopped or removed — orphaned indefinitely, with no way to manage them through the UI again. Reachable via both the web "delete all resources" flow and `DELETE /servers/{uuid}?force=true`. Fix: each action falls back to a trashed-inclusive lookup (`$destination->server()->withTrashed()->first()`) instead of crashing on `null`.

A second, distinct bug surfaced while tracing this: `StandaloneDocker`/`SwarmDocker`'s `getServerAttribute()` accessor routes through `Server::findCached()`, a static identity-map cache invalidated on the `updated` model event — but `SoftDeletes` writes `deleted_at` via a direct query-builder update that never fires `updated`. Within a single process that had already cached a Server, a subsequent delete never invalidated the cache, so the accessor kept serving the stale, pre-delete object — the opposite failure mode (silently stale data instead of a crash). Fixed by also flushing the cache on the `deleted` event.

---

### [`routes/web.php:688-711`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194679882) (`download.backup` route closure)

**critical · Security (IDOR / cross-tenant data exfiltration)** — Fixed via [PR #109](https://github.com/Terrence721/coolify-full/pull/109) ([`693a48db0`](https://github.com/Terrence721/coolify-full/commit/693a48db0))

The `download.backup` route only enforced `$team->id === $execution_team_id` when the caller's currently-selected team wasn't team 0 (`if ($team->id !== 0) { ...scoping... }`). Team 0 is the root team created for the first registered user — an ordinary, real team on any self-hosted multi-team instance, not exclusive to a formally-privileged "instance admin" role. `User::isAdminFromSession()` returns true for anyone who is admin/owner of team 0, **regardless of which team is currently selected** — so any such user, after simply switching their session's current team to team 0 (a normal UI action, not a privilege escalation itself), could fetch `/download/backup/{executionId}` for any other team's backup execution. `executionId` is a bare, sequential auto-increment integer with no relation to the caller — trivially enumerable, and a real `uuid` column already exists on this exact model but isn't used here. Checked whether "team 0 = instance admin" is an established convention elsewhere — it is, but always scoped strictly to instance-level settings, never to reading another team's actual resources; every other resource-access controller (including the sibling backup-*upload* route) scopes strictly by team with no exception. Fix: removed the `$team->id !== 0` exemption entirely — team ownership is now enforced unconditionally.

---

### [`NotificationPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194681783)

**high · Security (broken access control / privilege escalation)** — Fixed via [PR #110](https://github.com/Terrence721/coolify-full/pull/110) ([`99fbf214e`](https://github.com/Terrence721/coolify-full/commit/99fbf214e))

Every method in this shared policy (registered for all 6 notification-settings models: Email, Discord, Telegram, Slack, Pushover, Webhook) had its real check commented out and unconditionally `return true`. `update()`'s own comment made the intended design explicit — `// Only owners and admins can update notification settings` / `// return $user->isAdmin() || $user->isOwner();` — then `return true;` anyway. All 6 controllers derive `$settings` from `currentTeam()`, never an attacker-controllable ID, so this isn't a cross-team IDOR — it's a real intra-team privilege escalation: any team **member** (the lowest role) could view already-configured secrets (SMTP password, Resend API key, Telegram bot token, webhook URLs), overwrite them (e.g. silently redirecting deployment/backup notifications to an attacker-controlled endpoint), and trigger `sendTest` using the team's stored credentials. Confirmed inherited from the original upstream import via `git log --follow`. Fix: restored the intended checks — `view()` requires team membership, `update()`/`manage()`/`sendTest()` require admin or owner.

---

### [17 Policy classes](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194684452) (`ApplicationPolicy.php`, `DatabasePolicy.php`, `ServerPolicy.php`, `ProjectPolicy.php`, `EnvironmentPolicy.php`, `ServicePolicy.php`, and 11 more)

**critical · Security (broken access control / privilege escalation, codebase-wide)** — Fixed via [PR #111](https://github.com/Terrence721/coolify-full/pull/111) ([`b68ab04e3`](https://github.com/Terrence721/coolify-full/commit/b68ab04e3))

Discovered by specifically re-checking every other Policy class for the same disabled-check pattern once `NotificationPolicy.php` proved it real — nearly every Policy class in the app had the identical bug: real admin/owner-only checks commented out, unconditionally allowed everyone. Every controller call site checked resolves the target model scoped to `currentTeam()` before calling `authorize()`, so this was never a cross-team IDOR — it was a real, live intra-team privilege escalation across almost the entire resource layer: any team **member** could update/delete/deploy applications, databases, servers, projects, environments, and services; manage GitHub Apps, S3 storages, and shared environment variables; and self-issue API tokens with full write access. Confirmed inherited from the original upstream import via `git log --follow`.

Two real, distinct bugs surfaced while restoring the original commented-out code literally: `ApplicationPreviewPolicy::update()`/`DatabasePolicy::update()`'s commented bodies returned `Response` objects from a method declared `: bool` (a type error) — fixed by changing both signatures to `: Response`, matching `ApplicationPolicy::update()`'s already-correct pattern. Several restored checks called `->team()->first()->id`, but `team()` on `Application`/`StandaloneDatabaseInstance` returns a plain nullable `Team` object via `data_get()`, not a query builder — fixed to `->team()?->id`.

A fourth, separate instance of the same bug class was found but not fixed in this pass: `App\Http\Middleware\CanUpdateResource` has `return $next($request);` as its literal first statement, making ~40 lines of real dispatch-based authorization dead code. Flagged as a separate follow-up, not folded into this already-large fix — fixed later via [PR #113](https://github.com/Terrence721/coolify-full/pull/113), see below.

Fix: restored each policy method's original commented-out check.

---

### [`CanCreateResources.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194705160)

**critical · Security (broken access control / privilege escalation)** — Fixed via [PR #112](https://github.com/Terrence721/coolify-full/pull/112) ([`33e724cd4`](https://github.com/Terrence721/coolify-full/commit/33e724cd4))

`CanCreateResources::handle()` had `return $next($request);` as its literal first statement, making the real `Gate::allows('createAnyResource')` check permanently dead — the same shape as the already-flagged, then-not-yet-fixed `CanUpdateResource.php` bug (see below), but in a sibling middleware class. Found by specifically re-checking every other middleware for the same pattern once it proved real once. This middleware is the sole enforcement point for 13 routes (resource creation, environment clone) — none of the target controllers has an independent `authorize()` call. Any authenticated team member, not just admin/owner, could create Applications/Services/Databases/GithubApps or clone environments. Confirmed inherited from the original upstream import. Fix: restored the commented-out check.

---

### [`CanUpdateResource.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194706692)

**critical · Security (broken access control)** — Fixed via [PR #113](https://github.com/Terrence721/coolify-full/pull/113) ([`c9c195f4c`](https://github.com/Terrence721/coolify-full/commit/c9c195f4cad4bc6452c82b7832102720935aa176))

`CanUpdateResource::handle()` had `return $next($request);` as its literal first statement, making ~40 lines of real dispatch-based `Gate::allows('update', $resource)` authorization dead code across all 23 routes that use this middleware, across 6 controllers. The real, exploitable gap: `importRunEndpoint`/`importRestoreS3Endpoint`/`importCheckFileEndpoint`/`importCheckS3Endpoint` on `ProjectDatabaseConfigurationController` and `ProjectServiceResourceController` have no authorization of their own at all — unlike `updateHealthcheck()`/`toggleHealthcheck()` on the same controllers, which each authorize independently. This middleware was the only thing standing between a plain team member and a destructive live-database restore. `ProjectController::edit()`/`EnvironmentController::edit()` had a minor, non-exploitable view-only leak (their `update()` siblings already authorize independently). `ServerSecurityPatchesController`/`ServerSecurityTerminalAccessController` were never actually at risk — every method there already authorizes independently. Confirmed inherited from the original upstream import. Fix: restored the original disabled dispatch logic.

---

### [`EnvironmentVariablePolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194707916)

**critical · Security (broken access control / privilege escalation)** — Fixed via [PR #114](https://github.com/Terrence721/coolify-full/pull/114) ([`8ac017ad7`](https://github.com/Terrence721/coolify-full/commit/8ac017ad7))

Every method unconditionally `return true;` — unfilled boilerplate inherited from the original upstream import, unlike the disabled-but-once-real checks fixed in #111/#112/#113. Found by directly checking the 6 Policy classes PR #111's 17-class sweep hadn't touched (`CloudInitScriptPolicy`, `CloudProviderTokenPolicy`, `EnvironmentVariablePolicy`, `InstanceSettingsPolicy`, `PrivateKeyPolicy`, `TeamPolicy`) — 5 of the 6 turned out clean, this one was the live gap. `ManagesResourceEnvironmentVariables::envUpdate()`/`envLock()`/`envDestroy()` (9 routes across the Application/Service/Database configuration controllers) authorize against this policy, so those calls were a complete no-op — unlike `envStore()`/`envBulkUpdate()`, which correctly gate on the resource's own admin-only `manageEnvironment` ability, a plain team member could still edit the value of, lock, or delete any existing environment variable on any resource in their team, including production credentials. Not a cross-team IDOR — every controller call site scopes to `currentTeam()` first — a live intra-team privilege escalation. Fix: `update()`/`delete()`/`restore()`/`forceDelete()`/`manageEnvironment()` now resolve the variable's owning resource via its polymorphic `resourceable` relation and defer to that resource's own `manageEnvironment` ability, closing the inconsistency with `envStore()`/`envBulkUpdate()`.

---

### [`RestartDatabase.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194759912)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #115](https://github.com/Terrence721/coolify-full/pull/115) ([`22ebeb20d`](https://github.com/Terrence721/coolify-full/commit/22ebeb20d))

`handle()` read `$database->destination->server` and called `->isFunctional()` on it with no null check — `destination.server` resolves to `null` once the backing `Server` row is soft-deleted (e.g. mid-flight during a "delete server + delete all resources" request, before the queued cleanup job runs), since the default `belongsTo` query excludes trashed rows. Crashed with an uncaught `Error` instead of the graceful `'Server is not functional'` message the not-functional branch already returns. Same root shape as the `StopApplication`/`StopDatabase`/`StopService` null-server crash fixed via PR #108, but this sibling file — which duplicates the same read one line before ever delegating to the now-fixed `StopDatabase` — was never touched by that fix. Confirmed inherited verbatim from the original upstream import. PHPStan (level 6) doesn't catch this — verified directly against the pre-fix code — since `data_get()` returns `mixed` and level 6 doesn't flag method calls on `mixed`; found instead by comparing this file against its already-fixed siblings for the same pattern. Fix: guarded with `instanceof Server`, matching the identical pattern already used by the sibling `StartDatabase.php` this action calls right after.

---

### [`RegenerateSslCertJob.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194765986)

**high · Reliability (Core-Lifecycle — SSL renewal, instance-wide blast radius)** — Fixed via [PR #117](https://github.com/Terrence721/coolify-full/pull/117) ([`87a4df065`](https://github.com/Terrence721/coolify-full/commit/87a4df065))

The per-certificate loop only caught `\Exception`, but `$certificate->server` resolves to `null` once the backing `Server` row is soft-deleted (default `belongsTo` excludes trashed rows) — calling `->sslCertificates()` on that `null` throws `\Error`, not `\Exception`, so the catch never fired. This job runs `twiceDaily()` **instance-wide with no `server_id` filter**, so one soft-deleted server anywhere in the instance with a certificate due for renewal crashed the entire job, silently halting SSL renewal checks for every team on every run, with no `failed()` handler to surface it anywhere beyond Horizon's failed-jobs list. Same root shape as the `StopApplication`/`StopDatabase`/`StopService`/`RestartDatabase` null-server crashes already fixed, just missing the right catch type instead of missing a guard entirely. Confirmed inherited verbatim from the original upstream import. Fix: widened `catch (\Exception $e)` to `catch (\Throwable $e)`, matching the pattern already used elsewhere in this codebase for exactly this class of crash.

---

### [`StopApplicationOneServer.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194772108)

**high · Reliability (Core-Lifecycle)** — Fixed via [PR #118](https://github.com/Terrence721/coolify-full/pull/118) ([`66ff377aa`](https://github.com/Terrence721/coolify-full/commit/66ff377aa))

`handle()` read `$application->destination->server` and called `->isSwarm()` on it with no null check, outside the method's own `try`/`catch` — `destination.server` resolves to `null` once the destination's server has been soft-deleted, crashing with an uncaught `Error` instead of the graceful `'Server is not functional'` message the very next check already produces for the passed-in `$server`. Same root shape as the `StopApplication`/`StopDatabase`/`StopService`/`RestartDatabase`/`RegenerateSslCertJob` null-server crashes already fixed — `StopApplication.php` itself already has the exact fallback for this same read, this sibling file didn't. Reachable via `ProjectApplicationConfigurationController::serversStop()`/`serversRemove()` when a multi-server application's main destination server gets soft-deleted while a request targeting one of its additional servers races in. Confirmed inherited verbatim from the original upstream import. Fix: mirrors `StopApplication.php`'s established trashed-inclusive-lookup pattern.

---

### [`ServersController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194782953)

**critical · Security (credential exposure)** — Fixed via [PR #119](https://github.com/Terrence721/coolify-full/pull/119) ([`be840c3b7`](https://github.com/Terrence721/coolify-full/commit/be840c3b7)) — found via an independent `/code-review` pass (pseudo peer review), not this session's own discovery process; see `todo.md`'s "Independent verification review (pseudo peer review)" section.

`removeSensitiveDataFromSettings()`'s `makeHidden()` list redacted `sentinel_token`/`logdrain_axiom_api_key`/`logdrain_newrelic_license_key` (PR #94's original fix) but missed `logdrain_custom_config`/`logdrain_custom_config_parser` — two real `ServerSetting` columns with no encryption cast. `StartLogDrain.php` confirms `logdrain_custom_config` holds the raw Fluent Bit output config a user pastes in for a custom log drain, which routinely embeds a real `Authorization Bearer <token>` header — the MCP layer already independently treats these same two fields as sensitive. Reachable via `GET /api/v1/servers`/`GET /api/v1/servers/{uuid}`, both gated behind plain `read` ability — the same credential-exposure class PR #94 claimed to close, one field short. Fix: added both fields to the `makeHidden()` list.

---

### [`DeployController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194789412)

**high · Reliability (API)** — Fixed via [PR #121](https://github.com/Terrence721/coolify-full/pull/121) ([`64b46a16f`](https://github.com/Terrence721/coolify-full/commit/64b46a16f)) — found via an independent `/code-review` pass (pseudo peer review) on PR #99, while reviewing the whole function rather than just its diff.

`Application::deployments(int $skip = 0, int $take = 10, ...)` is strictly typed, but the controller read `$request->get('skip', 0)`/`$request->get('take', 10)` with no cast — query-string values are always strings when present, and PHP doesn't coerce a numeric string to `int` under `strict_types`. Only the no-params default case worked; any client that actually used pagination got an uncaught `TypeError` → 500. Reachable via `GET /api/v1/deployments/applications/{uuid}?skip=X&take=Y`. Confirmed empirically via a real HTTP request (a `tinker` call alone doesn't reproduce this, since `strict_types` is determined by the calling file, not the callee). Pre-existing bug in the unchanged parts of the function PR #99 touched. Fix: cast both values to `int` at the call site.

---

### [`StopDatabaseProxy.php`, `StartDatabaseProxy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194796468)

**medium · Reliability (null-server crash)** — Fixed via [PR #122](https://github.com/Terrence721/coolify-full/pull/122) ([`b9028732c`](https://github.com/Terrence721/coolify-full/commit/b9028732c))

Both actions read the target server via `data_get($database, 'destination.server')` (or the `ServiceDatabase` equivalent) with no null check, then passed it straight into `instant_remote_process()`, which requires a non-nullable `Server`. `destination.server` resolves to `null` once the backing server has been soft-deleted, since the default `belongsTo` excludes trashed rows, crashing with an uncaught `TypeError`. Reachable via `ManagesDatabaseGeneralForm::updateDatabaseProxy()` and `ProjectServiceResourceController::updateDatabasePublic()`, both gated only by team-scoped authorization with no server-functional check. Same root shape as the `StopApplication`/`StopDatabase`/`StopService`/`RestartDatabase`/`RegenerateSslCertJob`/`StopApplicationOneServer` null-server crashes already fixed this session, just in two sibling files that pattern never reached. Confirmed inherited verbatim from the original upstream import. Fix: guarded both with `instanceof Server`, matching the established pattern. `StopDatabaseProxy.php` had zero prior test coverage — added a new test file.

---

### [`TeamController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194803183)

**medium · Reliability (unguarded enum coercion on a missing pivot row)** — Fixed via [PR #124](https://github.com/Terrence721/coolify-full/pull/124) ([`89cf9680d`](https://github.com/Terrence721/coolify-full/commit/89cf9680d))

Both `updateMemberRole()` and `removeMember()` resolve the target with `User::findOrFail($member_id)` — unscoped to the current team — then compute the member's role via a pivot-table lookup and feed it directly into `Role::from()`, a backed string enum with no case for an empty string. When `$member_id` is a valid user who isn't a member of the *current* team, the pivot lookup returns `null`, `(string) null` becomes `''`, and `Role::from('')` throws an uncaught `ValueError` → 500, instead of any graceful "not a member" response. Reachable via `PUT /team/members/{member_id}/role` and `DELETE /team/members/{member_id}`, gated by `auth`/`verified` and `TeamPolicy::manageMembers` — any team admin/owner can hit these with an arbitrary `member_id`. Not inherited from upstream — original code written during this fork's own Livewire→React migration. No existing test exercised the not-a-member case. Fix: check for a missing pivot row before coercing to `Role`, return a normal flash-error response instead of crashing.

---

### [`OauthController.php`, `bootstrap/helpers/socialite.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194831511)

**critical · Security (broken authentication / account takeover)** — Fixed via [PR #127](https://github.com/Terrence721/coolify-full/pull/127) ([`4d23c10f9`](https://github.com/Terrence721/coolify-full/commit/4d23c10f9))

`OauthController::callback()` resolved the target user via `User::whereEmail($email)->first()` and logged the browser straight in with no check that this specific OAuth identity had ever been associated with that account — the `users` table has no `provider`/`provider_id` column at all, so login-by-email was the entire trust boundary. Reachable via `GET /auth/{provider}/callback`, fully public and unauthenticated. Concrete exploit: an instance admin enables a self-hosted or admin-configured OAuth provider (GitLab, Authentik, Zitadel, etc.); an attacker registers on that external IdP using a victim's known Coolify email — several supported providers don't guarantee the registering user actually owns/verified that mailbox — completes the OAuth flow, and is logged in as the victim, no password needed. Compounding bug in the same flow: `get_socialite_provider()` never checked `OauthSetting::enabled`, so a provider an admin explicitly disabled still worked end-to-end via a direct request. Confirmed inherited verbatim from the original upstream import for the core email-match logic. Fix: added a nullable `oauth_provider` column recording which provider originally created each account — an OAuth login now only matches an existing user when it's the same provider that created it, rejecting a password-only account or one created via a different provider instead of silently authenticating. Also added the missing `enabled` check.

---

### [`ResourcesController.php`, `ApplicationsController.php`, `ServicesController.php`, `DatabasesController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194835637)

**medium · Maintainability (DRY violation with real security consequences if it drifts)** — Fixed via [PR #129](https://github.com/Terrence721/coolify-full/pull/129) ([`8eed8b36a`](https://github.com/Terrence721/coolify-full/commit/8eed8b36a))

`ResourcesController::removeSensitiveData()` hardcoded its own copy of each resource type's always-hidden/sensitive-hidden field lists, instead of sourcing them from the same place `ApplicationsController`, `ServicesController`, and `DatabasesController` already use — the same drift class already fixed twice this session (PR #92, #94), a fourth, unlinked instance of it. Confirmed the lists are byte-for-byte identical to the sibling controllers' lists as of right now — no active leak today, but a future field added to one controller's list could easily be forgotten in this fourth copy, since nothing tied the two together. Found via an independent `/code-review` pass (pseudo peer review) on already-merged PR #98. Fix: extracted each controller's field lists into a new `public static function sensitiveFieldLists(): array` on that controller — the single source of truth both that controller's own `removeSensitiveData()` and `ResourcesController` now pull from directly, so they can no longer independently drift.

---

### [`ProjectDatabaseBackupController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194837481)

**medium · Security (broken access control)** — Fixed via [PR #130](https://github.com/Terrence721/coolify-full/pull/130) ([`6bb572f72`](https://github.com/Terrence721/coolify-full/commit/6bb572f72))

`cleanupFailedExecutions()` and `cleanupDeletedExecutions()` scoped the target database only to `currentTeam()`, not role, then permanently deleted `ScheduledDatabaseBackupExecution` rows with no `authorize('manageBackups', ...)` call — unlike every other mutating method in this same controller. Reachable via `POST .../backups/{backup_uuid}/cleanup-failed` and `.../cleanup-deleted`, gated only by `auth`/`verified`. Any authenticated team member, not just admin/owner, could permanently delete a standalone database's backup failure/audit history. Confirmed by contrast: the sibling controller `ProjectServiceDatabaseBackupController` implements the same two methods correctly, with the `authorize()` call present in the same position on both. Not inherited from upstream — written during this fork's own Livewire→React migration. Fix: added the missing `authorize('manageBackups', $database)` call to both methods, matching the sibling controller's pattern.

---

### [`SendMessageToSlackJob.php`, `SendMessageToDiscordJob.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194841477)

**medium · Security (SSRF)** — Fixed via [PR #131](https://github.com/Terrence721/coolify-full/pull/131) ([`5d32b1eb2`](https://github.com/Terrence721/coolify-full/commit/5d32b1eb2))

`SendWebhookJob` re-validates its webhook URL with `SafeWebhookUrl` at send time, in addition to the save-time check in its controller. `SendMessageToSlackJob`/`SendMessageToDiscordJob` never did — `SafeWebhookUrl` was only wired into `NotificationsSlackController`/`NotificationsDiscordController` at save time, not into either job's `handle()`. A webhook URL that resolves to a safe address when saved but gets repointed by DNS before the notification actually fires would go straight through with zero send-time check — PR #102's `withoutRedirecting()` fix doesn't help here, since there's no redirect involved, just a direct connection to whatever the URL now resolves to. Found via an independent `/code-review 102` pass (pseudo peer review) on already-merged PR #102, which closed the redirect-following bypass but left this adjacent gap in the two jobs it didn't cover as thoroughly as `SendWebhookJob`. Fix: added the same `Validator::make([...], ['webhook_url' => ['required', 'url', new SafeWebhookUrl]])` re-validation to both jobs' `handle()`, matching `SendWebhookJob`'s existing pattern. First of two related findings from the same review pass — a second fix (extracting the now-3x-duplicated validate-and-send pattern into one shared place) is tracked separately.

---

### [`SendWebhookJob.php`, `SendMessageToSlackJob.php`, `SendMessageToDiscordJob.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194842435)

**medium · Maintainability (DRY violation with real security consequences if it drifts)** — Fixed via [PR #132](https://github.com/Terrence721/coolify-full/pull/132) ([`e435dd8f3`](https://github.com/Terrence721/coolify-full/commit/e435dd8f3))

The `SafeWebhookUrl` re-validation + `Http::withoutRedirecting()` pattern was hand-copied at 4 separate call sites across the three outbound webhook jobs, with nothing structurally enforcing either protection — the same architectural theme already seen twice this session (findings #40 and #45), and exactly the shape of drift that let the redirect-following bypass (PR #102) and finding #47's missing send-time validation both go unnoticed for as long as they did. Found via the same independent `/code-review 102` pass as finding #47 — second of two related fixes from that review. Fix: extracted a new `App\Jobs\Concerns\SendsSafeWebhookRequests` trait, used by all 3 jobs — `isSafeWebhookUrl()` (validation + warning log) and `sendWebhookRequest()` (the `withoutRedirecting()` call). Pure refactor, no behavior change — every pre-existing test in the 3 job test files passes unchanged.

---

### [`SafeWebhookUrl.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194844916)

**medium · Security (SSRF)** — Fixed via [PR #133](https://github.com/Terrence721/coolify-full/pull/133) ([`78cee6328`](https://github.com/Terrence721/coolify-full/commit/78cee6328))

`SafeWebhookUrl` only inspected the host when it was a literal IP — a hostname was never resolved. A webhook URL pointing at an attacker-controlled domain that resolves directly to `169.254.169.254` (cloud metadata) or an internal address passed validation unconditionally, at both save time and every job's send-time re-validation (findings #47/#131 had just added that re-validation to all 3 outbound webhook jobs, but it still used this same under-checking rule). Last of three fixes from the same independent `/code-review 102` pass — PR #102's own commit message already disclosed this exact limitation. Fix: the rule now resolves a hostname via `dns_get_record()` and checks every returned A/AAAA address against the same loopback/link-local checks already applied to literal IPs. **Disclosed residual gap, not hidden**: closes the common case (a hostname resolving directly to a blocked address) but not classic DNS-rebinding TOCTOU — closing that fully needs connection-level IP pinning, documented directly in the rule's own docblock. New `SafeWebhookUrl::resolveHostUsing()` static override lets tests inject a fake resolver instead of hitting real DNS — 6 existing test files that submit real-world hostnames through this rule were stubbed so none of them started silently making real DNS calls, keeping the suite network-independent. This closes out all three fixes from the `/code-review 102` review pass (findings #47, #48, #49).

---

### [`SourceGithubController.php`, `GithubAppPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194897674)

**critical · Security (broken access control / cross-tenant secret disclosure)** — Fixed via [PR #135](https://github.com/Terrence721/coolify-full/pull/135) ([`8d5e1f1eb`](https://github.com/Terrence721/coolify-full/commit/8d5e1f1eb))

`SourceGithubController::show()` had no `authorize()` call — the only gate was `GithubApp::ownedByCurrentTeam()`, a scope that intentionally matches the current team's own apps *or any app from any team flagged `is_system_wide`* (correct for read-only app-selection, wrong as an access gate for the full record). Any authenticated user, on any team, could view another team's `is_system_wide` GitHub App at `GET /source/github/{uuid}` and get its `client_secret`/`webhook_secret` in plaintext — enough to forge a validly-signed webhook against that team's applications. Separately, `GithubAppPolicy::update()`/`delete()` already called `authorize()` correctly, but the policy itself was too permissive for `is_system_wide` apps: `$user->isAdmin()` checks the role in the user's current session team, not any instance-admin status, so any team's admin could rename or delete another team's shared app. Confirmed inherited verbatim from upstream Coolify's original Livewire `Source\Github\Change::mount()` — the React port carried the missing `authorize()` call over unchanged; the policy gap traces to the same original import. Fix: added the missing `authorize('view', $githubApp)` call. `GithubAppPolicy::view/update/delete` now require the owning team's membership/admin **or** `$user->isInstanceAdmin()` for `is_system_wide` apps, matching the pattern already used by `InstanceSettingsPolicy` for other instance-wide resources — was unconditionally true / any team's admin. The owning team's own access is unchanged.

---

### [`DownloadBackupTest.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194901327)

**medium · Test quality (false-confidence gap on a route with an actual IDOR history)** — Fixed via [PR #136](https://github.com/Terrence721/coolify-full/pull/136) ([`ab5d9f309`](https://github.com/Terrence721/coolify-full/commit/ab5d9f309))

The `download.backup` route (`routes/web.php:688-752`) wraps its entire body — every authorization check through the SFTP stream — in a single `catch (Throwable $e)` that returns the identical `500 {"message": "Failed to download backup."}` no matter where inside it something throws. The same-team regression test only asserted that generic response, so it couldn't tell "authorization correctly passed, then hit an unreachable SFTP server (expected in this test env)" apart from "an unrelated bug threw somewhere else in that same block, coincidentally producing the identical response." Found via an independent `/code-review 109` pass (pseudo peer review) on already-merged PR #109 (the `download.backup` IDOR fix) — the fix itself confirmed correct and complete across 4 review angles plus the command's own internal cross-check; this was the one real gap left in its test coverage. Fix: mock `Storage::build()` to prove the request actually reached the SFTP-connection step — past every authorization and relation-traversal check before it — rather than just asserting "some exception happened." Required giving the test's server fixture a real (throwaway, test-only) private key, since the route dereferences it immediately before the mocked call.

---

### [`ServicePolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194906253)

**medium · Security (broken access control, currently unreachable)** — Fixed via [PR #137](https://github.com/Terrence721/coolify-full/pull/137) ([`053540723`](https://github.com/Terrence721/coolify-full/commit/053540723))

`accessTerminal()` dereferenced `$service->team()->id` with no null-guard — every sibling method in the same file (`update()`, `stop()`, `manageEnvironment()`, `deploy()`) guards this with `$team = $service->team(); if (!$team) return false;` before touching `$team->id`. `Service::team()` is `data_get($this, 'environment.project.team')`, which is null whenever that relation chain is broken. Confirmed currently unreachable: nothing in the app calls `authorize('accessTerminal', $service)` against this policy method — the `canAccessTerminal` Gate used everywhere in the real app is a separate, unrelated check (`isAdmin() || isOwner()`, no resource involved). Still worth fixing: PR #111's entire purpose was auditing and hardening this exact file's authorization checks, and this was the one sibling method it left inconsistent. Found via an independent `/code-review 111` pass (pseudo peer review) on already-merged PR #111 (the 17-Policy-class disabled-authorization-check restoration). Fix: added the same null-guard the other 4 methods in this file already use.
