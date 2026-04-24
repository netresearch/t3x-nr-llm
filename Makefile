# Makefile for nr_llm TYPO3 extension development
# Generated according to TYPO3 DDEV Skill best practices

-include .Build/vendor/netresearch/typo3-ci-workflows/Makefile.include

RUNTESTS := Build/Scripts/runTests.sh

.PHONY: help up start down restart install install-all sync seed seed-tasks ollama test test-unit test-integration test-func test-fuzzy test-e2e coverage mutation cgl cgl-fix phpstan rector rector-fix docs clean ci ci-full

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

# ONE COMMAND — delegates to the DDEV-native canonical setup script.
# `.ddev/commands/web/setup` is the single source of truth (Netresearch
# convention across all TYPO3 projects). Makefile just provides a host-
# side alias for users who type `make` first.
up:
	ddev start
	ddev setup

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

# Testing targets (use runTests.sh Docker runner exclusively)
test:
	$(RUNTESTS) -s unit
	$(RUNTESTS) -s integration
	$(RUNTESTS) -s fuzzy

test-unit:
	$(RUNTESTS) -s unit

test-integration:
	$(RUNTESTS) -s integration

test-func:
	$(RUNTESTS) -s functional

test-fuzzy:
	$(RUNTESTS) -s fuzzy

test-e2e:
	$(RUNTESTS) -s e2e

coverage:
	$(RUNTESTS) -s unitCoverage
	@echo "Coverage report: .Build/coverage/html-unit/index.html"

mutation:
	$(RUNTESTS) -s mutation

# Quality targets
cgl: ## Check code style (dry-run)
	$(RUNTESTS) -s cgl -n

cgl-fix: ## Fix code style
	$(RUNTESTS) -s cgl

phpstan: ## Run PHPStan static analysis
	$(RUNTESTS) -s phpstan

rector: ## Run Rector dry-run
	$(RUNTESTS) -s rector -n

rector-fix:
	$(RUNTESTS) -s rector

ci: cgl phpstan test-unit test-integration test-fuzzy
	@echo "All CI checks passed"

ci-full: ci test-func
	@echo "Full CI checks passed (including functional tests)"

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
