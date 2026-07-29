# Code Review Results

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 29, 2026**

Real line-level code review notes: what was flagged, why, and how it was fixed. Reviewer and engineer are kept separate — see `todo.md` for that convention.

## `/app/Http/Controllers/TerminalController.php`

### Position: `22:0-22:119|46:0-46:119`

- Priority: `medium`
- Title: `Duplicated "eligible servers" query in index() and connect()`
- Category: `Maintainability`
- Description: The exact same query — `Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values()` — is repeated verbatim in `index()` (line 22) and `connect()` (line 46). If the eligibility rule for terminal access ever changes, it's easy to update one call site and miss the other, silently diverging what a user sees in the dropdown from what `connect()` actually allows.
- Additional Info: Extract into a shared private method, e.g. `eligibleServers(): Collection`, and call it from both places.
- SHA: `d25f580d43decef742c79d6f905caa492becb89f`

**Fixed** in [`3fdc985f2`](https://github.com/Terrence721/coolify-full/commit/3fdc985f2a802dfda50fb28187f6001739357146) — extracted into `eligibleServers()`, called from both `index()` and `connect()`.
