# Changelog

All notable changes to this preset are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning is [semantic](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing is tagged yet. `preset.yml` carries `0.1.0` as the version this content will ship as; the section below becomes `[0.1.0] - <date>` when the tag is cut.

### Added

- `speckit.specify` addendum: the TYPO3 and PHP range written out, public API and backward compatibility stated in prose before any file decides it, the security and credential boundary named, whether an installed instance has to act, the suite that proves each requirement, and an explicit out-of-scope list.
- `speckit.plan` addendum: read the root and every scoped `AGENTS.md` governing a touched path, search the ADRs and name what a plan supersedes, decide additive-or-breaking before the API snapshot does, state what already exists, and write accepted trade-offs down.
- `speckit.tasks` addendum: failing tests first, then the API snapshot, ADR, translations for every shipped language, documentation, changelog and any schema step each as their own task, with the project's own gate last.
- Split licensing: MIT for the manifest, CC-BY-SA-4.0 for the prompts and documentation.

### Notes

All three are `append` strategies rather than replacements, so the preset stacks on core or on another preset — `lean` included — without forking anyone else's prompt.

The manifest requires Spec Kit `>=0.16.0`. That is the version whose composition behaviour was read in the source and exercised, not the version that first shipped it; lower the floor once an older release has actually been tested.

[Unreleased]: https://github.com/netresearch/spec-kit-typo3/commits/main
