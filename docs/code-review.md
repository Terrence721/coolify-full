# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 30, 2026**

> [!CAUTION]
> This is a simulation of real-world code review.

Every finding below went through a real GitHub Pull Request: a branch, a review comment on the diff, and a real merge — see [issue #70](https://github.com/Terrence721/coolify-full/issues/70) for the live tracking card. This table is a readable historical index, not the live mechanism.

| File / Lines | Priority | Category | Finding | Status |
|---|---|---|---|---|
| [`TerminalController.php:22,46`](https://github.com/Terrence721/coolify-full/blob/d25f580d43decef742c79d6f905caa492becb89f/app/Http/Controllers/TerminalController.php#L22-L46) | medium | Maintainability | The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` and `connect()`. If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other. Suggested fix: extract into a shared `eligibleServers(): Collection` method. | **Fixed** via [PR #72](https://github.com/Terrence721/coolify-full/pull/72) ([`b49242cbe`](https://github.com/Terrence721/coolify-full/commit/b49242cbe8efdbc83fc9a788d8e2bb1f854b693b)) |
| [`ServiceExtraFieldsResolver.php:389-413`](https://github.com/Terrence721/coolify-full/blob/a97a0cb9aba0a20720ee1ec8ee495e5790d1482f/app/Services/ServiceExtraFieldsResolver.php#L389-L413) | high | Reliability | The `kong` switch case builds dashboard user/password fields but labels the group `'Supabase'` instead of `'Kong'` — a real, user-facing mislabel. It's also missing a `break;`, so PHP falls through into the next case (`minio`) unconditionally, running MiniO's whole field block for a Kong service too and producing a spurious, mostly-empty "MinIO" group alongside the mislabeled one. Suggested fix: rename to `'Kong'` and add the missing `break;`. | **Fixed** via [PR #73](https://github.com/Terrence721/coolify-full/pull/73) ([`27941b8d6`](https://github.com/Terrence721/coolify-full/commit/27941b8d6be339f558410331620667cf43e53d14)) |
| [`GetContainersStatus.php:346-370`](https://github.com/Terrence721/coolify-full/commit/abb1fad2879eb76e09e8ec76c89e3c2d4e6f852f#commitcomment-194339984) | medium | Maintainability | Both the exited-service and exited-database branches computed `containerName`/`projectUuid`/`url` etc. purely to feed a `notify()` call that's been commented out since the original upstream import — dead work on a hot polling path. `ContainerStopped` is still real, wired infrastructure elsewhere, so this wasn't abandoned code to delete outright. Suggested fix: remove the unused computation rather than silently re-enable a disabled notification. | **Fixed** via [PR #74](https://github.com/Terrence721/coolify-full/pull/74) ([`fb880789a`](https://github.com/Terrence721/coolify-full/commit/fb880789a86b6c7c42dcaca5d85f18d4eca7f47a)) |
