Position Alignment — Senior Full‑Stack Developer

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 5, 2026**

This document maps the actual duties and requirements from a real Senior Full-Stack Developer posting (PHP/Laravel + React, automotive-diagnostics domain) to what this repository demonstrates. Every "My Alignment" line points at something checkable in this repo — a commit, a PR, a file, a test count — not a generic skills claim. Where the posting asks for something this repo doesn't show, that's named directly rather than stretched to fit.

## Designs, develops, and maintains scalable web applications using PHP/Laravel and JavaScript/React

JD Requirement:
Designs, develops, and maintains scalable web applications using PHP/Laravel and JavaScript/React.

My Alignment:

Migrated the app's entire UI from Livewire 3 to Inertia.js + React 19, page by page, inside a live Laravel 12 application — not a rewrite, a real incremental migration with both stacks coexisting until each page converted (84 of 84 pages, complete 2026-07-14; both `livewire/livewire` and Alpine.js fully removed from `composer.json`/`package.json` once finished)

Chose Inertia over a decoupled SPA + REST API specifically to avoid designing and versioning a separate API before a single page could move — each converted page stays a normal Laravel route/controller returning props, trading a fully independent frontend deploy for keeping auth/sessions/routing working the whole way through

Full phase-by-phase log with real bugs found and fixed along the way: [`docs/livewire-to-react-migration.md`](docs/livewire-to-react-migration.md)

## Performs code reviews, writes unit and integration tests, and collaborates on pull request changes and feedback

JD Requirement:
Performs code reviews, writes unit and integration tests, and collaborates on pull request changes and feedback.

My Alignment:

