# 🗺️ Roadmap

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 24, 2026**

This file is for a different kind of item than [`todo.md`](todo.md). `todo.md` tracks **known work**: things already found, scoped, and either done or actionable right now, each backed by evidence. This file tracks **product-direction ideas** — real gaps identified by reading the code, not yet scoped into a work item, kept here so they don't get lost between sessions. Once an idea here is picked up, it gets a GitHub issue and moves to `todo.md`'s "Still to do" table like everything else; this file is not itself a place to check work off.

Every item below was found by reading the actual code (file/class named), not asserted from general PaaS-feature intuition — see each entry for what was checked.

## Candidates

| # | Idea | Why it matters | Found via | Issue |
|---|---|---|---|---|
| 1 | Real audit trail for team actions ("Alice deleted server X", "Bob changed env var Y") | Once more than one person has access to a team, no one can answer "who did this" — a common ask the moment a self-hosted instance stops being single-user | `spatie/laravel-activitylog` is installed, but every real usage (`Application.php`, `Service.php`, `RunRemoteProcess.php`, `CoolifyTask.php`, etc.) repurposes its `Activity` model purely as SSH/deployment command-output storage. No model logs settings/resource changes. | #66 |
| 2 | Per-project/environment RBAC, not just team-wide roles | Larger orgs running multiple client/product workspaces on one instance can't limit a member to just their own project — anyone with team access can see and touch everything | `app/Enums/Role.php` — a flat `Member(1) < Admin(2) < Owner(3)` hierarchy, applied uniformly team-wide. No project- or environment-scoped grant anywhere in `app/Policies/`. | #67 |
| 3 | HMAC-signed outbound webhook notifications | A receiver of the generic webhook notification channel has no way to verify a payload genuinely came from this instance and wasn't spoofed | `app/Notifications/Channels/WebhookChannel.php` posts the raw payload to `webhook_url` with no signature/shared-secret header — inconsistent with the *inbound* Git webhooks (GitHub/Gitea/Bitbucket), which do verify signatures correctly | #68 |
| 4 | In-app team switcher | Blocked real multi-user manual testing at least twice already (config-diff masking, admin-restriction checks both had to fall back to automated tests instead of a live second-user session) | `resources/js/Layouts/AppLayout.jsx`'s own docblock documents the gap directly (read-only team name shown instead of a working switcher); not a new finding, just previously untracked as an open item | #69 |
| 5 | Team-wide "require 2FA" enforcement | 2FA exists (Fortify, confirmed enabled with `confirm`/`confirmPassword`) but is opt-in per user — an Owner has no way to require it across the team | `config/fortify.php`'s `Features::twoFactorAuthentication()`; no corresponding team-level enforcement setting anywhere in `Team`/`TeamController` | — |
| 6 | Encryption at rest for S3-stored backups | Backup content currently relies entirely on whatever the destination bucket provides — no application-level encryption before upload | No `gpg`/`age`/equivalent step anywhere in `app/Jobs/DatabaseBackupJob.php` before the S3 upload | — |
| 7 | On-call-paging notification channels (PagerDuty/Opsgenie) | Current channels (Discord/Slack/Telegram/Pushover/generic webhook) are all chat-first; a PaaS pitched on reliability is more often paired with an on-call tool than another chat integration | `app/Notifications/Channels/` — 5 channels present, none paging-oriented | — |

Items 1–4 are scoped enough to become real GitHub issues now (done, see table). Items 5–7 are still speculative — worth a line here so they aren't lost, but not yet broken down enough to be actionable work.
