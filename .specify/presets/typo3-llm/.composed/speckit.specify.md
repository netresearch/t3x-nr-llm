---
description: Create a specification and store it in spec.md.
---


## User Input

```text
$ARGUMENTS
```

## Outline

1. **Ask the user** for the feature directory path (e.g., `specs/my-feature`). Do not proceed until provided.

2. Create the directory and write `.specify/feature.json`:
   ```json
   { "feature_directory": "<feature_directory>" }
   ```

3. Create a specification from the user input and store it in `<feature_directory>/spec.md`.
   - Overview, functional requirements, user scenarios, success criteria
   - Every requirement must be testable
   - Make informed defaults for unspecified details


## TYPO3 extension obligations — specification stage

Answer these before the plan exists. Every one of them is a *what*, not a *how*: if answering forces you to choose a class, a table or a migration, you have left this stage.

1. **Which versions does this have to hold for?** Name the TYPO3 and PHP range the change must work across. "The supported range" is not an answer — write the range out, because the specification is where a narrowing gets noticed instead of at the first red matrix cell.
2. **Does it touch the public surface?** Anything a consuming extension can call. Say whether the change is additive or breaking *in prose*, before any file tells you. A surface change that is discovered when the API snapshot fails has already cost a design round.
3. **Is backward compatibility intended here?** If a caller has to change, that is a decision with a deprecation path, not a detail. Say which callers.
4. **What moves at a security or credential boundary?** Where secrets are stored, what reaches an external service, what comes back from one and is therefore untrusted. Silence is read as "nothing moves", so say it explicitly when nothing does.
5. **Does an installed instance need to do anything?** A schema change, an upgrade wizard, a re-index, a cache flush, a re-saved configuration. If an operator has to act, that belongs in the requirements rather than in the release notes.
6. **What does a user or integrator have to be told?** Name the documentation that changes. "Docs" is not a requirement; a named page is.
7. **Which suite proves each requirement?** Unit, integration, functional, or a browser test. A requirement no suite can fail on is a wish — either name the suite or drop the requirement.
8. **What is explicitly NOT in scope?** Write the list. The things that look reasonable to add while nearby are exactly the ones that turn a two-file change into a review argument.

**Where a value gets recorded, say what is stored when it could not be measured.** A missing measurement and a measured zero are different facts and have to stay different in the data — `NULL` versus `0`, absent key versus zero. This is the single most common defect in a "just add a column" feature.

**Do not name classes, tables, migrations or file paths in the specification.** They are the plan's answer to this document, and writing them here means the plan has nothing left to decide and the decision was never reviewed.


## AI feature obligations — specification stage

These are the questions an AI feature gets wrong in the same way every time. Answer them here, in prose, before anything picks a provider or a class.

1. **Which provider capability does this assume?** Structured output, tool calling, streaming, vision, a context window of a given size. Name it.
2. **What happens on a provider that lacks it?** There are three defensible answers and you must pick one: refuse the call, fall back to a provider that has it, or degrade to a weaker behaviour. Say which — and say whether the fallback is automatic or something the caller opts into, because "it falls back" without that is not a specification.
3. **How does the caller find out which one happened?** A feature whose answer was produced by a degraded path, indistinguishable from one that was not, cannot be debugged or trusted downstream.
4. **What leaves the instance, and to whom?** The prompt content, the documents attached to it, personal data, customer data. Name the recipient. If the answer is "whatever the editor pastes in", that is the answer and it has consequences worth writing down.
5. **Model output is untrusted input.** Say where it goes: rendered as HTML, stored, passed to a shell, used as a file path, fed back into a prompt. Each of those is a different risk and the specification is where the difference gets noticed.
6. **What does a run cost, and what is stored when the provider does not say?** Token counts and cost are reported by some providers and not others. A model priced at zero and a provider that reported nothing must not end up as the same recorded value — that distinction is the single most common defect in AI telemetry.
7. **What is the assertion, given the output varies?** Equality is almost never it. Name what must hold: a schema, a set of required fields, a range, a refusal, an invariant that survives rewording. A requirement that can only be checked by eye is not testable.
8. **What happens when the provider changes the model behind the same name?** Say whether the feature pins a version, tolerates drift, or has to be re-evaluated — and if it has to be re-evaluated, what would signal that.

**Do not name a provider adapter, a middleware or a table here.** Which provider serves the capability is the plan's decision; this document says what the capability has to be.