67 documented security/reliability findings ([`docs/code-review.md`](docs/code-review.md)), each landed through a real branch, a real Pull Request, and a full quality gate (Pint, PHPStan, Psalm, the full Pest suite) before merge — not a static list, an ongoing process tracked live on [issue #70](https://github.com/Terrence721/coolify-full/issues/70)

A second, independent verification pass ("pseudo peer review") re-reviews already-merged PRs from scratch, checking whether the original fix actually held up — run repeatedly this session; it's caught real gaps more than once, including a case where a fix's own docblock overstated what it had actually reused, and a case where a related null-safety crash the original fix hadn't covered was found and closed in its own follow-up PR

Every regression test is TDD-proved, not just written: the test is confirmed to fail against the pre-fix code for the actual real reason before the fix lands, then confirmed passing after — the same discipline applied to the frontend side, where a suite's own sanity is checked by temporarily breaking the logic under test and confirming the suite catches it

1,443 Pest tests (5,638 assertions) on the backend, 1,306 Vitest/Testing Library component tests across 126 suites on the frontend (121 of 140 components/pages covered — [issue #32](https://github.com/Terrence721/coolify-full/issues/32) tracks the rest, each addition picked for genuine regression risk rather than swept mechanically; components with no real branching logic are deliberately left untested, with the reason recorded)

## Participates in design meetings, discusses tasks with colleagues, reviews, and contributes to company documentation

JD Requirement:
Participates in design meetings, discusses tasks with colleagues, reviews, and contributes to company documentation.

My Alignment:

A real GitHub Projects Scrum board (Backlog/Planned/In Progress/Done), kept genuinely in sync with actual issue and PR state — not decorative; a repo-wide drift sweep this session found and fixed a stale card reference, matching the same standard applied to code

Documentation written in layers on purpose: [`todo.md`](todo.md)'s "At a glance" section is a skimmable summary for anyone who doesn't want the full evidentiary detail underneath it; the detail itself is backed by line numbers, exact commands, and verification steps, not just assertions

Periodic drift sweeps re-verify every number against fresh ground truth (`git log`, live test runs, the Scrum board's real state) rather than trusting what a doc claimed last time it was touched — caught and fixed real gaps this way more than once, including in this file

## Monitors and manages product infrastructure, using tools such as Terraform and Azure DevOps to inform future development and improvements

JD Requirement:
Monitors and manages product infrastructure, using tools such as Terraform and Azure DevOps to inform future development and improvements.

My Alignment (honest gap, with a real bridge):

No Terraform, no Azure DevOps in this repo specifically. What is here: multiple purpose-built Docker Compose environments (dev, prod, HTTPS, Windows, and a dedicated smoke-test stack), GitHub Actions as the full CI/CD system (Pint, PHPStan, Psalm, Pest, Vitest, Prettier, an HTML5-structural-validity scan, and CodeQL — [`.github/workflows/`](.github/workflows)), and a genuinely isolated Docker-in-Docker smoke-test host built from scratch specifically because the original test host shared the real dev machine's Docker socket and wasn't safe for destructive testing

Azure DevOps itself bundles three things — work tracking (Boards), CI/CD (Pipelines), and repos. The GitHub-native equivalent of all three is run here directly: the Scrum board above, GitHub Actions, and GitHub itself — not the same vendor, but the same category of work

## Stays current with emerging technologies and industry trends to continuously improve development processes

JD Requirement:
Stays current with emerging technologies and industry trends to continuously improve development processes.

My Alignment:

Current-generation stack throughout, not legacy: Laravel 12, React 19, Tailwind CSS v4, Pest 4, Vitest 4

The larger signal: this repo was built by directing an AI coding agent as part of a real engineering workflow — setting direction and architectural decisions, then independently verifying the agent's output before trusting it (catching, among other things, a misattributed commit author, a security finding that turned out to be a false positive on closer inspection, and a documentation claim that overstated its own fix). Treating that as a real, current engineering skill rather than a shortcut is itself part of staying current.

## Automotive diagnostics experience / understanding of automotive diagnostic tools and processes

JD Requirement:
Minimum three years of experience in automotive diagnostics or related field preferred. Understanding of automotive diagnostic tools and processes is highly desirable.

My Alignment (honest gap, with a real bridge):

No direct automotive-diagnostics experience — named plainly rather than stretched. What does transfer: this repo manages real physical infrastructure over SSH, where a malformed command or a missed authorization check has a real operational consequence, not a cosmetic one — the same underlying discipline (structured protocol handling, validating untrusted input before it drives a workflow, correctness that actually matters) the domain needs, applied to a different kind of hardware

## Previous experience as a Product Owner or in a product management role

JD Requirement:
Previous experience as a Product Owner or in a product management role is highly desirable.

My Alignment (honest gap, with real ownership signals):

No formal Product Owner title. What's here instead: the prioritization discipline running through the entire findings process — a tiered severity system (security first, reliability second, everything else deferred), a maintained backlog of 20 open items each with an explicit priority and status, and real scope decisions about what to fix now versus defer — including choosing not to fully close a DNS-rebinding TOCTOU gap in `SafeWebhookUrl` because the cost of closing it outweighed the actual risk, and documenting that reasoning rather than leaving it silently undone

## Knowledge: programming languages (PHP/Laravel, JavaScript/React, Node.js, MySQL, etc.)

JD Requirement:
Strong technical background in programming languages (such as PHP/Laravel, JavaScript/React, Node.js, MySQL, etc.).

My Alignment:

PHP/Laravel and JavaScript/React: covered throughout this repo, above

Node.js: `coolify-realtime` runs a real, hand-built Node.js WebSocket server (`terminal-server.js`) handling live SSH-terminal sessions — session-cookie/XSRF re-validation against the real Laravel backend on every connection, target-host authorization checked against a server-fetched allowlist rather than trusted from the client. Audited directly during a security review, not boilerplate.

MySQL: honest partial — the platform manages MySQL and MariaDB as first-class supported database engines (provisioning, backups, proxying), but this repo's own engineering work has concentrated on the Postgres-backed application data, so there's no MySQL-specific bug fix to point to yet

## Familiarity with software development tools (Git, JIRA)

JD Requirement:
Experience with Agile development methodologies (e.g., Scrum, Kanban) and familiarity with software development tools (e.g., Git, JIRA).

My Alignment:

Git, well past basic usage: real commit-history correction work this session, including `git commit-tree` reconstruction to fix misattributed commit authorship while proving the content was byte-identical before force-pushing, and rebasing a PR branch mid-review to catch it up with `main` after a sibling PR merged first

JIRA specifically: not used here. GitHub Issues and the GitHub Projects Scrum board (above) serve the same function on this repo — the same category of tool, different vendor

## Ability to architect scalable and maintainable web-based and cloud-based solutions, considering performance, reliability, and safety

JD Requirement:
Ability to architect scalable and maintainable web-based and cloud-based solutions, considering factors such as performance, reliability, and safety.

My Alignment:

Performance: a 3-hop relation join on every team-scoped query was replaced with a direct, indexed `team_id` column — the real cost (two sources of truth now, kept in sync via a save hook, one known gap where that sync doesn't fire) was named out loud rather than hidden. Backend and frontend performance are both covered — a structured scan of 7 known React performance techniques found real value in the two that applied (splitting an overloaded component, virtualizing an unbounded log view) and correctly ruled out the other five rather than applying them for their own sake.

Reliability: the full PHPStan hardening arc — 1,306 down to 55 suppressed errors across 65 phases, every phase verified with a full test run, all 55 remaining entries individually confirmed as analysis-tool limitations rather than left unexamined

Safety, read as security: a large share of the 67 code-review findings are real access-control and credential-handling bugs — cross-tenant privilege escalation across multiple Policy classes, a private-key acceptance gap that could result in one team's real SSH or GitHub App credentials being used against a host an attacker chose, an unauthenticated endpoint capable of relaying arbitrary content through the operator's own server. Each fix is backed by a concrete, traced exploit path, not a theoretical description.

## Strong understanding of Agile methodologies and experience working in a Scrum team / high ability to work independently

JD Requirement:
Strong understanding of Agile methodologies and experience working in a Scrum team. High ability to work independently, requires little management oversight or supervision. Ability to manage multiple objectives and projects simultaneously. Ability to adapt to changing priorities and work in a fast-paced environment.

My Alignment:

The Scrum board (above) runs this whole repo's backlog in practice, not just in name

Independent execution: a standing directive as broad as "find and fix a real security issue in unswept territory" is run end-to-end with no step-by-step instruction — research, independent verification, a TDD-proved fix, a full quality gate, and a PR — with review only happening at the merge decision

Multiple simultaneous threads: this session runs several parallel, recurring workstreams in rotation (the code-review program, the frontend test-coverage initiative, the independent-verification pass, documentation upkeep) rather than one task at a time

Adapting mid-task: real instances this session of being redirected mid-investigation to a different priority, parking the in-progress work cleanly and resuming it later the same session rather than losing it

Attention to detail under pace: a formatter check scoped to just the changed files passed locally, then the full repo-wide check caught a real issue before it reached CI — now run as standard practice before every push, not just when reminded

## Exceptional communication and interpersonal skills, with the ability to articulate technical concepts to non-technical stakeholders

JD Requirement:
Exceptional communication and interpersonal skills, with the ability to articulate technical concepts to non-technical stakeholders.

My Alignment:

This document is itself an example of the skill it's describing — translating a job posting's technical asks into plain, checkable evidence rather than a generic claim

The same layered-documentation instinct described above (a skimmable summary, full detail underneath for anyone who wants to verify a specific line) is the same instinct needed to explain a technical decision to someone who isn't going to read the source code

## Summary

This repository demonstrates the range of competencies a Senior Full-Stack Developer role asks for: incremental modernization of a live Laravel application, a disciplined and independently-verified test/review process, an honestly maintained backlog and Scrum board, and clear documentation of both what's done and what's genuinely still a gap — including, where the posting's domain-specific asks (automotive diagnostics, Terraform/Azure DevOps, Product Owner experience) aren't directly covered here, saying so plainly rather than overclaiming.
