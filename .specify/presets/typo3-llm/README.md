# spec-kit-typo3-llm

A [Spec Kit](https://github.com/github/spec-kit) preset for TYPO3 extensions that call a language model. It adds the questions an AI feature gets wrong in the same way every time: which provider capability is assumed, what happens on a provider that lacks it, what leaves the instance, where untrusted model output crosses a boundary, what a run costs and what is stored when nobody measured, and what you assert when the output varies by design.

It stacks **on top of** [spec-kit-typo3](https://github.com/netresearch/spec-kit-typo3), which carries the obligations every TYPO3 extension has. Install both.

## Install

```bash
# base: every TYPO3 extension
specify preset add --from https://github.com/netresearch/spec-kit-typo3/archive/refs/heads/main.tar.gz --priority 5

# on top: extensions that call a model
specify preset add --from https://github.com/netresearch/spec-kit-typo3-llm/archive/refs/heads/main.tar.gz --priority 3
```

Presets resolve lowest-number-first, so the priorities are the layering:

| Priority | Preset | Applies to |
|---------:|--------|------------|
| 10 | `lean` (or core) | the workflow itself |
| 5 | `typo3` | every TYPO3 extension |
| 3 | `typo3-llm` | extensions that call a model |

Verify the composition rather than trusting it:

```bash
specify preset resolve speckit.specify
```

No release is tagged yet, so the commands above install from `main`. Once `v0.1.0` exists, use the tag instead.

## What it adds

| Stage | Obligation |
|-------|------------|
| `speckit.specify` | The assumed capability; the behaviour when a provider lacks it and whether fallback is automatic; how the caller learns which path answered; what leaves the instance and to whom; where untrusted output goes; cost and what is stored when unreported; the assertion given the output varies; behaviour on model drift |
| `speckit.plan` | Which providers actually have the capability, checked in their adapters; enforcement through the project's existing chain rather than around it; every boundary untrusted output crosses; what is logged; which column can carry an absent measurement; the assertion shape and what mocked suites therefore do not cover |
| `speckit.tasks` | A test per fallback branch including the exhausted one; an assertion that an absent measurement stays absent; a test at every named boundary; the invariant rather than the wording; and reading one real answer end to end before the gate |

## What it deliberately does not do

- **It does not restate any repository's own rules.** No credential store, no attribute names, no registration mechanics. The base preset already sends the agent to read the repository's `AGENTS.md`, and a second copy of a rule drifts from the first — silently, because both keep working.
- **It does not name a provider.** Which one serves a capability is a plan decision and changes over time; this preset asks what the capability has to be.
- **It does not check anything.** A preset shapes prompts. A rule that must hold needs a gate the consuming repository's CI runs.

## Requirements

Spec Kit 0.16.0 or newer, and `spec-kit-typo3` installed alongside it. This preset assumes the base layer's obligations rather than repeating them.

## License

This project uses split licensing:

- **Code** (manifest, configs): [MIT](LICENSE-MIT)
- **Content** (command prompts, documentation): [CC-BY-SA-4.0](LICENSE-CC-BY-SA-4.0)

See the individual license files for full terms.

## Support

- Issues: [github.com/netresearch/spec-kit-typo3-llm/issues](https://github.com/netresearch/spec-kit-typo3-llm/issues)
