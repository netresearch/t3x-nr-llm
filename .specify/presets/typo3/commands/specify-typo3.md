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
