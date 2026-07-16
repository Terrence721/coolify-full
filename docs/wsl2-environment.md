# 🖥 WSL2 Dev Environment (archived showcase)

**Last Updated: July 16, 2026**

> **Archival note:** this file is a historical snapshot, not a maintained doc. A GitHub web edit briefly replaced the entire `todo.md` ledger with this WSL2 dev-environment showcase content; the ledger was restored to `todo.md` and this showcase was moved here verbatim (2026-07-13) rather than discarded. Its title used to read "TODO — Coolify-Full Migration Ledger," left over from that accident — fixed here. For the actual up-to-date, authoritative WSL2 migration write-up (including the full root-cause "RESOLVED" section and setup steps), see `docs/command.md`'s "WSL2 migration" section and `DEVELOPING_IN_CONTAINERS_WINDOWS.md` — this file predates and duplicates parts of both, kept only for its performance-comparison tables below.

---

# 🖥 Development Environment Notes (Linux via WSL2)

This migration is developed on **Windows 11 using WSL2 (Ubuntu)** — not native Windows.  
All PHP, Node, Composer, Docker, and Laravel processes run inside the Linux subsystem to ensure production‑accurate behavior.

Key reasons for using WSL2:

- Matches real Linux servers (PHP‑FPM, Nginx, Redis, PostgreSQL)
- Avoids Windows filesystem performance issues and slow bind mounts
- Ensures Docker behaves like production (WSL2 backend)
- Restores Vite HMR responsiveness and Laravel file watcher correctness
- Prevents Windows-specific PHP extension and path inconsistencies

The repository is intentionally checked out on the **WSL filesystem** (`~/projects/...`), not under `C:\...`, to avoid 5–10× slower I/O and degraded Docker/Vite performance.

See `docs/command.md` for the full write-up, including the “RESOLVED” section documenting the root cause and fix.

---

# 📅 Dev Environment Timeline (WSL2 Migration)

| Date | Change | Notes |
|------|--------|-------|
| **2026‑07‑10** | First detection of severe Windows bind‑mount I/O degradation | `yarn build` taking 3+ hours; Pest suite >150s |
| **2026‑07‑11** | Root cause identified | Docker Desktop + Windows filesystem bottleneck |
| **2026‑07‑12** | Repo migrated into WSL2 Ubuntu filesystem | Performance normalized instantly |
| **2026‑07‑12** | PHP OPcache enabled in dev | Additional per-request performance gains |
| **2026‑07‑13** | Documentation updated | Added WSL2 migration notes to `docs/command.md` |

---

# ⚡ Performance Improvements After WSL2 Migration

| Operation | Before (Windows bind mount) | After (WSL2 native FS) |
|----------|------------------------------|-------------------------|
| `yarn build` | **3+ hours** | **~2 seconds** |
| Full Pest test suite | **150–170 seconds** | **~31 seconds** |
| Vite HMR | Frequently stalled / unresponsive | Instant reloads |
| PHPStan | Noticeably slow | Normalized |
| Docker container I/O | High latency | Stable, Linux‑correct |

---

# ⚠ Known Windows Pitfalls (All Avoided via WSL2)

- **Windows bind mounts are 5–10× slower** than Linux ext4  
- **Docker Desktop virtualization overhead** causes degraded I/O  
- **Node/Vite file watchers break or stall** under Windows FS  
- **Laravel queue workers behave inconsistently**  
- **PHP extensions differ between Windows and Linux builds**  
- **Composer installs are slower** due to filesystem latency  
- **Long-running processes (Redis, PostgreSQL)** show degraded concurrency  
- **Strict types + Windows path handling** can cause subtle bugs  

WSL2 eliminates all of these issues.

---

# 🛠 WSL2 Setup Guide

This section was cut off mid-step in the original web edit and was never completed — rather than guess at the missing steps, see `DEVELOPING_IN_CONTAINERS_WINDOWS.md` for the actual, complete, verified setup guide (install WSL2 + Ubuntu, clone into the Linux filesystem, connect VS Code via Remote - WSL, run the dev stack from there).
