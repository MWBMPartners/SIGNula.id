# ⚙️ GitHub Actions — SIGNula.id

## 🖥️ Runner convention (IMPORTANT)

**Every workflow job MUST use `runs-on: ubuntu-latest`.**
Do **not** use `macos-*`, `windows-*`, or self-hosted runners. All current
workflows comply, and any workflow added in future must follow this — including
a deploy pipeline (see below).

## Current workflows

| File | Purpose | Runner |
|------|---------|--------|
| `ci.yml` | `php -l` lint (blocking, PHP 8.3 + 8.4) + PHPStan/PHPCS (advisory) | `ubuntu-latest` |
| `security.yml` | PR dependency-review + scheduled `composer audit` across all four tiers | `ubuntu-latest` |
| `dependabot-auto-label.yml` | Labels Dependabot **security** PRs on `main` (via GHSA id) | `ubuntu-latest` |
| `backport.yml` | Cherry-picks `security`/`backport`-labelled merges from `main` → `release-candidate`/`beta`/`alpha` | `ubuntu-latest` |

Branch model: `alpha ▸ beta ▸ release-candidate ▸ main`.

## 🚀 Deployment (no workflow yet)

There is currently **no SFTP/rsync deploy GitHub Action**. Deployments are done
locally via the VS Code `ftp-sync` extension (the project targets Dreamhost
shared hosting, which has no CLI/Composer).

**When an automated deploy IS added:**
- Use **`runs-on: ubuntu-latest`** (not macOS/Windows/self-hosted).
- Prefer an SFTP or rsync-over-SSH action (e.g. `wlixcc/SFTP-Deploy-Action`,
  `easingthemes/ssh-deploy`, or `SamKirkland/FTP-Deploy-Action` for plain FTPS),
  pinned to a version tag (Dependabot's `github-actions` ecosystem will keep it
  patched across all four tiers).
- Pull the host / username / SSH key (or FTPS password) from **repo secrets** —
  never hard-code credentials. Gate deploys per-branch/environment as needed.
