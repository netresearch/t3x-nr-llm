## Description

<!-- Describe your changes in detail -->

## Related Issue

<!-- Link to the issue this PR addresses: Fixes #123 -->

## Type of Change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [ ] Documentation update
- [ ] Refactoring (no functional changes)

## Checklist

- [ ] My code follows the project's coding standards
- [ ] I have run `make gate` and all checks pass (not `composer ci` — that set omits Rector and the functional suite)
- [ ] I have added tests that prove my fix/feature works
- [ ] I have updated the documentation accordingly
- [ ] I have added a `CHANGELOG.md` entry under `## [Unreleased]`
- [ ] If this changes the public surface, the ADR under `Documentation/Adr/` landed before this PR (see step 1 of the Development Workflow in `AGENTS.md`)
- [ ] My changes generate no new warnings
