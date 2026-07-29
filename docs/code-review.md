# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 29, 2026**

> [!CAUTION]
> This is a simulation of real-world code review.

| File / Lines | Priority | Category | Finding | Status |
|---|---|---|---|---|
| [`TerminalController.php:22,46`](https://github.com/Terrence721/coolify-full/blob/d25f580d43decef742c79d6f905caa492becb89f/app/Http/Controllers/TerminalController.php#L22-L46) | medium | Maintainability | The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` and `connect()`. If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other. Suggested fix: extract into a shared `eligibleServers(): Collection` method. | **Fixed** in [`17bba9bcb`](https://github.com/Terrence721/coolify-full/commit/17bba9bcb3762e7c93077a2a4c3621cc2049f3cb) |
