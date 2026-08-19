# AGENTS.md — spec-kit-typo3

## Overview

A [Spec Kit](https://github.com/github/spec-kit) preset. It contains no runtime code: three Markdown files that append TYPO3 extension obligations to the `specify`, `plan` and `tasks` prompts, plus the `preset.yml` manifest that registers them.

## Layout

| Path | Purpose |
|------|---------|
| `preset.yml` | The manifest. `schema_version: "1.0"`, and the only file Spec Kit parses. |
| `commands/specify-typo3.md` | Appended to `speckit.specify` |
| `commands/plan-typo3.md` | Appended to `speckit.plan` |
| `commands/tasks-typo3.md` | Appended to `speckit.tasks` |

The repository root **is** the preset root. That is not a style choice: `install_from_archive` looks for `preset.yml` at the archive root and falls back to a single top-level subdirectory, which is exactly the shape of a GitHub tag tarball. A `presets/<id>/` layout would not install via `--from`.

## The one rule that shapes every change here

**Append, never replace.** Every entry in `provides.templates` carries `strategy: "append"`. A `replace` would fork the upstream prompt and stop tracking its improvements from that day on, silently — the fork keeps working, it just gets worse relative to the original. If a rule cannot be expressed as an addition, that is a signal the rule belongs in a repository's own `AGENTS.md` rather than here.

## What does not belong in this preset

- **Organisation-specific rules.** No vault, no house naming, no CI particulars. They go in a preset that stacks on top, so this layer stays true for any TYPO3 extension rather than only for ours.
- **Anything git.** Branching, worktrees, signing, pre-push gates. Spec Kit ships that as a separate extension; this preset does not pull it in and must not reimplement it.
- **Checks.** A preset shapes prompts. A rule that has to hold needs a gate in the consuming repository that CI runs. Writing a rule here and calling it enforced is the failure this preset exists to talk consumers out of.

## No symlinks in the archive

`CLAUDE.md` and `GEMINI.md` are symlinks to `AGENTS.md`, and Spec Kit's `safe_extract_archive` refuses an archive that contains one:

```text
Validation Error: Unsafe symlink in tar.gz archive: spec-kit-typo3-main/CLAUDE.md
```

`.gitattributes` therefore marks both `export-ignore`, which keeps them in a clone and out of the tarball. Removing those two lines breaks the documented install immediately — measured 2026-08-17, not predicted. Anything else added to this repository as a symlink needs the same treatment.

## Testing a change

Both paths, because they do not fail the same way:

```bash
# From a project that already has .specify/
specify preset add --dev /home/sme/p/spec-kit-typo3/main --priority 5
specify preset resolve speckit.specify      # must show core/lean content THEN ours
specify preset remove typo3

# THEN the path a consumer actually uses
specify preset add --from https://github.com/netresearch/spec-kit-typo3/archive/refs/heads/main.tar.gz --priority 5
```

The `--from` run is not redundant. `--dev` copies a directory and never builds an archive, so it passed cleanly while `--from` failed on the symlinks — the defect existed only on the path every consumer takes.

`--priority 5` matters: `specify preset add` defaults to 10, and a preset at 10 ties with everything else installed without an explicit priority. Verify with `resolve` rather than assuming — the append landing in the wrong order is invisible until an agent reads it.

## Releasing

Bump `preset.yml`'s `preset.version` and add the `CHANGELOG.md` section in the same commit, then tag `vX.Y.Z`. The install URL in `README.md` names a concrete tag, so it has to be bumped with the release or it will keep pointing at the previous one.

## Commits

Signed off (`git commit -S --signoff`). Conventional commit subjects.
