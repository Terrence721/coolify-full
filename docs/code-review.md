# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 21, 2026**

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

---

### [`DisabledPolicyChecksTest.php`, `NotificationPolicyTest.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194910773)

**medium · Maintainability (test-only DRY gap)** — Fixed via [PR #138](https://github.com/Terrence721/coolify-full/pull/138) ([`89d5b6e9b`](https://github.com/Terrence721/coolify-full/commit/89d5b6e9b))

Both files independently define byte-for-byte identical private `memberOf()`/`adminOf()` (and `NotificationPolicyTest.php` additionally has `ownerOf()`) test helpers, with no shared trait either could reuse. Confirmed via direct diff — genuinely identical, not just similarly named. Second of two fixes from an independent `/code-review 111` pass (pseudo peer review) on already-merged PR #111 — the first (`ServicePolicy::accessTerminal()`'s missing null-guard) is finding #52. Fix: new `Tests\Support\InteractsWithTeamRoles` trait (matching this repo's existing `tests/Support/CallsProtectedMethods.php` convention) holding all three helpers. Both test classes now `use` it instead of hand-rolling their own copies. Pure refactor, no behavior change.

---

### [`ApplicationPolicy.php`, `ServicePolicy.php`, `ApplicationPreviewPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194910831)

**medium · Security (broken access control, mixed reachability)** — Fixed via [PR #139](https://github.com/Terrence721/coolify-full/pull/139) ([`9862ed3d4`](https://github.com/Terrence721/coolify-full/commit/9862ed3d4))

Found via a second, follow-up independent `/code-review 111` pass (pseudo peer review) on already-merged PR #111 — the same "isAdmin()-only, missing team-membership check" bug shape as finding #52 (`ServicePolicy::accessTerminal()`), repeated across 3 more sibling methods in 3 different Policy classes, bundled into one fix since it's the identical bug pattern (matching PR #111's own precedent of restoring the same disabled-check bug across 17 Policy classes in one commit). `ApplicationPolicy::update()`/`delete()` checked only `isAdmin()`, unlike `forceDelete()`/`deploy()`/`manageDeployments()`/`manageEnvironment()` in the same class. `ServicePolicy::delete()`/`forceDelete()` checked only `isAdmin()`, unlike `update()`/`stop()`/`manageEnvironment()`/`deploy()`/`accessTerminal()` in the same class — `ServiceApplicationPolicy`/`ServiceDatabasePolicy` delegate their own `delete()`/`forceDelete()` to this exact check. `ApplicationPreviewPolicy::update()` checked only `isAdmin()`, unlike `view()`/`delete()`/`restore()`/`forceDelete()`/`deploy()`/`manageDeployments()` in the same class. Independently re-verified reachability for each: `ApplicationPolicy::update/delete` is exploitable only if a future caller resolves an `Application` without team-scoping first (every current call site scopes it); `ApplicationPreviewPolicy::update()` is currently dead code, same shape as the already-fixed `accessTerminal()` gap. Fix: added the same team-membership check each sibling method in the same file already uses, matching each file's own established pattern.

---

### [`routes/api.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194917300)

**medium · Security (broken access control / unauthenticated external relay)** — Fixed via [PR #140](https://github.com/Terrence721/coolify-full/pull/140) ([`81e3c695d`](https://github.com/Terrence721/coolify-full/commit/81e3c695d))

`POST /api/feedback` (`OtherController::feedback()`) was registered outside the versioned `/api/v1/*` route group and its `auth:sanctum` + `api.token.team` + `api.ability` middleware stack — every other API action in this app requires a valid Sanctum bearer token; this one had only a `throttle:feedback` rate limit (3 requests/minute, keyed by IP for unauthenticated requests, trivially bypassed). Anyone who can reach the instance — no account, no login, no token — could relay arbitrary attacker-controlled text (up to 2000 chars) through the operator's own server into whatever `FEEDBACK_DISCORD_WEBHOOK` env var they've configured, a "confused deputy" open relay into a third party's private Discord channel. Found via a general-purpose research-agent sweep pointed at unswept API territory. Confirmed inherited verbatim from upstream Coolify. Confirmed zero frontend code in this fork calls this endpoint, and it has no OpenAPI docblock unlike every sibling method in the same controller. Fix: added the same middleware stack every other write-type API action already uses, keeping the existing path and rate limit as defense-in-depth.

---

### [`GithubAppPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194952747)

**high · Security (broken access control / cross-tenant secret disclosure)** — Fixed via [PR #147](https://github.com/Terrence721/coolify-full/pull/147) ([`34a619976`](https://github.com/Terrence721/coolify-full/commit/34a619976))

`update()`/`delete()` checked `$user->isAdmin() && $user->teams->contains('id', $githubApp->team_id)` for system-wide (and, identically, non-system-wide) apps — `isAdmin()` resolves the role in the user's *current session team*, not the target app's owning team, traced through `User::role()` → `currentTeam()` → `session('currentTeam')`. Combined with `teams->contains()` only checking plain membership (any role), a user who is admin of their own current team but only a plain member of a different team could pass this check for any `GithubApp` owned by that other team. Found via an independent `/code-review 135` pass (pseudo peer review) on already-merged PR #135. Concrete, confirmed exploit: `SourceGithubController::update()` calls `authorize('update', $githubApp)` then sets `client_secret`/`webhook_secret` directly from request input — proved via a real HTTP request through the controller, pre-fix, returning 302 (succeeded) where it should 403. The correct pattern already existed elsewhere in this codebase (`PrivateKeyPolicy::update()`/`delete()`, using `isAdminOfTeam($teamId)`). The same broken pattern also existed in the non-system-wide branch, but is currently unreachable via this controller — `GithubApp::ownedByCurrentTeam()`'s route-model-resolution scope already 404s that case before `authorize()` is reached — fixed anyway for policy-level correctness. Fix: switched both branches of both methods to `isAdminOfTeam($githubApp->team_id)`.

---

### [`ServiceDatabasePolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194958165)

**medium-high · Security (broken access control / intra-team privilege escalation)** — Fixed via [PR #148](https://github.com/Terrence721/coolify-full/pull/148) ([`e84e162a3`](https://github.com/Terrence721/coolify-full/commit/e84e162a3))

`manageBackups()` returned `true` unconditionally — no role check, no team check at all — unlike every sibling method in the same file (`update()`/`delete()`/`restore()`/`forceDelete()`), which correctly delegate to `Gate::allows('update'|'delete', $serviceDatabase->service)`. Confirmed via `git show` this was `return true` verbatim from the original upstream import, with no commented-out check to restore — a different bug shape from the earlier 17-Policy disabled-check sweep (PR #111), which never audited this file. Found via an autonomous code-review sweep, prioritized under the established Tier 1 (security) first, Tier 2 (reliability) fallback order — Tier 1 turned up a real one. `ProjectServiceDatabaseBackupController`'s 7 mutating endpoints (`store`, `update`, `destroy`, `backupNow`, `cleanupFailedExecutions`, `cleanupDeletedExecutions`, `destroyExecution`) all call `authorize('manageBackups', $serviceDatabase)` after correctly team-scoping the model via `currentTeam()` — not a cross-team IDOR, a real, reachable intra-team privilege escalation: any plain team member could create/edit/delete a service database's backup schedule, force a backup run, and delete backup files/executions. The `destroy`/`destroyExecution` paths have a password-confirmation step, but it only checks the member's own current password, not a role — no actual barrier. Comparable correct pattern already in this codebase: `DatabasePolicy::manageBackups()` (for standalone databases) correctly requires admin. Fix: delegate to `Gate::allows('update', $serviceDatabase->service)`, matching this file's own established pattern.

---

### [`ApplicationPolicy.php`, `ServicePolicy.php`, `ApplicationPreviewPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194964552)

**high · Security (broken access control / intra-team privilege escalation, plus one broader cross-tenant gap)** — Fixed via [PR #149](https://github.com/Terrence721/coolify-full/pull/149) ([`436b96dbc`](https://github.com/Terrence721/coolify-full/commit/436b96dbc))

PR #139 added team-membership checks to 14 methods across these 3 files, but used `$user->isAdmin()` (role in the user's *current session team*) instead of `$user->isAdminOfTeam($teamId)` (admin status in the *target resource's* team) — the exact same bug class just fixed in `GithubAppPolicy` (finding #56). A user admin of their own current team but only a plain member of a different team could pass the combined `isAdmin() && teams->contains()` check for any resource owned by that other team. Also found in the same sweep, a distinct and more severe bug: `ServicePolicy::accessTerminal()` used `||` instead of `&&` — any instance admin of *any* team could pass regardless of any real relationship to the target service's team, currently unreachable but fixed anyway as the intended last line of defense. `isAdminOfTeam()` widened from `int` to `?int` since `Application::team()` is genuinely nullable. Found via an independent `/code-review 139` pass (pseudo peer review) on already-merged PR #139, corroborated by two independent background verification passes. 3 existing regression tests from PR #139 had the identical test-writing gap already found in `GithubAppPolicy`'s own pre-existing test — the "admin of a different team" user was never actually attached to the target resource's team, so those tests only ever exercised the membership check, never the `isAdmin()` logic they claimed to test. Fixed via a new `adminOfButMemberOf()` trait helper constructing the real cross-tenant shape. Fix: switched all 14 methods plus `accessTerminal()` to `isAdminOfTeam()`.

---

### [`StandaloneDockerPolicy.php`, `SwarmDockerPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194968170)

**high · Security (broken access control / intra-team privilege escalation)** — Fixed via [PR #151](https://github.com/Terrence721/coolify-full/pull/151) ([`c8b0e20c7`](https://github.com/Terrence721/coolify-full/commit/c8b0e20c7))

`update()`/`delete()` in both files (identical logic in each) checked only team membership — no role check at all — inconsistent with `create()` in the same two files, which correctly requires `isAdmin()`, and with `ServerPolicy::update()`/`delete()` on the closely related `Server` resource, both of which correctly require `isAdmin() && teams->contains(...)`. Found via an autonomous code-review sweep, prioritized under the established Tier 1 (security) first, Tier 2 (reliability) fallback order — Tier 1 turned up a real one. Confirmed reachable via `DestinationController::update()`/`destroy()`, both calling `authorize('update'|'delete', $destination)` after resolving via `find_destination_for_current_team()` — correctly team-scoped, a real intra-team privilege escalation, not a cross-team IDOR: any plain team member could rename or delete a team's Docker destination. `destroy()` isn't just a row delete — it runs real remote SSH commands (`docker network disconnect`/`docker network rm -f`) against the physical server before removing the DB row. Fix: switched both methods in both files to match `ServerPolicy`'s established pattern.

---

### [`ProjectController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195047537)

**critical · Security (secret disclosure via missing API redaction)** — Fixed via [PR #152](https://github.com/Terrence721/coolify-full/pull/152) ([`9f0eb07ae`](https://github.com/Terrence721/coolify-full/commit/9f0eb07ae))

`environment_details()` (`GET /api/v1/projects/{uuid}/{environment_name_or_uuid}`, gated only by `api.ability:read`) loaded applications, services, and every database engine onto the Environment response and serialized the whole thing via `serializeApiResponse()` with zero redaction — never wired into `RedactsApiSensitiveFields` at all, unlike every dedicated single-type controller, and unlike `ResourcesController`'s own mixed-type `/resources` endpoint (finding #22), which already solved this exact "multiple resource types in one response" shape. No model in this codebase defines `$hidden` — all redaction is opt-in, per-controller, and this controller simply never opted in. Found via an autonomous code-review sweep, prioritized under the established Tier 1 (security) first, Tier 2 (reliability) fallback order. Confirmed reachable: team-scoped correctly (not a cross-tenant IDOR), zero existing test coverage for this endpoint. Concrete leak: any token with plain `read` ability got every application's `docker_compose_raw`/webhook secrets/basic-auth password, every database's root/user passwords in plaintext, and every service's raw compose file, for a whole environment in one request — same bug class already fixed 4 times this session on other controllers, a fresh instance on a controller none of those touched. Fix: dispatches per relation, pulling the exact same field lists each dedicated controller already exposes via its own `sensitiveFieldLists()`, matching `ResourcesController`'s established pattern. Disclosed, not fixed: `environment_details()`'s own `load()` call never includes `keydbs`/`dragonflies`/`clickhouses` at all, so those 3 engines never appear in this endpoint's response regardless of redaction — a separate completeness gap, out of scope for this security fix.

---

### [`ProjectController.php`, `ResourcesController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195052005)

**medium · Maintainability (redaction procedure duplication)** — Fixed via [PR #154](https://github.com/Terrence721/coolify-full/pull/154) ([`a30bb3a54`](https://github.com/Terrence721/coolify-full/commit/a30bb3a54))

`ProjectController::redactEnvironmentResources()` and `ResourcesController::removeSensitiveData()` each hand-rolled the same makeHidden()-application loop instead of using `RedactsApiSensitiveFields::hideApiFields()`, which already existed for exactly this purpose. Found via an independent `/code-review 152` pass (pseudo peer review) on already-merged PR #152 — 3 of 8 parallel review angles (diff scan, reuse/duplication check, altitude/architecture check) converged on the same finding. PR #152's own docblock inaccurately claimed the redaction procedure was already shared "matching ResourcesController's established pattern" — only the field-list *sources* were shared, not the procedure itself, which was implemented three separate times. Fix: split `RedactsApiSensitiveFields::redactApiFields()` into a new `hideApiFields()` (makeHidden-only, no serialize) for callers redacting several models across several relations before one final `serializeApiResponse()` pass; both controllers now delegate to it. Pure refactor, no behavior change — confirmed by an identical Pest pass count before and after (1429 tests, 5611 assertions).

---

### [`PushServerUpdateJob.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195056019)

**medium · Reliability (silently swallowed exception)** — Fixed via [PR #156](https://github.com/Terrence721/coolify-full/pull/156) ([`778b50fa3`](https://github.com/Terrence721/coolify-full/commit/778b50fa3))

`handle()`'s per-container processing loop wrapped the application/preview status branch in `try { ... } catch (\Exception $e) {}` — a completely empty catch, unlike every other catch block in this same file (`updateProxyStatus()` logs via `Log::error()`). Found via an autonomous sweep, prioritized under the established Tier 1 (security) first, Tier 2 (reliability) fallback order — genuinely unswept API controllers and Policy classes were checked first for Tier 1 and came back clean, so this Tier 2 finding is what the fallback turned up. `PushServerUpdateJob` is dispatched from `SentinelController::push()` on every Sentinel agent heartbeat (~60s per functional server) — a genuinely hot path. The swallowed branch calls `updateApplicationPreviewStatus()`, whose `$application->save()` writes to the same `applications`/`application_previews` tables that deployments and other jobs write to concurrently — a transient deadlock or lock-wait timeout is realistic, not hypothetical; when it happens, the preview's status silently fails to update for that heartbeat cycle with zero operator-visible signal. Fix: extracted the try/catch body into its own `processApplicationContainerLabels()` method, matching this file's own existing pattern (`updateProxyStatus()`/`aggregateMultiContainerStatuses()` are already separate, independently-testable private methods rather than inline loop bodies) — necessary to TDD-prove the fix without mocking the full SSH-heavy `handle()` pipeline. Added the same `Log::error()` call the sibling catch block already uses. New regression test simulates a real DB-write failure (a stand-in preview object whose `save()` throws) and asserts the exception is logged, not silently lost — TDD-proved via `git stash`: confirmed `ReflectionException` pre-fix (the extracted method didn't exist yet), passing after.

---

### [`PushServerUpdateJob.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195060367)

**medium · Reliability (uncaught TypeError surface introduced by an extraction)** — Fixed via [PR #157](https://github.com/Terrence721/coolify-full/pull/157) ([`1c6bac44d`](https://github.com/Terrence721/coolify-full/commit/1c6bac44d))

Found via an independent `/code-review 156` pass (pseudo peer review) on already-merged PR #156 — 3 of 4 parallel review angles converged on the same finding, and 2 of them also independently flagged a related, pre-existing inconsistency. PR #156's extraction into `processApplicationContainerLabels(Collection $labels, string $applicationId, string $pullRequestId, string $containerStatus)` moved type enforcement to the call site in `handle()`'s foreach — outside any try/catch, under this file's `declare(strict_types=1)`. `SentinelController::push()` only validates `'containers' => ['present', 'array']` — nothing validates that nested label values decode as strings, so a malformed/non-string label value (a JSON number instead of a string) throws an uncaught `TypeError` right at that call, aborting the whole heartbeat's container loop instead of failing just that one container — a stronger failure mode than the silent swallow PR #156 set out to fix. Reproduced with a real `handle()` call rather than Reflection (which bypasses `strict_types` entirely, so a reflection-based test wouldn't have caught this) — confirmed the exact `TypeError` fires pre-fix. Fix: widened `$applicationId`/`$pullRequestId` to `mixed`, cast to `string` as the first lines inside the try block; also widened the catch from `\Exception` to `\Throwable`, matching the sibling `updateProxyStatus()` catch this method was already modeled on — a pre-existing inconsistency, not introduced by #156, but worth closing since it's exactly the class of error the new cast could itself raise. Not actioned: a 4th review angle noted the two now-near-identical log-and-swallow catch blocks could share a helper — two instances doesn't justify the abstraction yet.

---

### [`ApplicationPreview.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195071530)

**medium · Reliability (docker cleanup silently skipped for preview deletions)** — Fixed via [PR #158](https://github.com/Terrence721/coolify-full/pull/158) ([`84b70ac96`](https://github.com/Terrence721/coolify-full/commit/84b70ac96))

An autonomous sweep, prioritized under the Tier 1 (security) first, Tier 2 (reliability) fallback order. Tier 1 turned up two leads, both disproven on independent verification: a "missing redaction" claim on `GithubController` was a false positive (`GithubApp` already has native Eloquent `$hidden` protection for `client_secret`/`webhook_secret`), and a broken-access-control claim across 11 Policy classes had zero live-reachable exploit path anywhere — every controller resolves the target via `currentTeam()`, and this session's own finding #59 already used `ServerPolicy`'s exact pattern as the accepted reference. Falling back to Tier 2 found a real one. `DeleteResourceJob::handle()`'s `finally` block resolves the docker-cleanup target server via `data_get($resource, 'server') ?? data_get($resource, 'destination.server')` — correct for `Application`/`Service`/`StandaloneDatabaseInstance`, each with a real `destination()`/`server()` relation. `ApplicationPreview` has neither (it shares its parent `Application`'s destination, not its own), so both calls silently resolve `null` and `CleanupDocker::dispatch()` never runs for preview deletions — reached on every GitHub "PR closed" webhook, the manual delete-preview UI action, and the stuck-resource cleanup command. Fix: added a `server` `Attribute` accessor to `ApplicationPreview`, computed from `$this->application?->destination?->server` — the exact resolution the model's own `forceDeleting` boot hook already does directly a few lines away. Implemented via `Attribute::make()` rather than a plain method, since a plain `server()` method would collide with Eloquent's property-access relation resolution (which requires it to return a `Relation` instance, not a plain value) — caught this exact mismatch via TDD when the first implementation attempt threw a `LogicException` instead of resolving. Not catastrophic: `DockerCleanupJob` runs on a separate schedule and would eventually reclaim the same disk space — this closes the immediate-cleanup gap, not an unbounded leak.

---

### [`ApplicationPreview.php`, `DeleteResourceJob.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195085242)

**medium · Reliability (crash when a preview outlives its parent Application)** — Fixed via [PR #159](https://github.com/Terrence721/coolify-full/pull/159) ([`522f37352`](https://github.com/Terrence721/coolify-full/commit/522f37352))

Found via an independent `/code-review 158` pass (pseudo peer review) on already-merged PR #158. The review's specific claimed error text ("Call to a member function destination() on null") was inaccurate on independent verification — the actual code is a plain property chain, not a method call — but the underlying bug is real. Reproduced directly with a full Pest `TestCase` run rather than trusting the claim or an initial tinker check (which gave a false negative, since tinker's REPL doesn't apply Laravel's warning-to-exception conversion the same way a real queue worker does). `application_previews.application_id` has no DB foreign-key constraint, and `Application::forceDeleting` only soft-deletes its previews before the application row is permanently removed — it doesn't cascade a real delete — so a preview can genuinely outlive its parent. `CleanupStuckedResources`'s second preview-cleanup loop (unlike its sibling loop right above it, which already guards on the application relation) dispatches `DeleteResourceJob` for every trashed preview with no such guard. Both `DeleteResourceJob::deleteApplicationPreview()` and `ApplicationPreview`'s own `forceDeleting` boot hook chained `$preview->application->destination->server` with no null-safety — confirmed this throws `ErrorException: Attempt to read property "destination" on null` in the real app/queue-worker context (Laravel's `HandleExceptions` bootstrapper converts the underlying PHP warning into a real exception there). Fix: `DeleteResourceJob::deleteApplicationPreview()` now force-deletes the preview directly when its `application` relation is null; `ApplicationPreview`'s boot hook skips the docker volume/network cleanup block in that case but always still runs the local `persistentStorages()->delete()` cleanup.

---

### [`ServerController.php`, `BoardingController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195093390)

**critical · Security (cross-team private key acceptance / real SSH credential misuse)** — Fixed via [PR #160](https://github.com/Terrence721/coolify-full/pull/160) ([`dcc54239f`](https://github.com/Terrence721/coolify-full/commit/dcc54239f))

An autonomous sweep, prioritized under the Tier 1 (security) first, Tier 2 (reliability) fallback order, pointed at genuinely unswept territory (plain, non-API Inertia-page-backing controllers). `ServerController::store()` and `BoardingController::createServer()` both validated `private_key_id` as just `'nullable|integer'`/`'required|integer'` — no check that the referenced `PrivateKey` belongs to `currentTeam()` — writing the value straight into `Server::create()`. Worse than a typical IDOR: `SshMultiplexingHelper::generateSshCommand()` resolves the key via `PrivateKey::findOrFail($server->private_key_id)` — completely unscoped — writes that key's real private key material to disk, and uses it (`-i {$sshKeyLocation}`) to build the actual SSH command. A user could create a server in their own team pointing `private_key_id` at another team's key (sequential, trivially enumerable IDs) plus an attacker-controlled `ip`/`user`/`port`; any later action that connects to that server causes Coolify to load the *other team's actual SSH private key* and attempt authentication against the attacker-chosen host. Correct pattern already existed in the same codebase — `ServerPrivateKeyController::setKey()` (changing an *existing* server's key) already scopes correctly via `PrivateKey::ownedByCurrentTeam()->find(...)`; server *creation* just never adopted the same pattern in either entry point. Independently re-verified before fixing: read both controllers directly confirming the unscoped validation; confirmed `ServerPolicy::create()` only checks `isAdmin()`, with no `Server` instance yet to check a key against; confirmed `PrivateKey` has no global team scope; traced `SshMultiplexingHelper::generateSshCommand()` directly confirming the unscoped `findOrFail()` and the real SSH command construction. Fix: both controllers now do the same `PrivateKey::ownedByCurrentTeam()->find(...)` lookup and reject with a clear error before ever reaching `Server::create()`. New regression tests in both `ServerIndexTest.php` and `BoardingControllerTest.php` attempt to create a server with another team's private key ID; TDD-proved failing pre-fix (the server was genuinely created with the cross-team key), passing after.

---

### [`SourceGithubController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195101222)

**critical · Security (cross-team private key attachment / live credential leak via signed JWT)** — Fixed via [PR #161](https://github.com/Terrence721/coolify-full/pull/161) ([`4e4726ffb`](https://github.com/Terrence721/coolify-full/commit/4e4726ffb))

Found via an autonomous sweep specifically hunting for more instances of the bug class just fixed in PR #160 (unscoped `private_key_id` acceptance). `SourceGithubController::update()` validated `privateKeyId` as just `'nullable|int'` — no check that the referenced `PrivateKey` belongs to `currentTeam()` — writing it straight into `GithubApp::update()`. `GithubApp::privateKey()` is a plain unscoped `belongsTo`, so any team's key could be attached. More directly exploitable than PR #160's finding: `generateGithubToken()` signs a real JWT with `$source->privateKey->private_key` — the actual decrypted key material — and `GithubAppPermissionJob` sends that JWT via a real outbound HTTP request to `$github_app->api_url`, attacker-settable (`SafeExternalUrl` only blocks internal/private/reserved hosts). A team admin attaches another team's private key, points `api_url` at their own server, triggers `checkPermissions()` — Coolify signs a JWT with the victim team's real key and delivers it directly to the attacker's server, no action needed from the victim, usable to impersonate the victim's GitHub App against the real GitHub API within its 8-minute validity window if relayed there. Correct pattern already existed in the same file — `updateName()` already scopes correctly via `PrivateKey::ownedByCurrentTeam()->find(...)` — `update()` just never adopted it; confirmed it's the only write site for `private_key_id` in this controller. Independently re-verified before fixing: read all three methods directly, confirmed the unscoped relation, traced `generateGithubToken()`'s signing call and `GithubAppPermissionJob`'s real outbound HTTP request, read `SafeExternalUrl` confirming any public host is permitted. Fix: `update()` now resolves `privateKeyId` through the same `ownedByCurrentTeam()` lookup before writing. New regression test attempts to attach another team's private key on update; TDD-proved failing pre-fix (attached with no error), passing after; a positive test confirms attaching the current team's own key still works.

---

### [`EnvironmentController.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195113300)

**critical · Security (cross-team destination acceptance on environment clone)** — Fixed via [PR #163](https://github.com/Terrence721/coolify-full/pull/163) ([`a8dc9b1e5`](https://github.com/Terrence721/coolify-full/commit/a8dc9b1e5))

Found via an autonomous sweep hunting for more instances of the bug class fixed in PR #160/#161. This instance is subtler — the controller already computed the correct value, but two of three clone paths silently ignored it. `EnvironmentController::clone()` validates `destination_id` as a bare `'required|integer'`, then correctly resolves it by searching only `currentTeam()`'s own servers' destinations — a cross-team `destination_id` would never be found by that loop, so `$selectedDestination` correctly ends up `null` in that case. The application clone path uses this correctly (`clone_application()`, which even re-checks the destination's server `team_id` itself as a second layer). The database and service clone blocks bypassed `$selectedDestination` entirely and wrote the raw `$validated['destination_id']` straight into the replicated row. `StandaloneDocker`/`SwarmDocker`'s `server()` relation and `Service::destination()` are both unscoped — once such a database/service is started, `StartDatabase::handle()` resolves `destination.server` and deploys there if functional, no ownership re-check at start time. A team member cloning an environment could pass any team's `destination_id` (sequential, enumerable) and end up with a database/service record pointing at that team's server; starting it deploys real containers there over that server's own configured SSH credentials. Fix: moved the destination lookup and an explicit ownership guard to the top of the `try` block, before any project/environment/database/service gets created (also making a bad `destination_id` fail atomically instead of leaving a half-created empty clone behind), and wired the database/service clone blocks to use `$selectedDestination->id` instead of the raw validated input.

---

### [`NotificationPolicy.php`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195127177)

**medium · Reliability (root team locked out of its own notification settings)** — Fixed via [PR #165](https://github.com/Terrence721/coolify-full/pull/165) ([`5e20a8f74`](https://github.com/Terrence721/coolify-full/commit/5e20a8f74))

Found via an independent `/code-review 164` pass (pseudo peer review) on already-merged PR #164 (the `NotificationPolicy`/`CloudProviderTokenPolicy` team-scoping hardening). PR #164's `update()` fix added `if (! $teamId) { return false; }` before the new `isAdminOfTeam($teamId)` check — a falsy check, not a null check. Team id `0` is the real root/instance team every Coolify install seeds (`RootUserSeeder`, `ProductionSeeder`, and others all explicitly create it with `id => 0`), and `!0` is `true` in PHP, so the root team's own admin/owner was silently denied `update()`/`manage()`/`sendTest()` on its own notification settings — reachable through `NotificationsEmailController::edit()` and its 5 siblings, all of which derive `$settings` from `currentTeam()`. `view()` (unchanged in PR #164) doesn't share the bug, since it checks the `team` relation object's truthiness (always truthy) rather than `team.id`. Independently re-verified before fixing: confirmed team id 0 is a real, actively-used pattern via `grep` across `database/seeders/` and multiple `$model->id === 0` checks elsewhere in the app; reproduced with a real Pest test (`Team::factory()->create(['id' => 0])`, an admin of it, asserting `update()` returns `true`) rather than trusting the review's claim on its own — confirmed failing pre-fix. Fix: `is_null($teamId)` instead of a falsy check. New regression test `update_allows_an_admin_of_the_root_team_whose_id_is_zero`, TDD-proved against the pre-fix code.

---

### [`useTeamChannel.js`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-195128989)

**medium · Reliability (root team's real-time updates silently disabled)** — Fixed via [PR #166](https://github.com/Terrence721/coolify-full/pull/166) ([`a0bb97d52`](https://github.com/Terrence721/coolify-full/commit/a0bb97d52))

Found via an autonomous sweep specifically hunting for more instances of finding #69's bug class (a falsy check on an ID that can legitimately be `0`) — this time in JS, not PHP. `useTeamChannel`'s effect guard `if (!teamId || !echoConfig || events.length === 0) { return; }` treats team id `0` as falsy (`!0` is `true` in JS). Team id `0` is the real root/instance team every Coolify install seeds (`RootUserSeeder` explicitly creates `Team::find(0)`, attaches the root user as owner); `HandleInertiaRequests` shares its real numeric `id` (0) as the `currentTeam` prop for that user (`$team ? [...] : null` checks the Team object's truthiness, always truthy, not the id's), and `App\Events\*`'s `broadcastOn()` methods build the matching `team.{id}` private channel name on the backend. Independently re-verified before fixing: traced the full chain from seeder through session to Inertia prop share to a real event's `broadcastOn()`, confirming `team.0` really is the channel name on both ends; checked the rest of the frontend for the same shape — the one other `!currentTeam`-style hit (`AppLayout.jsx`) checks the team object's truthiness, not `.id`, so it's unaffected. Consequence: this hook backs ~15 components/pages (`ServerNavbar`, `DatabaseHeading`, `ServiceHeading`, `ScheduledTasksTab`, `BackupExecutionsList`, `ConfigurationChecker`, `Deployment/Index`, etc.) — for the root/instance-admin user specifically, every one of these silently never subscribed, leaving deployment/proxy/backup/scheduled-task status stale until a manual page refresh, while every other team worked correctly. Fix: `teamId == null` instead of a falsy check, matching the `is_null($teamId)` fix already used for `NotificationPolicy::update()` (finding #69). New regression test confirms the hook subscribes for team id 0, TDD-proved against the pre-fix code.

---

### [`ProjectResourceCreateControllerTest.php`](https://github.com/Terrence721/coolify-full/blob/main/tests/v4/Feature/ProjectResourceCreateControllerTest.php)

**medium · Test quality** — Fixed via [PR #175](https://github.com/Terrence721/coolify-full/pull/175) ([`41cf76f8c`](https://github.com/Terrence721/coolify-full/commit/41cf76f8c))

Found via an independent `/code-review 112` pass (pseudo peer review) on already-merged PR #112. That PR restored the real `Gate::allows('createAnyResource')` check in `CanCreateResources` middleware — dead code that had let any team member create resources — but its only regression test hit `project.resource.create`, leaving the other 12 routes behind the same middleware with no test proving a plain member gets 403. Same false-confidence shape as finding #51's `download.backup` test gap. Verified all 13 routes via `routes/web.php` and confirmed none has an independent `authorize()` call. Fixed by expanding the single-route test into a Pest dataset covering all 13 route/method pairs. TDD-proved: reverting the middleware check reproduced the exact real symptom across all 13 cases.

---

### [`CanUpdateResource.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Middleware/CanUpdateResource.php)

**high · Security / Reliability / Test quality** — Fixed via [PR #176](https://github.com/Terrence721/coolify-full/pull/176), [PR #177](https://github.com/Terrence721/coolify-full/pull/177), [PR #178](https://github.com/Terrence721/coolify-full/pull/178), [PR #179](https://github.com/Terrence721/coolify-full/pull/179), [PR #180](https://github.com/Terrence721/coolify-full/pull/180)

Found via an independent `/code-review 113` pass — five separate real gaps in one file, the most any single pass has surfaced. (1) The `elseif` chain checked `service_uuid` before `stack_service_uuid`, so the more specific branch never matched. (2) The `server_uuid` branch used session-scoped `isAdmin()` instead of resolving the target through `Gate::allows()` — the same bug class as finding #56. (3) The `database_uuid` branch hand-listed all 8 standalone database model classes instead of using `DatabaseEngineRegistry::modelClasses()`, the single source of truth written to prevent exactly that drift. (4) The `project_uuid` branch returned 404 instead of the 403 every other branch produces for cross-team access. (5) Five of the middleware's seven branches had no test coverage at all. Landed as five independent PRs at the user's request rather than one bundled fix, so each stands as its own reviewable unit. Every fix individually TDD-proved by reverting it and confirming the exact real symptom.

---

### [`ServiceApplicationPolicy.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Policies/ServiceApplicationPolicy.php), [`ServiceDatabasePolicy.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Policies/ServiceDatabasePolicy.php)

**medium · Security** — Fixed via [PR #181](https://github.com/Terrence721/coolify-full/pull/181) ([`76ae0c74f`](https://github.com/Terrence721/coolify-full/commit/76ae0c74f))

Found via an independent `/code-review 114` pass. `EnvironmentVariablePolicy::canManage()` delegates to `Gate::allows('manageEnvironment', $resourceable)` for whatever model the polymorphic relation points at — but `ServiceApplication`/`ServiceDatabase` never got a `manageEnvironment` method on their policies. `Gate::allows()` returns `false` for a missing ability rather than throwing, so this would fail closed for every user including team owners, the moment any future caller passed one of these types through. A real gap, though confirmed not currently reachable. Writing the test surfaced a genuine gotcha: these policies delegate via the *static* `Gate::allows()` facade, which resolves against the authenticated user rather than the `$user` argument passed in — unlike sibling policies — so each assertion needs its own `actingAs()`.

---

### [`ServersController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/Api/ServersController.php)

**low · Security hygiene** — Fixed via [PR #184](https://github.com/Terrence721/coolify-full/pull/184) ([`637315bb1`](https://github.com/Terrence721/coolify-full/commit/637315bb1))

Found via an independent `/code-review 119` pass. `removeSensitiveData()` deliberately hides the server's own integer `id` (uuid is this API's public identifier), but the nested settings row carried both its own `id` and a `server_id` equal to that very value — returning one level down exactly what was stripped one level up. Verified `serializeApiResponse()` explicitly *preserves* `id` rather than stripping it, so nothing downstream removed these. Severity stated plainly rather than inflated: both endpoints are team-scoped, so a caller only ever saw their own team's server ids — not a cross-tenant leak, and an integer surrogate key is not a credential. Brought in line with the rule `app/Mcp/Concerns/BuildsResponse.php` already applies. Also removed a vestigial empty `if` block signalling redaction was once intended there.

---

### [`ServerLogDrainsController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/ServerLogDrainsController.php), [`ServerSentinelController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/ServerSentinelController.php)

**high · Security** — Fixed via [PR #185](https://github.com/Terrence721/coolify-full/pull/185) ([`03dc26a9f`](https://github.com/Terrence721/coolify-full/commit/03dc26a9f))

Found by the same `/code-review 119` pass, which flagged it as adjacent and out of scope for that diff; verified real and materially larger than the finding it accompanied. `ServerPolicy::view()` is plain team membership and neither controller's `index()` calls `authorize()` at all — unlike `toggle()`/`submit()` in both files. So any plain team member received the raw credentials in the Inertia props. The five fields involved are exactly the set `ServersController` gates behind the API's `read:sensitive` ability, so the API and the web UI gave opposite answers for an identical field set. Confirmed empirically: the pre-fix test failed with a member's rendered page genuinely containing the secret. Key judgment call — adding `authorize('view')` would have been a **no-op**, since that policy is the same membership check `ownedByCurrentTeam()` already enforces, so the fix gates the credentials rather than the page. Members keep the page and every enabled/disabled flag.

---

### [`ServersSensitiveFieldsTest.php`](https://github.com/Terrence721/coolify-full/blob/main/tests/v4/Feature/Api/ServersSensitiveFieldsTest.php)

**medium · Test quality** — Fixed via [PR #182](https://github.com/Terrence721/coolify-full/pull/182) ([`09cda4623`](https://github.com/Terrence721/coolify-full/commit/09cda4623))

Found via the same `/code-review 119` pass that produced the two findings above. PR #119's regression test for `logdrain_custom_config`/`logdrain_custom_config_parser` only asserted the single-resource endpoint (`GET /api/v1/servers/{uuid}`); `ServersController` calls `removeSensitiveDataFromSettings()` on the list endpoint (`GET /api/v1/servers`) too, so the same fields had no test proving they don't leak there. Fixed by adding a companion test matching the pattern already established by the `logdrain_axiom`/`logdrain_newrelic` tests, which check both endpoints. *Logged retroactively 2026-08-17 — the original logging commit landed with the right message but no file changes, so this went unrecorded for two days.*

---

### [`OauthController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/OauthController.php)

**high · Security / Reliability** — Fixed via [PR #183](https://github.com/Terrence721/coolify-full/pull/183) ([`69bdb88ec`](https://github.com/Terrence721/coolify-full/commit/69bdb88ec))

Found via an independent `/code-review 127` pass on already-merged PR #127 (the OAuth account-takeover fix). PR #127 required an OAuth login to match the account's originally-recorded `oauth_provider` before logging it in. Accounts created via OAuth before that column existed have `oauth_provider = NULL` and `password = NULL` — indistinguishable, under the new check, from a genuine password-only account being targeted — so every pre-existing OAuth-only user was locked out post-migration. Fixed by detecting that specific case (both columns null) as an existing OAuth account, allowing the login and backfilling `oauth_provider`, while leaving the account-takeover check intact for every other case. New regression test covers the migration scenario directly. *Logged retroactively 2026-08-17 — same empty-commit gap as the finding above.*

---

### [`OauthController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/OauthController.php)

**high · Security — broken authentication / account takeover** — Fixed via [PR #187](https://github.com/Terrence721/coolify-full/pull/187) ([`5ba13c588`](https://github.com/Terrence721/coolify-full/commit/5ba13c588))

Found via an independent `/code-review 183` pass on already-merged PR #183 (the finding immediately above). A legacy account (`oauth_provider = NULL`, `password = NULL`) has no record of which provider originally created it — confirmed independently that no other code path leaves `password` null, so this combination can only be a pre-migration OAuth account. PR #183's backfill let *any* currently-enabled provider claim such an account on first successful email match, reopening PR #127's account-takeover fix scoped to this population. Fixed by only allowing the backfill when exactly one OAuth provider is enabled — the only case where the provider making the request is unambiguously the one that could have created the account; with 2+ enabled providers, the request now fails closed instead of guessing. Also replaced a direct `$user->password === null` check with the existing `User::hasPassword()` helper. Disclosed tradeoff: a legacy account on a multi-provider instance has no self-service recovery path today.

---

### [`LogDrains.jsx`](https://github.com/Terrence721/coolify-full/blob/main/resources/js/Pages/Server/LogDrains.jsx)

**medium · UI correctness** — Fixed via [PR #188](https://github.com/Terrence721/coolify-full/pull/188) ([`e2ed08b17`](https://github.com/Terrence721/coolify-full/commit/e2ed08b17))

Found via an independent `/code-review 185` pass on already-merged PR #185 (the credential-withholding fix). The controller already sent a `canUpdate` prop to gate the page's forms for non-admin members, but `LogDrains.jsx` never consumed it — unlike every sibling Server page, which all disable their mutating controls when `canUpdate` is false. All 3 toggle checkboxes, all 6 inputs, and all 3 Save buttons stayed fully interactive for a plain member. `authorize('update', $server)` still rejects the actual mutation server-side, so this isn't a privilege escalation — just a broken read-only affordance. Fixed by adding `disabled={... || !canUpdate}` to all 12 controls. Also converted two inline security-rationale comments to PHPDoc blocks on their methods, per repo convention.

---

### [`ServerPolicy.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Policies/ServerPolicy.php)

**high · Security — cross-team privilege escalation** — Fixed via [PR #190](https://github.com/Terrence721/coolify-full/pull/190) ([`112510cb5`](https://github.com/Terrence721/coolify-full/commit/112510cb5))

Found via an independent `/code-review 177` pass on already-merged PR #177 (the `CanUpdateResource` fix that resolved the real `server_uuid` target). `update()`, `delete()`, `manageProxy()`, `manageSentinel()`, `manageCaCertificate()`, and `viewSecurity()` all checked `$user->isAdmin()` — the role in the user's session-current team — ANDed with mere membership (any role) in the server's team, instead of admin status *in that team*. A user admin of their own current team but only a plain member of the target server's team passed every one of these checks. PR #177 made this reachable via the bare `server.security` route; the two data-bearing controllers were only incidentally safe due to their own independent `ownedByCurrentTeam()` re-scoping. Fixed by swapping `isAdmin()` for `isAdminOfTeam($server->team_id)` in all 6 methods, matching the pattern `ApplicationPolicy` already used correctly.

---

### [`ProjectPolicy.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Policies/ProjectPolicy.php)

**medium · Security — latent cross-team privilege escalation, not currently exploitable** — Fixed via [PR #191](https://github.com/Terrence721/coolify-full/pull/191) ([`3681f802b`](https://github.com/Terrence721/coolify-full/commit/3681f802b))

Found via an independent `/code-review 179` pass on already-merged PR #179 (which fixed `CanUpdateResource`'s `project_uuid` branch to resolve unscoped and let the Gate produce 403 instead of a leaky 404 — confirmed correct on its own). `update()`, `delete()`, `restore()`, and `forceDelete()` shared the same `isAdmin()`-vs-target-team bug as `ServerPolicy` (above). Unlike that finding, confirmed not currently exploitable — both routes reaching the branch (`project.edit`, `project.update`) independently re-scope to `currentTeam()` in the controller first, masking rather than closing the gap. Fixed anyway since the same bug shape has now recurred three times across this codebase. Same fix pattern: `isAdminOfTeam($project->team_id)` in all 4 methods.

---

### [`CanUpdateResourceTest.php`](https://github.com/Terrence721/coolify-full/blob/main/tests/Unit/Middleware/CanUpdateResourceTest.php)

**low · Test quality — duplication** — Fixed via [PR #192](https://github.com/Terrence721/coolify-full/pull/192)

Found via an independent `/code-review 180` pass on already-merged PR #180 (which added the `application_uuid`/`service_uuid` branch tests), run twice by the user and converging on the same finding both times. The two new tests duplicated a ~9-line team/user/server/destination/project/environment setup block and an ~11-line cross-team-403 assertion block verbatim, differing only in the failure message string. Fixed by extracting `actingAsNewTeamAdmin()`/`assertCrossTeamUuidIs403()` helpers.

---

### [`ServiceApplicationPolicy.php`, `ServiceDatabasePolicy.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Policies/ServiceApplicationPolicy.php)

**medium · Security — latent wrong-actor authorization, not currently exploitable** — Fixed via [PR #193](https://github.com/Terrence721/coolify-full/pull/193) ([`62e7549bc`](https://github.com/Terrence721/coolify-full/commit/62e7549bc))

Found via an independent `/code-review 181` pass on already-merged PR #181 (which added `manageEnvironment()` to both policies), corroborated by 2 background review angles. The new methods — and every sibling delegate method already in both files — called the static `Gate::allows()` facade, which resolves against the currently-authenticated `Auth::user()` rather than the `$user` argument Laravel passes to a policy method. `EnvironmentVariablePolicy::canManage()`, the real caller, deliberately uses `Gate::forUser($user)` to evaluate an explicit `$user`; sibling policies all check `$user` directly. Dormant today since every real caller passes the currently-authenticated user, but the first caller evaluating permissions for a different user would silently authorize the wrong person. Fixed by switching every `Gate::allows(...)` call to `Gate::forUser($user)->allows(...)` across both files, not just the 2 new methods.

---

### [`ProjectEnvironmentVariablesTabTest.php`](https://github.com/Terrence721/coolify-full/blob/main/tests/v4/Feature/ProjectEnvironmentVariablesTabTest.php)

**low · Test quality — duplication** — Fixed via [PR #194](https://github.com/Terrence721/coolify-full/pull/194)

Found via the same `/code-review 181` pass above. `envTabMakePostgres()`, `envTabMakeApplication()`, and `envTabMakeService()` each duplicated an identical `Server::factory()->create(...)` + `Project::factory()->create(...)` setup block. Fixed by extracting `envTabMakeServerAndProject()`.

---

### [`ServersController.php`, `ServerSetting.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/Api/ServersController.php)

**medium · Reliability + doc drift** — Fixed via [PR #195](https://github.com/Terrence721/coolify-full/pull/195) ([`8b90613f7`](https://github.com/Terrence721/coolify-full/commit/8b90613f7))

Found via an independent `/code-review 184` pass on already-merged PR #184 (the fix hiding the settings row's surrogate keys). The new unconditional `makeHidden(['id', 'server_id'])` call ran ahead of the `can_read_sensitive` guard that used to gate the file's only `makeHidden()` call — a server with a null settings relation now fatals on the `read:sensitive` API path too (the plain-`read` path already crashed pre-existing; PR #184 widened an existing gap). Not confirmed reachable in production (every `Server` auto-creates its `ServerSetting` via a model hook) but independently reproduced via a real HTTP request. Same pass found `ServerSetting`'s `#[OA\Schema]` attribute and the checked-in `openapi.yaml` still listed `id`/`server_id` despite both now being hidden. Fixed by adding a null guard, removing both stale schema properties, and regenerating `openapi.yaml` from source.

---

### [`OauthController.php`, `FortifyServiceProvider.php`, `OauthSetting.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/OauthController.php)

**low · Reuse** — Fixed via [PR #196](https://github.com/Terrence721/coolify-full/pull/196) ([`df1d39da8`](https://github.com/Terrence721/coolify-full/commit/df1d39da8))

Found via an independent `/code-review 187` pass on already-merged PR #187. `OauthController`/`FortifyServiceProvider` each hand-rolled `OauthSetting::where('enabled', true)` with no shared scope. Fixed by extracting `OauthSetting::scopeEnabled()`.

---

### [`OauthController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/OauthController.php)

**high · Security — TOCTOU account-takeover, requires a concurrent admin action** — Fixed via [PR #197](https://github.com/Terrence721/coolify-full/pull/197) ([`dff2c0c0a`](https://github.com/Terrence721/coolify-full/commit/dff2c0c0a))

Found via the same `/code-review 187` pass above, corroborated by 3 independent review passes. PR #187's multi-provider-lockout check (`count() === 1`) only proved the enabled-provider set was a singleton at check time, not that the singleton was the provider actually completing the login. `get_socialite_provider()` validates the requesting provider's own enabled flag *before* the network round-trip to the OAuth IdP; an admin provider-swap during that round-trip could let the check pass against a different now-sole-enabled provider while backfilling the original, disabled one — the exact account-takeover this fix chain (#127/#183/#187) exists to prevent, just requiring one intervening admin action. Fixed by checking the enabled set is exactly `{$provider}`, not just its count. New regression test reproduces the race by running the provider swap as a side effect inside the faked Socialite callback.

---

### [`LogDrains.jsx`](https://github.com/Terrence721/coolify-full/blob/main/resources/js/Pages/Server/LogDrains.jsx)

**low · UI consistency + test coverage** — Fixed via [PR #198](https://github.com/Terrence721/coolify-full/pull/198) ([`7cd213ae7`](https://github.com/Terrence721/coolify-full/commit/7cd213ae7))

Found via an independent `/code-review 188` pass on already-merged PR #188 (the `canUpdate` gating fix). Confirmed correct across all 3 background review angles, but `LogDrains.jsx` kept its 3 Save buttons rendered-but-disabled for a plain member instead of hidden entirely, the only page in its family to do so — independently verified against `Sentinel.jsx` directly, since the official review synthesis's "matches the sibling pattern" claim didn't hold for this specific element. Also found `isLogDrainEnabled || !canUpdate` duplicated verbatim 6 times, and the backend test missing the `canUpdate` assertion its sibling test already had. Fixed by hiding the buttons for non-updaters, extracting the duplicated expression into a `fieldsDisabled` variable, and adding the missing backend assertions.

---

### [`ServicesController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/Api/ServicesController.php)

**high · Security — mass-assignment IDOR** — Fixed via [PR #199](https://github.com/Terrence721/coolify-full/pull/199) ([`a617b3a9a`](https://github.com/Terrence721/coolify-full/commit/a617b3a9a7509a0d76d5a7f4d24779cb7e9b5b31))

Found via a fresh code-review pass on this previously-unreviewed 2,217-line API controller. `create_bulk_envs()` passed the raw request item straight through as `updateOrCreate()`'s `$values`; `resourceable_id`/`resourceable_type` are both fillable and the validator never rejects unknown fields. The create-new-row path is already safe (Eloquent re-applies the correct foreign/morph attributes after `newInstance()`), but the update-existing-row path calls `fill($values)->save()` unconditionally with no re-application — a caller who knows an existing env var's key can retarget it onto an arbitrary resource, including a different team's. Same call also discarded the normalized key on save. Fixed by building an explicit whitelisted `$values` array instead of passing the raw item through.

---

### [`ServicesController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/Api/ServicesController.php)

**medium · Reliability** — Fixed via [PR #200](https://github.com/Terrence721/coolify-full/pull/200) ([`ea18e8676`](https://github.com/Terrence721/coolify-full/commit/ea18e8676b42916adef78d4f04b10b7c5a2d12b5))

Found via the same pass above. `create_bulk_envs()` only checked truthiness of the request's `data` field before `foreach`-ing over it — a non-empty string value threw instead of returning a clean 400. Fixed by checking `is_array($bulk_data)` instead of just truthiness.

---

### [`ServicesController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/Api/ServicesController.php)

**low · Consistency, not a security leak** — Fixed via [PR #201](https://github.com/Terrence721/coolify-full/pull/201) ([`a05c2375e`](https://github.com/Terrence721/coolify-full/commit/a05c2375e124364fd49e533054d6c37812764fa4))

Found via the same pass above. `services()` reassigned its `foreach` loop variable instead of writing `removeSensitiveData()`'s result back into `$services`, discarding `serializeApiResponse()`'s key-sort/field-reordering on the list endpoint — sensitive fields still ended up hidden only because `makeHidden()` mutates the model in place. Fixed with `->map()` instead of a `foreach` that discards its result.

---

### [`ServicesController.php`](https://github.com/Terrence721/coolify-full/blob/main/app/Http/Controllers/Api/ServicesController.php)

**low · Maintainability, dead code** — Fixed via [PR #202](https://github.com/Terrence721/coolify-full/pull/202) ([`b11c9c56f`](https://github.com/Terrence721/coolify-full/commit/b11c9c56fc04306969d1f1464df68e228054df17))

Found via the same pass above. `create_service()` checked `$request->is_public`/`$request->public_port`, but neither field is in `$allowedFields`/`$validationRules` for this endpoint, so the branch can never fire. Removed the dead branch.
