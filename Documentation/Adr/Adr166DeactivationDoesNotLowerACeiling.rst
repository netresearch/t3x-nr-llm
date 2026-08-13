.. _adr-166:

============================================================
ADR-166: Deactivating a source does not lower a ceiling
============================================================

:Status: Accepted
:Date: 2026-08-13
:Amends: :ref:`ADR-165 <adr-165>` (which lookup the resume re-gate uses)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-165 <adr-165>` rebuilds a resumed run's forced set from the uids the
suspend persisted, so the :ref:`ADR-164 <adr-164>` ceiling still sees it. It did
that through :php:`PromptSnippetRepository::findByUids()`, which filters
``is_active = true``.

That filter is the whole defect. The repository's :php:`initializeObject()`
already sets :php:`setIgnoreEnableFields(true)`, so TYPO3's ``hidden`` field
plays no part; the explicit ``is_active`` clause was the only thing deciding,
and an operator who switched a snippet off while a run was suspended made it
vanish from the re-gate. Both lists then came back empty,
:php:`augmentationFrom()` returned null, and the send took the
configuration-only gate path — the pre-ADR-165 answer, silently.

The text was still there. It sits in the persisted transcript, it goes on the
wire on the very next send, and its classification is what the ceiling exists to
weigh. Nothing about deactivating a record removes it from a conversation that
is already carrying it.

Decision
========

**"Inactive" means "not for new composition". It does not mean "already-injected
text loses its classification".** Those are two different questions, and the
repository now answers them with two methods.

:php:`findByUids()` keeps its active-only semantics unchanged: it is the lookup
for a prompt being assembled *now*, and a snippet an operator switched off must
not enter one. :php:`findExistingByUids()` is new and drops only the
``is_active`` clause — same contract otherwise: input order preserved, unknown
uids silently skipped, a **deleted** record still resolving to nothing.
:php:`ToolLoopService::augmentationFrom()` uses the second one, because the text
it is classifying is already in the transcript.

The two are deliberately not merged, and each carries a docblock saying which
question it answers.

**The skill half was already correct.** :php:`SkillRepository` ignores enable
fields for the same reason the snippet repository does, and
:php:`augmentationFrom()` filters :php:`findAll()` by uid alone — no ``enabled``
clause anywhere on that path. A skill disabled while a run was suspended
therefore always kept its classification on resume. Only the snippet branch had
an explicit filter to trip over, so only the snippet branch needed a change.

.. _adr-166-not:

What this does not do
=====================

**It does not reverse any of ADR-165's three decisions.** They stand exactly as
written:

- the **live record wins** over a frozen classification (identity over
  snapshot), so a class raised *or lowered* while the run was suspended takes
  effect;
- a **deleted** source resolves to nothing and contributes nothing — the
  deleted restriction stays on in :php:`findExistingByUids()`, so refusing a
  resume over a record an operator removed is still not what happens;
- a **legacy suspended row with no uids** rehydrates with none and resumes,
  never refuses.

**It does not make an inactive snippet usable again.** Nothing composes it into
a new prompt, nothing offers it in the playground, and a fresh run that forces
it gets it through the active-only lookup like any other. The only thing it
regains is being *counted* by a ceiling that is judging text already sent.

**It does not widen what the ceiling reads.** The forced set is the same set
ADR-164 defined; this changes which rows resolve, not which rows are asked for.

Consequences
============

A run suspended for approval or typed input, one of whose forced snippets is
switched off before the approver answers, is re-gated against that snippet's
class exactly as if it were still active — and is refused when the ceiling has
meanwhile dropped below it. Before this, deactivating a snippet mid-run was a
way to quietly lower a run's ceiling while its text kept going out.

An installation that has classified nothing is unaffected, and a run that forced
nothing still hands over null.
