# Security assurance

> OpenSSF Best Practices Badge criterion: `security_review`

This page states what is actually verified about nr_llm's security posture,
and by what. It is not an attestation that the extension is free of
vulnerabilities, and it does not carry a "ready for production" verdict.

**There is no current full-scope security audit.** The last one was an
internal self-audit dated 2026-01-05; it has been archived unchanged as
[`Audits/2026-01-05-internal-self-audit.md`](Audits/2026-01-05-internal-self-audit.md)
with the list of statements in it that have since expired. Treat it as a
historical record, not as a description of the shipped code.

## Continuous verification

These run on every push and pull request. Check-run names are as they appear
on a commit's status list.

| What | Check | Blocking |
|---|---|---|
| PHP static analysis, level 10 (`Build/phpstan/phpstan.neon`) | `ci / PHPStan` across the full matrix | yes |
| SAST | `security / SAST (Opengrep)`, `codeql`, `SonarCloud Code Analysis` | Opengrep and CodeQL surface into the Security tab |
| Secret scanning | `gitleaks / Secret Scanning` plus GitHub native secret scanning | yes |
| Dependency vulnerabilities | `security / Composer Audit`, `dependency-review` | yes |
| Workflow hardening | `zizmor / zizmor analysis`, `step-security/harden-runner` | yes |
| Supply-chain posture | `scorecard` | reported |
| Provenance and signing | SLSA Level 3 attestation and Cosign keyless signing on every release, via the org release workflow | release-time |

Everything above is delegated to `netresearch/typo3-ci-workflows` and
`netresearch/.github` reusable workflows; see `.github/workflows/checks.yml`.

## Security properties enforced by the test suite

These are behaviours, not scans — a regression fails a test rather than
raising a finding:

- **API keys never live in the extension.** They are nr-vault UUID
  identifiers under nr-vault's envelope encryption (ADR-012). No
  `sodium_crypto_*` call exists in `Classes/`.
- **Tool access is fail-closed at four layers** — global per-tool state,
  per-group state, per-configuration allowed groups, per-run allow-list —
  where each layer can only narrow (ADR-039, ADR-120).
- **Tool output is data-classified** and cannot cross a declared trust-zone
  ceiling (ADR-094).
- **Writing tools require human approval.** Both shipped writers
  (`update_page_metadata`, `set_file_alternative_text`) ship disabled and
  suspend the run for approval before they act (ADR-134, ADR-135).
- **Secret-bearing output is redacted or gated** behind separate raw variants
  that ship disabled; `settings.php`-class files are structurally unreadable.
- **Network egress is fail-closed** per tool (`ToolEgressScope`).

## Point-in-time reviews since the archived audit

| Date | Scope | Outcome |
|---|---|---|
| 2026-07-19 | Adversarial review of read-tool permission and data-exfiltration boundaries | 13 confirmed findings, all fixed and merged: [#461](https://github.com/netresearch/t3x-nr-llm/pull/461) (workspace drafts), [#463](https://github.com/netresearch/t3x-nr-llm/pull/463) / [#467](https://github.com/netresearch/t3x-nr-llm/pull/467) (secret-egress denylist), [#464](https://github.com/netresearch/t3x-nr-llm/pull/464) (FAL file-mount boundaries), [#466](https://github.com/netresearch/t3x-nr-llm/pull/466) (backend language access) |
| 2026-07-19 | Untrusted-boundary hardening across specialized services and the token store | [#460](https://github.com/netresearch/t3x-nr-llm/pull/460) |

These were scoped reviews of specific subsystems. Neither is a substitute for
a full-scope audit.

## Known open gaps

Deferred security and governance decisions are tracked as open issues labelled
[`deferred`](https://github.com/netresearch/t3x-nr-llm/issues?q=is%3Aissue+is%3Aopen+label%3Adeferred).
An accepted ADR that declines to build something closes the decision, not the
gap — the gap stays open on the issue tracker until it is closed by code.

## Reporting a vulnerability

Use GitHub Private Vulnerability Reporting — see [SECURITY.md](SECURITY.md).
Do not open a public issue for a suspected vulnerability.
