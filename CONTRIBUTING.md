# Contributing to nr_llm

Thank you for your interest in contributing to the TYPO3 LLM extension!

## Development Setup

### Prerequisites

- PHP 8.5+
- [DDEV](https://ddev.readthedocs.io/) for local development
- Composer

### Getting Started

```bash
# Clone the repository
git clone https://github.com/netresearch/t3x-nr-llm.git
cd t3x-nr-llm

# Start DDEV
ddev start

# Install dependencies
ddev composer install

# Run tests
ddev exec ".Build/bin/phpunit -c phpunit.xml"
```

## Code Quality

Before submitting a PR, ensure all checks pass:

```bash
# Run all CI checks
ddev exec "composer ci"

# Or run individually:
ddev exec ".Build/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run"
ddev exec ".Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon"
ddev exec ".Build/bin/phpunit -c phpunit.xml"
ddev exec ".Build/bin/rector process --config=Build/rector/rector.php --dry-run"
```

## Pull Request Process

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Ensure all tests pass
5. Commit using [conventional commits](https://www.conventionalcommits.org/)
6. Push and open a Pull Request

## Commit Messages

We use conventional commits:

- `feat:` New features
- `fix:` Bug fixes
- `docs:` Documentation changes
- `refactor:` Code refactoring
- `test:` Test changes
- `chore:` Maintenance tasks

## Adding a New Provider

1. Create a new class extending `AbstractProvider`
2. Implement required methods
3. Add tests in `Tests/Unit/Provider/`
4. Update documentation

## Questions?

Open an issue or start a discussion!
