# Makefile for nr_llm TYPO3 extension development
# Generated according to TYPO3 DDEV Skill best practices

.PHONY: help up start down restart install install-all sync seed seed-tasks ollama test test-unit test-integration test-functional test-fuzzy test-e2e coverage mutation lint lint-fix phpstan rector docs clean ci

# Default target
help:
	@echo "nr_llm - TYPO3 LLM Extension Development"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "Quick Start:"
	@echo "  up          ONE COMMAND - complete setup (DDEV + TYPO3 + docs + Ollama)"
	@echo ""
	@echo "Environment:"
	@echo "  start       Start DDEV only (no installation)"
	@echo "  down        Stop DDEV environment"
	@echo "  restart     Restart DDEV environment"
	@echo "  install     Install TYPO3 v14 with extension"
	@echo "  install-all Install all supported TYPO3 versions (v14)"
	@echo "  sync        Sync database schema (run extension:setup)"
	@echo "  seed        Import Ollama seed data (provider, models, configs)"
	@echo "  seed-tasks  Import task seed data (one-shot prompts)"
	@echo "  ollama      Check Ollama status and available models"
	@echo ""
	@echo "Testing:"
	@echo "  test            Run all tests (unit, integration, fuzzy)"
	@echo "  test-unit       Run unit tests only"
	@echo "  test-integration Run integration tests only"
	@echo "  test-func       Run functional tests (SQLite)"
	@echo "  test-fuzzy      Run fuzzy/property-based tests"
	@echo "  test-e2e        Run Playwright E2E tests"
	@echo "  coverage        Run tests with coverage report"
	@echo "  mutation        Run mutation testing with Infection"
	@echo ""
	@echo "Quality:"
	@echo "  lint        Check code style (dry-run)"
	@echo "  lint-fix    Fix code style issues"
	@echo "  phpstan     Run static analysis"
	@echo "  rector      Run Rector refactoring (dry-run)"
	@echo "  rector-fix  Apply Rector refactoring"
	@echo "  ci          Run all CI checks"
	@echo ""
	@echo "Documentation:"
	@echo "  docs        Render RST documentation"
	@echo ""
	@echo "Maintenance:"
	@echo "  clean       Remove generated files"

# ONE COMMAND - complete setup
up: start install docs ollama
	@echo ""
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo "✅ Setup complete!"
	@echo ""
	@echo "🌐 TYPO3 Backend: https://v14.nr-llm.ddev.site/typo3/"
	@echo "   Username: admin | Password: Joh316!!"
	@echo ""
	@echo "📚 Documentation: https://docs.nr-llm.ddev.site/"
	@echo ""
	@echo "🤖 LLM ready: Local Ollama with qwen3:0.6b"
	@echo "   Test: ddev ollama chat"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Environment targets
start:
	ddev start

down:
	ddev stop

restart:
	ddev restart

install:
	ddev install-v14

install-all:
	ddev install-all

sync:
	ddev exec -d /var/www/html/v14 vendor/bin/typo3 extension:setup
	ddev exec -d /var/www/html/v14 vendor/bin/typo3 cache:flush

seed:
	ddev seed-ollama

seed-tasks:
	ddev seed-tasks

ollama:
	@echo "🤖 Ollama Status:"
	@ddev ollama list || echo "   Model not yet pulled. Run: ddev ollama pull"

# Testing targets
test:
	ddev exec "cd /var/www/nr_llm && .Build/bin/phpunit -c phpunit.xml --testsuite unit,integration,fuzzy"

test-unit:
	ddev exec "cd /var/www/nr_llm && .Build/bin/phpunit -c phpunit.xml --testsuite unit"

test-integration:
	ddev exec "cd /var/www/nr_llm && .Build/bin/phpunit -c phpunit.xml --testsuite integration"

test-func:
	ddev exec "cd /var/www/nr_llm && .Build/bin/phpunit -c Build/FunctionalTests.xml"

test-fuzzy:
	ddev exec "cd /var/www/nr_llm && .Build/bin/phpunit -c phpunit.xml --testsuite fuzzy"

test-e2e:
	cd Tests/E2E/Playwright && npm run test

coverage:
	ddev exec "cd /var/www/nr_llm && XDEBUG_MODE=coverage .Build/bin/phpunit -c phpunit.xml --coverage-html .Build/coverage"
	@echo "Coverage report: .Build/coverage/index.html"

mutation:
	ddev exec "cd /var/www/nr_llm && .Build/bin/infection --configuration=infection.json.dist --threads=4 --show-mutations --no-progress"

# Quality targets
lint:
	ddev lint

lint-fix:
	ddev exec "cd /var/www/nr_llm && .Build/bin/php-cs-fixer fix"

phpstan:
	ddev phpstan

rector:
	ddev exec "cd /var/www/nr_llm && .Build/bin/rector process --config Build/rector/rector.php --dry-run"

rector-fix:
	ddev exec "cd /var/www/nr_llm && .Build/bin/rector process --config Build/rector/rector.php"

ci: lint phpstan test-unit test-integration test-fuzzy
	@echo "✅ All CI checks passed"

ci-full: ci test-func
	@echo "✅ Full CI checks passed (including functional tests)"

# Documentation targets
docs:
	ddev docs

# Maintenance targets
clean:
	rm -rf .Build/coverage
	rm -rf .Build/logs/infection*
	rm -rf .Build/cache/infection
	rm -rf Documentation-GENERATED-temp
	rm -rf var/cache
