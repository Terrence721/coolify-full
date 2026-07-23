# Security Policy

This repository is a professional portfolio fork of Coolify (see [README.md](README.md)) — a single-maintainer technical demonstration, not a commercially supported product. It's not affiliated with the Coolify team; do not report vulnerabilities found here to coollabs' own security contact, since they have no relationship to this fork's code.

## Supported Versions

There's one supported version: the current state of the `main` branch. This fork does not maintain multiple release lines or backport fixes.

## Reporting a Vulnerability

If you find a security issue in this fork specifically:

- **Preferred**: use GitHub's [private vulnerability reporting](https://github.com/Terrence721/coolify-full/security/advisories/new) (Security tab → "Report a vulnerability"), so details aren't public before a fix lands.
- **Alternative**: [open a regular issue](https://github.com/Terrence721/coolify-full/issues/new) if the finding is already public or doesn't need to stay private (e.g. a dependency CVE already tracked upstream).

There's no bug bounty and no guaranteed response SLA — this is maintained by one person in their own time — but genuine reports will be looked at and, where they apply to this fork's own code (not just inherited upstream behavior), fixed and documented in [`todo.md`](todo.md), the same way every other real bug found during this project's own testing has been.

## Automated Security Tooling

This fork already runs, on every push: [CodeQL](https://github.com/Terrence721/coolify-full/security/code-scanning) (JavaScript static analysis), [Dependabot](https://github.com/Terrence721/coolify-full/security/dependabot) (dependency vulnerability alerts + automated fix PRs), GitHub secret scanning with push protection, and Psalm taint analysis for the PHP backend (`composer psalm`, run manually — see [`CLAUDE.md`](CLAUDE.md)). See `todo.md`'s "GitHub repo-level security features" entry for what each has actually caught.
