# Security assurance

> OpenSSF Best Practices Badge criterion: `security_review`

This page states what is actually verified about nr_llm's security posture,
and by what. It is not an attestation that the extension is free of
vulnerabilities, and it does not carry a "ready for production" verdict.

**There is no current full-scope security audit.** The last one was an
internal self-audit dated 2026-01-05; it has been archived unchanged as
[`Audits/2026-01-05-internal-self-audit.md`](https://github.com/netresearch/t3x-nr-llm/blob/main/Audits/2026-01-05-internal-self-audit.md)
with the list of statements in it that have since expired. Treat it as a
historical record, not as a description of the shipped code. (The link is
absolute because `Audits/` is `export-ignore`d and absent from the distributed
package.)

## Continuous verification

These run on every push and pull request. Check-run names are as they appear on
a commit's status list. **Blocking** means the context is in the 16 required by
the `main-branch-rules` ruleset, either directly or through the
`All security checks` gate — read the current list with
`gh api repos/netresearch/t3x-nr-llm/rules/branches/main`, not the legacy
branch-protection endpoint.

`All security checks` is one required context standing for nine jobs. It fails
unless every job it depends on finished `success` or `skipped`, so the checks
listed under it below block a merge even though their own names are not in the
required list.

| What | Check | Blocking |
|---|---|---|
| PHP static analysis, level 10 (`Build/phpstan/phpstan.neon`) | `ci / PHPStan (8.4, ^13.4)` and `(8.4, ^14.3)` | yes — those two cells |
| PHP SAST | `security / SAST (Opengrep)` | yes — via the gate |
| SAST, non-PHP | `codeql / Analyze (actions)` and `codeql / Analyze (javascript-typescript)`. CodeQL has no PHP analysis here — Opengrep is the PHP SAST | yes — via the gate |
| Code quality | `SonarCloud Code Analysis` (GitHub App, driven by `.sonarcloud.properties`) | no — the only check that reports without blocking |
| Secret scanning, CI | `gitleaks / Secret Scanning` | yes — via the gate |
| Secret scanning, push | GitHub native secret scanning with push protection `enabled` | yes — it rejects the push, before CI |
| Dependency vulnerabilities | `security / Composer Audit`, `dependency-review / Dependency Review` | yes — via the gate |
| Workflow hardening | `zizmor / zizmor analysis`, `step-security/harden-runner` | yes — via the gate |
| Supply-chain posture | `scorecard` | yes — via the gate |
| Licence compliance | `license-check / PHP License Audit` | yes — via the gate |
| Sign-off | `dco / DCO` | yes |
| Provenance and signing | SLSA Level 3 attestation and Cosign keyless signing on every release, via the org release workflow | release-time |

This is what `checks.yml`'s header comment always intended — "the gate is the
only context a ruleset requires" — and until 2026-08-10 this repository did the
opposite: it required `security / …`, `fuzz / …` and `license-check / …`
individually and the gate not at all, so gitleaks, zizmor, dependency-review,
scorecard and pr-quality ran without being able to block anything.

Requiring the gate instead of the individual contexts also removed a
requirement that enforced nothing. `fuzz / Fuzz Tests` comes from `checks.yml`,
which passes no inputs to the fuzz reusable, so it is always `skipped`; the
fuzzy suite that actually runs is `ci.yml`'s `fuzz-mutation / Fuzz Tests`, and
that one is now required in its place.

Most of the above is delegated to `netresearch/typo3-ci-workflows` and
`netresearch/.github` reusable workflows (`.github/workflows/checks.yml`). The
PHPStan and test matrix lives in `.github/workflows/ci.yml`; SonarCloud is a
GitHub App and is in no workflow at all.

## Security properties enforced by the test suite

These are behaviours, not scans — a regression fails a test rather than
raising a finding:

- **API keys never live in the extension.** They are nr-vault UUID
  identifiers under nr-vault's envelope encryption (ADR-012). No
  `sodium_crypto_*` call exists in `Classes/`.
- **Tool access passes four gates that can only narrow** — global per-tool
  state, per-group state, per-configuration allowed groups, per-run allow-list.
  The composition is a pure AND (ADR-039, ADR-120). Three of the four default
  *open* when unset (an unknown group is enabled, an empty group set does not
  filter, an absent request list means "everything enabled"), so this is a
  narrowing cascade, not a fail-closed one. The axes that do fail closed are
  elsewhere: an unregistered tool name, `requiresAdmin()` with no user, and the
  trust-zone ceiling.
- **Tool output is data-classified** and cannot cross a declared trust-zone
  ceiling (ADR-094), which fails closed on an unreadable or mistyped setting
  (ADR-113).
- **Writing tools require human approval.** Both shipped writers
  (`update_page_metadata`, `set_file_alternative_text`) ship disabled and
  suspend the run for approval before they act (ADR-134, ADR-135).
- **Secret-bearing output is redacted or gated** behind separate raw variants
  that ship disabled; `settings.php`-class files are structurally unreadable.
- **Network egress is declared per tool *group*** and fail-closed for any group
  without an entry (`EgressPolicyService`, ADR-043/ADR-093). Two groups have a
  positive scope: `system` (own sites) and `rag` (the configured search
  endpoint). Two call sites consult it. On the `rag` path it is an audit and
  consistency gate, as its own docblock says — the endpoint comes from site
  configuration. On the `system` path it is more than that: `probe_url` takes a
  **model-supplied** URL and `resolveAllowedUrl()` matches it against the
  instance's own site hosts, which is an SSRF boundary.

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
