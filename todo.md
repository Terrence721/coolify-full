# 🧾 TODO — Coolify-Full Migration Ledger  
**Last Updated: July 13, 2026**

A living, audited record of what’s done and what remains in this self-hosted-only fork of Coolify.  
This document tracks modernization progress, de-commercialization work, environment corrections, and all verified engineering changes.

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

# 🛠 WSL2 Setup Guide (Reviewer-Friendly)

If developing this fork on Windows, follow these steps:

### 1. Install WSL2 + Ubuntu
```bash
wsl --install -d Ubuntu
