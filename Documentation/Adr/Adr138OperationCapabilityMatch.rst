.. include:: /Includes.rst.txt

.. _adr-138:

============================================================================
ADR-138: Criteria-mode selection matches the operation, not only the criteria
============================================================================

:Status: Accepted
:Date: 2026-08-09
:Authors: Netresearch DTT GmbH

.. _adr-138-context:

Context
=======

A criteria-mode :php:`LlmConfiguration` carries no model relation. Its model is
chosen at call time from stored criteria — capabilities, adapter types, a
context-length floor, a cost ceiling. Until now that was the whole input.
:php:`ModelSelectionService::resolveModel()` never learned which call it was
resolving for.

So a configuration whose criteria say ``{"adapterTypes": ["ollama"]}`` could
serve a tool call with a model whose own record states it cannot do tools. The
selection succeeded, the adapter was built, and the request failed at the
provider — as a transport-shaped error, several layers away from the
configuration that caused it.

Two related facts shaped the fix.

**The same call resolves twice.** ``embedForConfiguration()`` resolves once
outside the pipeline to build the embedding cache key and once inside the
terminal to pick the adapter. Two resolutions that can disagree would let cache
entries stored under model A serve a call that ran against model B. The eager
streaming-capability check had the same shape.

**The capability column never reached the entity.** Extbase resolves property
types through Symfony's PropertyInfo, whose ``ReflectionExtractor`` infers a
collection from an adder/remover pair. :php:`Model::addCapability()` /
:php:`removeCapability()` inflect to ``$capabilities``, so the property resolved
as ``array``; the DataMapper has no array mapping and dropped the column. Every
repository-loaded model came back with an EMPTY capability set. The pre-existing
``capabilities`` criterion therefore matched nothing in production either — the
defect this ADR fixes was one of two, and the second one hid the first.

.. _adr-138-decision:

Decision
========

**Thread the operation into the resolution.**
:php:`ModelSelectionServiceInterface::resolveModel()` and
:php:`ConfigurationCallPlanner::resolveModel()` take a
:php:`?ProviderOperation`. It has no default: every resolution belonging to a
concrete call must name that call, and the one caller that genuinely has none —
the bare ``adapterFor()`` lookup behind :php:`getAdapterFromConfiguration()` —
says ``null`` out loud. In criteria mode the capability the operation requires is
merged into the criteria under its own key before ``findMatchingModel()`` runs.
Fixed mode is untouched: the operator named that model, so nothing is being
chosen and there is nothing to constrain.

**Both resolutions of one call pass the same operation.** The embedding
cache-key site and the embedding terminal both pass ``Embedding``; the eager
streaming check calls the planner directly with ``Stream`` rather than routing
through the operation-less public entry point. A unit test asserts that the two
embedding resolutions receive the same operation and return the same model.

**Restore the capability mapping.** An explicit ``@var string`` on
:php:`Model::$capabilities` puts ``PhpDocExtractor`` — which runs first — back in
charge of the property type. Without it this ADR would ship decoration: every
model would read as undeclared and the new check would never fire.

**An empty capability CSV means undeclared, not "cannot".** The field is
optional and many installations never filled it. The operation-derived check is
therefore skipped for such a model, in both switch positions. This is a separate
criteria key from ``capabilities`` precisely so the two can differ: what an
operator explicitly asked for is still matched strictly, and a model that
declares nothing still fails that.

**Enforcement is a fail-closed switch, following ADR-113.**
``routing.operationCapabilityEnforcement`` defaults to ``enforce``. Only a
literal ``observe`` observes — a missing value, a malformed ``routing`` section,
an unreadable extension configuration and a typo all enforce, so a broken
setting cannot silently disable the axis. Fail-closed governs the SWITCH, not
the empty CSV: reading an absent statement as a denial would break working
installations for a fact nobody ever stated.

**The map is narrower than the vocabulary.** Only ``chat``, ``vision`` and
``tools`` are enforced, because only those are actually written. All seven model
discoverers write ``chat`` and write ``vision`` / ``tools`` when the model has
them, so a record lacking one of those is a statement. No discoverer writes
``completion`` or ``embeddings`` at all, and only four of seven write
``streaming`` — requiring those would refuse models that work, on the strength
of a field their own discoverer never filled. A requirement no producer
satisfies is not a check, it is an outage.

**A misconfiguration is named, not disguised.** When enforcement is on and the
criteria match models but none that can serve the operation, resolution throws
:php:`UnsupportedFeatureException` naming the configuration, the capability and
the operation. Criteria that match nothing at all still return ``null`` — that is
the pre-existing "has no model assigned" condition and it keeps its behaviour.

**UnsupportedFeatureException stays UNKNOWN in the failure classifier**, and
therefore not retryable. This is deliberate and must not be "fixed": the
exception now reports the installation's own misconfiguration. A retry cannot
repair it, and a fallback that silently answered from another configuration
would hide the very defect this ADR exists to surface. The operator would see a
working system quietly running on the wrong model.

.. _adr-138-boundary:

Boundary: the generic path keeps its adapter checks
===================================================

``chat()``, ``complete()`` and ``streamChat()`` prefer the default DB
configuration and reach ``resolveModel()`` through it — those calls get the
check. Their ad-hoc branch does not, and neither do ``embed()``, ``vision()`` or
``chatWithTools()``, which always run ad-hoc: they synthesize a transient
configuration carrying only an identifier — no model, no provider, no criteria —
and resolve a provider by key instead. There is no model record to match
against, so there is nothing for this ADR to check.

Those paths keep the adapter-level guards they already have
(:php:`ToolCapableInterface`, :php:`VisionCapableInterface`,
``supportsFeature('embeddings')``). This is stated as a boundary rather than
papered over: making the generic path capability-aware means giving it a model
record, which is a different change with a different blast radius.

.. _adr-138-consequences:

Consequences
============

Fixed-mode configurations — the majority — are unaffected.

Criteria-mode configurations become stricter for chat, vision and tool calls,
and the capability criterion starts working at all now that the column reaches
the entity. An installation whose model records understate what their models can
do will see a resolution refused where it previously succeeded and failed later
at the provider. The escape hatch is one setting, and the real fix is one
checkbox on the model record.

Restoring the capability mapping is visible beyond selection: anything reading
``getCapabilities()`` off a repository-loaded model saw an empty string before
and now sees the persisted value.

.. _adr-138-revisit:

Revisit when
============

The discoverers write the capabilities they currently omit. ``streaming`` is the
nearest: four of seven already write it, and closing the other three would make
``Stream`` enforceable. ``embeddings`` and ``completion`` need a producer before
they mean anything at all.

Also revisit if the generic path ever gains a model record. The boundary above
exists because it has none, not because operation matching is unwanted there.
