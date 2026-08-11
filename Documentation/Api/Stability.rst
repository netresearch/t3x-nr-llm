.. include:: /Includes.rst.txt

.. _api-stability:

=============
API stability
=============

Which classes the semantic-versioning promise covers, and what it promises.
The authority is the marker in each class-level docblock, not this page and
not the DI container visibility (:ref:`ADR-127 <adr-127>`).

The three markers
=================

``@api`` — call it
------------------

Classes and interfaces you *call*: the feature services
(:php:`CompletionServiceInterface` and friends), :php:`LlmServiceManager`,
the option classes, the response and value objects they accept and return,
and the typed exceptions they throw. Within a major version:

- no class or method is removed,
- no method signature changes incompatibly,
- documented behaviour does not break.

Everything a marked method's signature mentions is itself ``@api`` — you
never receive an object you are not allowed to rely on.

``@api Extension point`` — implement it
---------------------------------------

Interfaces and attributes third parties *implement*:
:php:`ToolInterface`, :php:`GuardrailInterface`, :php:`ProviderInterface`
and the capability interfaces, :php:`TranslatorInterface`,
:php:`SearchBackendInterface`, the preset/evaluation providers, the
middleware contracts, and the ``#[AsLlmProvider]`` / ``#[AsTranslator]``
attributes. These carry a stricter promise, forced by the direction of
implementation:
**no new abstract member within a major version** — adding one would break
every existing implementation, not just callers.

``@internal`` — hands off
-------------------------

Everything else: backend controllers, dashboard widgets, hooks, upgrade
wizards, console commands, DI compiler passes, TCA form elements, Extbase
repositories and the setup wizard. These may change or disappear in **any**
release, including patch releases. PHPStan and modern IDEs warn when code
outside this package touches them.

What is out of contract
=======================

- Subclassing internals: protected members of ``@api`` classes are not part
  of the promise. Extend via the extension points, not inheritance.
- Constructor signatures of ``@api`` *services*: obtain them from the DI
  container, never via ``new``. Constructing option/value objects directly
  is fine — their constructors are part of the signature promise.
- Anything reached by reflection or by reading private state.

The snapshot below records the constructor a caller reaches with ``new``,
including the service ones that are out of contract here. That is
deliberate: no mechanical rule separates a value object a consumer builds
with ``new`` from a service it only ever injects, and a new required
argument on the former breaks callers exactly as a deleted method would.
Recording them costs a service-wiring change one snapshot regeneration;
recording none of them let a widened constructor through the gate in
silence.

The constructor is also the one member that is **not** recorded
declared-only. Every other member has to be, because inherited TYPO3 core
members differ between 13.4 and 14.x — but ``new Foo(...)`` binds to
whatever constructor ``Foo`` inherits, so a declared-only rule left four
``Specialized`` services and two ``ProviderResponseException`` subclasses
with no constructor line at all, and a required argument added to their
shared base moved nothing. A constructor is therefore taken from the
nearest declaring class whenever that class is inside ``Netresearch\NrLlm``.

Two cases still carry no ``constructor(...)`` line, and both are intended:

- The constructor is inherited from **outside** this repository — TYPO3
  core's ``AbstractEntity``, ``\RuntimeException``. That signature is not
  ours and does differ across the version matrix, so recording it would
  make the snapshot depend on which matrix cell rendered it.
- There is no public constructor. A value object with a
  ``private __construct`` and static factories is reached through the
  factories, which the snapshot records as methods.

Today that is 70 of the 97 ``@api`` classes with a constructor line.

Enforcement
===========

The rendered ``@api`` surface is frozen in
``Tests/Unit/Api/api-surface.txt``: an unintended signature change fails CI
before review, and the same test asserts the closure rule (every type an
``@api`` signature mentions is ``@api``). An intended change updates the
snapshot in the same PR — the diff is the review artifact.

The failure is classified, because "different" makes a new value object read
like a deleted method. An **additive** diff (a new class, method, property,
constant or enum case) is regenerated and noted under ``### Added``. A
**breaking** one — anything removed or changed, a widened constructor
included — is a decision, and the failure message says so and points at
:ref:`api-deprecation`.

How something leaves the surface again is :ref:`api-deprecation`. Which
TYPO3 and PHP versions the promise is made on is :ref:`api-support-matrix`.

Versioning during 0.x
=====================

While the extension is pre-1.0, the promise applies one level down, as is
conventional: **minor releases (0.N → 0.N+1) may break**, and do so only
with a CHANGELOG entry under a BREAKING heading; patch releases do not
break. From 1.0.0 on the promise applies as written above.
