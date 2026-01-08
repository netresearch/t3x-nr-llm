# Security Controls for Solo Maintainer Project

This document describes compensating security controls implemented for this solo-maintainer project,
following [OpenSSF Best Practices](https://www.bestpractices.dev/) and
[Solo Maintainer Guidelines](https://github.com/ossf/wg-best-practices-os-developers).

## Code Review Compensating Controls

Since traditional two-person code review is not possible for a solo maintainer, the following
automated controls are enforced on all changes:

### Automated Quality Gates

| Control | Tool | Enforcement |
|---------|------|-------------|
| Static Analysis | PHPStan (level max) | CI required to pass |
| Code Style | PHP-CS-Fixer | CI required to pass |
| Security Scanning | CodeQL, Gitleaks | CI required to pass |
| Dependency Review | dependency-review-action | Blocks PRs with vulnerabilities |
| Test Coverage | PHPUnit + pcov | 80%+ coverage required |
| Rector | Automated refactoring checks | CI required to pass |

### Security Scanning

- **CodeQL**: Semantic code analysis on every push
- **Gitleaks**: Secret detection in all commits
- **Composer Audit**: Weekly vulnerability checks
- **Dependency Review**: PR-level CVE and license checks

### Branch Protection

The `main` branch has the following protections:
- Require status checks to pass before merging
- Require branches to be up to date before merging
- Require signed commits (when GPG key is configured)
- Include administrators in restrictions

## Fuzzing

The project includes property-based fuzzy testing via PHPUnit:

```bash
composer test:fuzzy
```

This covers:
- Random input generation for API endpoints
- Malformed JSON handling
- Unicode and encoding edge cases
- Boundary value testing

Full OSS-Fuzz integration is not implemented as the project is a TYPO3 extension
(PHP) which has limited OSS-Fuzz support.

## Supply Chain Security

### Signed Releases

All releases include:
- Cosign keyless signatures (Sigstore)
- SHA256 checksums
- SBOM in SPDX and CycloneDX formats
- SLSA Level 3 provenance attestations

### Dependency Management

- Dependabot enabled for automated updates
- composer.lock committed for reproducible builds
- All GitHub Actions pinned with SHA hashes
- Weekly dependency audits

## Succession Planning

In the event of maintainer unavailability:

1. **Repository Access**: Netresearch GmbH organization admins have backup access
2. **Documentation**: All architecture decisions documented in code and docs
3. **Standard Tooling**: Uses standard TYPO3/PHP ecosystem tools
4. **No Tribal Knowledge**: All processes automated in CI/CD

## Contact

For security concerns, see [SECURITY.md](../SECURITY.md).
