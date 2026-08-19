# AGENTS.md — spec-kit-typo3-llm

## Overview

A [Spec Kit](https://github.com/github/spec-kit) preset for TYPO3 extensions that call a language model. No runtime code: three Markdown files appended to the `specify`, `plan` and `tasks` prompts, plus the `preset.yml` that registers them.

It is the third layer of a stack and assumes the second: `lean` (10) → `typo3` (5) → `typo3-llm` (3). Lower number wins, so this preset's content lands last.

## Layout

| Path | Purpose |
|------|---------|
| `preset.yml` | The manifest — the only file Spec Kit parses |
| `commands/specify-llm.md` | Appended to `speckit.specify` |
| `commands/plan-llm.md` | Appended to `speckit.plan` |
| `commands/tasks-llm.md` | Appended to `speckit.tasks` |

The repository root **is** the preset root: `install_from_archive` looks for `preset.yml` at the archive root and falls back to a single top-level subdirectory, which is the shape of a GitHub tarball. A `presets/<id>/` layout would not install via `--from`.

## The two rules that shape every change here

**Append, never replace.** A `replace` forks the upstream prompt and stops tracking its improvements from that day on, silently, because the fork keeps working and only gets worse relative to the original.

**Never restate a rule that lives in a consuming repository.** The base preset already instructs the agent to read the root and every scoped `AGENTS.md`. Copying a credential rule, an attribute name or a registration mechanic in here creates a second source that drifts from the first — and this preset would be the copy that is wrong. If a rule is specific to one extension, it belongs in that extension's `AGENTS.md`; what belongs here is a question that is true across every AI extension and written down in none of them.

## No symlinks in the archive

`CLAUDE.md` and `GEMINI.md` are symlinks, and `safe_extract_archive` rejects an archive containing one:

```text
Validation Error: Unsafe symlink in tar.gz archive: <repo>-main/CLAUDE.md
```

`.gitattributes` marks both `export-ignore`. Removing those lines breaks the documented install. Anything else added as a symlink needs the same treatment.

## Testing a change

Both paths, because they do not fail the same way:

```bash
# in a project that has .specify/ and the base preset installed
specify preset add --dev /home/sme/p/spec-kit-typo3-llm/main --priority 3
specify preset resolve speckit.specify    # chain: lean → typo3 → typo3-llm
specify preset remove typo3-llm

# THEN the path a consumer actually uses
specify preset add --from https://github.com/netresearch/spec-kit-typo3-llm/archive/refs/heads/main.tar.gz --priority 3
```

`--dev` copies a directory and never builds an archive, so it cannot catch an archive-level defect. That is how the symlink problem reached the sibling preset's `main`.

Check the **materialized** skill, not only the chain: the chain is the plan, `.claude/skills/speckit-specify/SKILL.md` is what an agent reads.

## Releasing

Bump `preset.version` and add the `CHANGELOG.md` section in the same commit, then tag `vX.Y.Z` and update the install URLs in `README.md`, which name a concrete ref.

## Commits

Signed off (`git commit -S --signoff`). Conventional commit subjects.
