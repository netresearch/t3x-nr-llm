# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `CHANGELOG.md`, `CODEOWNERS`, GitHub issue templates (bug report, feature request).
- External JavaScript files for the Test and WizardChainPreview backend templates
  (replaces inline `<script>` tags to satisfy Content Security Policy).
- Canonical sections in `AGENTS.md` (Commands, Testing, Development Workflow,
  Architecture, File Map, Critical Constraints, Heuristics, Shared Utilities,
  Golden Samples).

### Changed

- `Build/captainhook.json` is now the documented default location for git
  hooks (configured via `composer.json` `extra.captainhook.config`).
- `Makefile` test/quality targets delegate to `Build/Scripts/runTests.sh -s
  <suite>` instead of invoking PHPUnit / PHPStan / Rector directly.
- `Build/FunctionalTests.xml` testsuite names normalised to `functional` and
  `e2e-backend` (lowercase, conventional).
- E2E test fixtures use vault-UUID-style placeholders or runtime-built
  prefix concatenations rather than literal API-key strings.

### Fixed

- `Resources/Public/Icons/Extension.svg` brand colour corrected to the official
  Netresearch teal `#2F99A4` (was `#2999a4` typo).

### BREAKING

- The following classes are now `final` (and `final readonly` where applicable)
  and can no longer be subclassed by downstream extensions: the four leaf
  provider exceptions (`ProviderConfigurationException`,
  `ProviderConnectionException`, `ProviderResponseException`,
  `UnsupportedFeatureException`); the four feature services
  (`Service/Feature/CompletionService`, `EmbeddingService`,
  `TranslationService`, `VisionService`); the two supporting services
  (`Service/ModelSelectionService`, `Service/PromptTemplateService`).
  Downstream consumers that extended any of these classes should switch to
  composition or open an issue if a documented extension point is needed.
  The base `ProviderException` and `ProviderAdapterRegistry` remain
  non-final pending interface extraction.

## [0.7.0] - 2026-04-22

Initial public release. See git history for prior commits.

[Unreleased]: https://github.com/netresearch/t3x-nr-llm/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.7.0
