# AGENTS.md

> AI agent guide for the `nr_llm` TYPO3 extension

## Project Overview

This is a TYPO3 v14 extension providing a unified LLM (Large Language Model) provider abstraction layer. It supports multiple AI providers (OpenAI, Claude, Gemini, etc.) through a standardized interface.

**Key characteristics:**
- PHP 8.5+ / TYPO3 v14
- PHPStan level 10 strict typing
- Three-tier architecture: Providers → Models → Configurations
- Encrypted API key storage (sodium_crypto_secretbox)

## Directory Structure

```
nr_llm/
├── Classes/                    # PHP source code
│   ├── Controller/Backend/     # Backend module controllers
│   ├── Domain/Model/           # Domain entities (Provider, Model, Configuration)
│   ├── Domain/Repository/      # Extbase repositories
│   ├── Provider/               # LLM provider adapters (OpenAI, Claude, Gemini...)
│   ├── Service/                # Business logic services
│   │   ├── Feature/            # High-level feature services (Vision, Translation...)
│   │   ├── Option/             # Request option DTOs
│   │   └── SetupWizard/        # Setup wizard services
│   └── Specialized/            # Specialized services (DeepL, Speech...)
├── Configuration/              # TYPO3 configuration
│   ├── Backend/                # Backend routes and modules
│   ├── TCA/                    # Table Configuration Array
│   └── TypoScript/             # TypoScript setup
├── Documentation/              # RST documentation (TYPO3 docs standard)
│   └── Adr/                    # Architecture Decision Records
├── Tests/
│   ├── Unit/                   # Unit tests (PHPUnit 12)
│   ├── Integration/            # Integration tests
│   ├── Functional/             # TYPO3 functional tests
│   ├── Fuzzy/                  # Property-based tests (Eris)
│   ├── E2E/                    # Playwright E2E tests
│   └── Architecture/           # PHPat architecture tests
├── Build/                      # Build configuration
│   ├── phpstan/                # PHPStan config
│   ├── rector/                 # Rector config
│   └── fractor/                # Fractor config
└── Resources/                  # Assets and templates
```

## Coding Standards

### PHP Style
- **PSR-12** with TYPO3 conventions via PHP-CS-Fixer
- **Strict types**: All files must have `declare(strict_types=1);`
- **Typed properties**: All class properties must be typed
- **Return types**: All methods must have return type declarations

### Architecture Rules (enforced by PHPat)
- Controllers must not depend on Repositories directly
- DTOs must be readonly and contain only typed properties
- Domain models in `Domain/Model/` are excluded from mutation testing

### Commit Messages
Conventional commits format enforced by GrumPHP:
```
feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert|security(scope)?: message
```

## Testing Requirements

Tests run in Docker containers using TYPO3 core-testing images for consistent environments.

| Test Type | Location | Command | When Required |
|-----------|----------|---------|---------------|
| Unit | `Tests/Unit/` | `./Build/Scripts/runTests.sh -s unit` | All new code |
| Integration | `Tests/Integration/` | `./Build/Scripts/runTests.sh -s integration` | API interactions |
| Functional | `Tests/Functional/` | `./Build/Scripts/runTests.sh -s functional` | TYPO3 integration |
| Fuzzy | `Tests/Fuzzy/` | `./Build/Scripts/runTests.sh -s fuzzy` | Input validation |
| Mutation | - | `./Build/Scripts/runTests.sh -s mutation` | Critical paths |
| E2E | `Tests/E2E/` | `./Build/Scripts/runTests.sh -s e2e` | UI workflows |
| Architecture | `Tests/Architecture/` | `./Build/Scripts/runTests.sh -s architecture` | Layer constraints |

**Coverage requirements:**
- Minimum MSI: 70%
- Minimum Covered MSI: 74%

## Key Files

| File | Purpose |
|------|---------|
| `ext_emconf.php` | Extension metadata (auto-maintained by Rector) |
| `ext_localconf.php` | Extension bootstrap |
| `composer.json` | Dependencies (note: composer.lock NOT committed) |
| `phpunit.xml` | PHPUnit configuration |
| `infection.json.dist` | Mutation testing config |
| `grumphp.yml` | Pre-commit hooks |

## Provider Implementation

To add a new LLM provider:

1. Create class in `Classes/Provider/` extending `AbstractProvider`
2. Implement required interfaces (`VisionCapableInterface`, `StreamingCapableInterface`, etc.)
3. Add adapter type to `AdapterType` enum
4. Register via dependency injection
5. Add unit tests in `Tests/Unit/Provider/`
6. Add integration tests in `Tests/Integration/Provider/`

## Running Quality Checks

```bash
# Docker-based test runner (recommended)
./Build/Scripts/runTests.sh -s unit        # Unit tests
./Build/Scripts/runTests.sh -s functional  # Functional tests
./Build/Scripts/runTests.sh -s phpstan     # Static analysis
./Build/Scripts/runTests.sh -s cgl -n      # PHP-CS-Fixer (check, dry-run)
./Build/Scripts/runTests.sh -s cgl         # PHP-CS-Fixer (fix)
./Build/Scripts/runTests.sh -s rector -n   # Rector (dry-run)

# Composer scripts (local PHP, for quick checks)
composer ci            # All CI checks
composer lint          # PHP-CS-Fixer (dry-run)
composer phpstan       # Static analysis
composer test:unit     # Unit tests

# Fix formatting
composer lint:fix
composer rector
```

## Security Considerations

- **API keys** are encrypted using `sodium_crypto_secretbox` with domain-separated key derivation
- **Never** log or expose API keys in error messages
- **Always** sanitize user input before sending to LLM providers
- **Treat** LLM responses as untrusted content

## CI/CD

Workflows in `.github/workflows/`:
- `ci.yml` - Main CI (lint, PHPStan, tests, Rector)
- `security.yml` - Security scanning (Gitleaks, dependency review, composer audit)
- `release.yml` - Release with SBOM, Cosign signing, SLSA attestation
- `scorecard.yml` - OpenSSF Scorecard
- `e2e.yml` - Playwright E2E tests
- `docs.yml` - Documentation rendering

## Useful Commands

```bash
# Development
ddev start                              # Start DDEV environment
composer install                        # Install dependencies
./Build/Scripts/runTests.sh -s unit     # Run unit tests

# Testing (Docker-based, recommended)
./Build/Scripts/runTests.sh -s unit            # Unit tests
./Build/Scripts/runTests.sh -s functional      # Functional tests
./Build/Scripts/runTests.sh -s functional -d mariadb  # With MariaDB
./Build/Scripts/runTests.sh -s mutation        # Mutation testing
./Build/Scripts/runTests.sh -s e2e             # Playwright E2E

# Maintenance
./Build/Scripts/runTests.sh -s rector          # Apply Rector fixes
./Build/Scripts/runTests.sh -s cgl             # Fix code style
.Build/bin/grumphp git:init                    # Install git hooks
```

## Contact

- **Issues**: https://github.com/netresearch/t3x-nr-llm/issues
- **Security**: [GitHub Security Advisories](https://github.com/netresearch/t3x-nr-llm/security/advisories/new)
- **Maintainer**: [https://github.com/netresearch/t3x-nr-llm](https://github.com/netresearch/t3x-nr-llm))
