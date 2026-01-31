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

# Testing targets (Docker-based, CI-compatible)
test:
	./Build/Scripts/runTests.sh -s unit
	./Build/Scripts/runTests.sh -s integration
	./Build/Scripts/runTests.sh -s fuzzy

test-unit:
	./Build/Scripts/runTests.sh -s unit

test-integration:
	./Build/Scripts/runTests.sh -s integration

test-func:
	./Build/Scripts/runTests.sh -s functional

test-fuzzy:
	./Build/Scripts/runTests.sh -s fuzzy

test-e2e:
	./Build/Scripts/runTests.sh -s e2e

coverage:
	./Build/Scripts/runTests.sh -s unitcoverage
	@echo "Coverage report: .Build/coverage/html-unit/index.html"

mutation:
	./Build/Scripts/runTests.sh -s mutation

# Quality targets (Docker-based, CI-compatible)
lint:
	./Build/Scripts/runTests.sh -s cgl -n

lint-fix:
	./Build/Scripts/runTests.sh -s cgl

phpstan:
	./Build/Scripts/runTests.sh -s phpstan

rector:
	./Build/Scripts/runTests.sh -s rector -n

rector-fix:
	./Build/Scripts/runTests.sh -s rector

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
