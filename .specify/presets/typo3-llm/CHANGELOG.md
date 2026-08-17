# Changelog

All notable changes to this preset are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning is [semantic](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing is tagged yet. `preset.yml` carries `0.1.0` as the version this content will ship as; this section becomes `[0.1.0] - <date>` when the tag is cut.

### Added

- `speckit.specify` addendum: the assumed provider capability, the behaviour when a provider lacks it and whether fallback is automatic or opt-in, how the caller learns which path answered, what leaves the instance and to whom, where untrusted model output goes, cost and what is stored when the provider reports nothing, the assertion to use given the output varies, and the behaviour on model drift.
- `speckit.plan` addendum: which providers actually have the capability checked in their adapters, enforcement through the project's existing chain rather than around it, every boundary untrusted output crosses, what is logged, which column can carry an absent measurement, and the assertion shape together with what a mocked suite therefore leaves untested.
- `speckit.tasks` addendum: a test per fallback branch including the exhausted one, an assertion that an absent measurement stays absent, a test at every named boundary, the invariant rather than the wording, and reading one real answer end to end before the gate.
- `.gitattributes` excluding the `CLAUDE.md` / `GEMINI.md` symlinks from the archive, because `safe_extract_archive` rejects any symlink and the install fails before reading anything.

### Notes

All three entries are `append` strategies. The preset stacks on `spec-kit-typo3` at a lower priority number (3 against 5), which is what makes its content land last.

Nothing here restates a rule that lives in a consuming repository's own `AGENTS.md`. The base preset already sends the agent there to read it, and a second copy would drift from the first.

[Unreleased]: https://github.com/netresearch/spec-kit-typo3-llm/commits/main
