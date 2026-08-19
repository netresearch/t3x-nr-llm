# spec-kit-typo3

A [Spec Kit](https://github.com/github/spec-kit) preset that adds TYPO3 extension obligations to the specify → plan → tasks workflow: the version matrix, the public API surface, architecture decision records, which suite proves what, translations, and the documentation and changelog that ship with a change.

It **appends** to whichever prompt is active rather than replacing it, so it stacks on top of core or of another preset — `lean` included — and keeps tracking that prompt's improvements.

## Install

```bash
specify preset add --from https://github.com/netresearch/spec-kit-typo3/archive/refs/heads/main.tar.gz --priority 5
```

No release is tagged yet, so the command above installs from `main`. Once `v0.1.0` exists, use the tag instead — `…/archive/refs/tags/v0.1.0.tar.gz` — because installing from a branch means a consumer's next install can differ from the last one without anything saying so.

`--priority 5` is not decoration. Presets resolve lowest-number-first, and `specify preset add` defaults to 10, so a preset installed without an explicit priority ties with everything else installed that way. Five puts this preset above the default, which is what makes its content land *after* the prompt it appends to.

Verify what a command resolves to:

```bash
specify preset resolve speckit.specify
```

## What it adds

| Stage | Obligation |
|-------|------------|
| `speckit.specify` | The TYPO3 and PHP range written out; public API and backward compatibility stated in prose; the security and credential boundary named; whether an installed instance has to act; which suite proves each requirement; what is explicitly out of scope |
| `speckit.plan` | Read the root and every scoped `AGENTS.md` governing a touched path; search the ADRs and name what a plan supersedes; decide additive-or-breaking before the API snapshot decides it; state what already exists; write accepted trade-offs down |
| `speckit.tasks` | Failing tests first; API snapshot, ADR, translations for every shipped language, documentation, changelog and schema step each as their own task; the project's own gate last |

It adds no templates and no scripts. Every rule is a question the agent has to answer or a task it has to create — nothing here rewrites your repository's conventions, because the plan stage is told to go read them.

## What it deliberately does not do

- **It does not carry organisation-specific rules.** No vault, no house naming schemes, no CI particulars. Those belong in a preset that stacks on top of this one, so this layer stays true for any TYPO3 extension.
- **It does not touch git.** Branching, worktrees, signing and the pre-push gate are your repository's business; Spec Kit ships that as a separate extension and this preset does not pull it in.
- **It does not check anything.** A preset shapes prompts. If a rule has to hold, it needs a gate in the repository that CI runs — a prompt is a reminder, and the difference matters.

## Requirements

Spec Kit 0.16.0 or newer. The manifest pins that version because it is the one whose composition behaviour was read in the source and exercised, not the one that first shipped it.

## License

This project uses split licensing:

- **Code** (manifest, configs): [MIT](LICENSE-MIT)
- **Content** (command prompts, documentation): [CC-BY-SA-4.0](LICENSE-CC-BY-SA-4.0)

See the individual license files for full terms.

## Support

- Issues: [github.com/netresearch/spec-kit-typo3/issues](https://github.com/netresearch/spec-kit-typo3/issues)
