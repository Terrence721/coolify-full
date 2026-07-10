# TODO

**Last Updated: July 10, 2026**

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
- 53 of 84 full-page Livewire components converted to Inertia + React.
- Easy and Medium buckets: 100% done (25/25).
- Hard bucket: 26 of 59 done, including the shared `Server` navbar/sidebar chrome and six non-`Server`-scoped Hard-bucket pages (`Security\PrivateKey\Index`, `Destination\Index`, `Project\Show`, `Project\Edit`, `Storage\Index`, `Project\Index`).

## Still to do

**Migration**
- 33 Hard-bucket pages remain on Livewire, including 5 of 21 `Server\Navbar`-dependent pages (Terminal command, `Server\Show`, plus Dynamic Configurations/Logs within Proxy and Logs within Sentinel). `Server\Show` and Terminal both need real design work before conversion (embedded Livewire island / WebSocket bridge, respectively) — see Section 64 of the migration doc.
- Every SSH-touching action converted so far has an untested happy-path gap (verified only via safe/validation-rejection paths in Pest) — see `docs/smoketest.md` for the manual QA checklist that closes this gap.
- `/terminal` (still Livewire): observed an endless WebSocket reconnect loop during manual QA on 2026-07-10 (handshake authenticates successfully server-side, then the connection closes abnormally, code 1006). Likely just this dev environment lacking a genuinely reachable SSH target, not a code bug — needs real validation once Terminal is converted. See the note in `docs/smoketest.md`'s Terminal checklist.

**Cleanup opportunities (lower priority, not blocking)**
- Chrome DevTools' Issues tab flagged 14 instances of "A form field element should have an id or name attribute" on the converted Server/Metrics page during manual QA on 2026-07-10 — likely `<input>`/`<select>` elements across several converted React pages missing an explicit `id`/`name` (relying only on a wrapping `<label>` for the accessible name). Worth a dedicated accessibility pass across `resources/js/Pages/` once the migration itself is further along.
- A few still-Livewire components became unreachable from the UI during the navbar trim (`Upgrade`, `Help`/Feedback modal is still used elsewhere so it's fine, `SettingsDropdown`'s "What's New" changelog trigger) — worth a decision on whether to delete them outright or leave them for a future settings/about page.
- `Team::serverLimit()` / `custom_server_limit` still exist as general infrastructure (no longer settable via any billing flow) — could be simplified further if per-team server limits aren't a feature you want at all.
- Once the Livewire→React migration is 100% complete, flip `'livewire' => true` off in `config/debugbar.php` (see conversation history — intentionally left on until then, since it's still useful for the pages that haven't converted yet).

**Verification standing habit**
- Every change in this repo is expected to go through: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse`, `php artisan test --compact`, and `yarn build` before being considered done. See `docs/command.md` for the exact commands.
