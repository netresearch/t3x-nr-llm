<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-23 -->

# AGENTS.md — .ddev

<!-- AGENTS-GENERATED:START overview -->
## Overview
DDEV local development environment configuration. **Use the `typo3-ddev` skill** for setup and multi-version testing.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
ddev start && ddev composer install
ddev describe   # print URLs and credentials
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `config.yaml` | Main DDEV configuration |
| `docker-compose.*.yaml` | Custom service overrides |
| `commands/host/` | Host-side custom commands |
| `commands/web/` | Container-side custom commands |
| `.env` | Environment variables |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START commands -->
## Commands
| Task | Command |
|------|---------|
| Start | `ddev start` |
| Stop | `ddev stop` |
| SSH into container | `ddev ssh` |
| Run composer | `ddev composer ...` |
| Database export | `ddev export-db > dump.sql.gz` |
| Database import | `ddev import-db < dump.sql.gz` |
| View logs | `ddev logs` |
| Restart | `ddev restart` |
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Common patterns
- Use `ddev composer` instead of local composer
- Custom commands in `.ddev/commands/` for project-specific tasks
- Override services with `docker-compose.*.yaml` files
- Use `ddev describe` to see URLs and credentials
- Multi-version testing: change `php_version` in config.yaml
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START code-style -->
## Style
- Keep `config.yaml` minimal, use overrides for complexity
- Document custom commands with `## Description:` header
- Use `#ddev-generated` comment for files DDEV manages
- Pin addon versions for reproducibility
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START security -->
## Security
- `.env` is local-only; never commit real credentials
- Treat DDEV as a dev sandbox; do not expose ports to the internet
- Use the `nr-vault` extension for storing API keys, not DDEV env vars
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] `ddev start` works after changes
- [ ] Custom commands have descriptions
- [ ] No hardcoded paths or credentials
- [ ] Works on macOS, Linux, and Windows (WSL2)
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> See the actual config and commands:
> - `.ddev/config.yaml` — main DDEV configuration
> - `.ddev/commands/` — custom commands
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START skill-reference -->
## Resources
> For DDEV setup, TYPO3 multi-version testing, and custom commands:
> **Invoke skill:** `typo3-ddev`
<!-- AGENTS-GENERATED:END skill-reference -->
