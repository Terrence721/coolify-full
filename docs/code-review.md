# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 30, 2026**

> [!CAUTION]
> This is a simulation of real-world code review.

| File / Lines | Priority | Category | Finding | Status |
|---|---|---|---|---|
| [`TerminalController.php:22,46`](https://github.com/Terrence721/coolify-full/blob/d25f580d43decef742c79d6f905caa492becb89f/app/Http/Controllers/TerminalController.php#L22-L46) | medium | Maintainability | The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` and `connect()`. If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other. Suggested fix: extract into a shared `eligibleServers(): Collection` method. | **Fixed** via [PR #72](https://github.com/Terrence721/coolify-full/pull/72) ([`b49242cbe`](https://github.com/Terrence721/coolify-full/commit/b49242cbe8efdbc83fc9a788d8e2bb1f854b693b)) |
| [`ServiceExtraFieldsResolver.php:389-413`](https://github.com/Terrence721/coolify-full/blob/a97a0cb9aba0a20720ee1ec8ee495e5790d1482f/app/Services/ServiceExtraFieldsResolver.php#L389-L413) | high | Reliability | The `kong` switch case builds dashboard user/password fields but labels the group `'Supabase'` instead of `'Kong'` — a real, user-facing mislabel. It's also missing a `break;`, so PHP falls through into the next case (`minio`) unconditionally, running MiniO's whole field block for a Kong service too and producing a spurious, mostly-empty "MinIO" group alongside the mislabeled one. Suggested fix: rename to `'Kong'` and add the missing `break;`. | Open |
