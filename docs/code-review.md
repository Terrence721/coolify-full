# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 29, 2026**

Real line-level code review notes: what was flagged, why, and how it was fixed. One person finds and writes up issues, a separate person fixes them — kept apart on purpose, like a real reviewer/engineer split.

| File / Lines | Priority | Category | Finding | Status |
|---|---|---|---|---|
| [`TerminalController.php:22,46`](https://github.com/Terrence721/coolify-full/blob/d25f580d43decef742c79d6f905caa492becb89f/app/Http/Controllers/TerminalController.php#L22-L46) | medium | Maintainability | The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` and `connect()`. If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other. Suggested fix: extract into a shared `eligibleServers(): Collection` method. | **Fixed** in [`3fdc985f2`](https://github.com/Terrence721/coolify-full/commit/3fdc985f2a802dfda50fb28187f6001739357146) |
