.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

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
:php:`ToolInterface`, :php:`GuardrailInterface`,
:php:`ContextualGuardrailInterface`, :php:`ProviderInterface`
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
- Members of an ``@api`` class marked ``@internal`` in their docblock, such
  as the process pin of ``RunAugmentation`` (ADR-228): the runtime sets
  them, and the snapshot still lists them.

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

The snapshot records constructor lines for the classes that meet these rules;
its current contents are the inventory of the public surface.

Enforcement
===========

The rendered ``@api`` surface is frozen in
``Tests/Unit/Api/api-surface.txt``: an unintended signature change fails CI
before review, and the same test asserts the closure rule: every
``Netresearch\NrLlm`` type an ``@api`` **method or property** signature
mentions is itself ``@api``. An intended change updates the snapshot in the
same PR — the diff is the review artifact.

Constructor parameter types are outside the closure rule. A DI-built service
is handed its collaborators by the container, so its constructor names
internals by design; the line is still frozen — widening it is a breaking
diff — but the types on it are not a promise that you may call them.

The failure is classified, because "different" makes a new value object read
like a deleted method. An **additive** diff (a new class, interface or enum,
a new concrete method or a property of a class, a new constant, an abstract
class made concrete, a final class made non-final, an abstract method given
a body, an interface a class now implements without leaving methods open) is regenerated and noted in an
``added`` changelog fragment.
A **breaking** one is a decision, and the failure message says so and points
at :ref:`api-deprecation`:

- anything removed or changed, a widened constructor and a class or method
  made ``final`` included,
- a new method on an existing ``@api`` interface — it breaks every
  implementation outside this extension, whether or not the interface is
  marked as an extension point,
- a new abstract method on an existing ``@api`` abstract class, public or
  protected, declared there or left open from a parent or an interface — it
  breaks every subclass outside this extension (the snapshot marks the class
  ``abstract class`` and the method ``method abstract``),
- a new case of an existing ``@api`` enum — it breaks every exhaustive
  ``match`` without a default arm,
- a changed header line: a class made abstract, a changed parent class, a
  changed list of parent interfaces of an interface (an interface that starts
  to extend another makes every implementation implement that one too), or an
  interface removed from a class's ``implements``. A parent class changed to
  a subclass of the old one breaks nobody, but the snapshot alone cannot tell,
  so the message asks the author to check.

Two diffs are reported as **check by hand**, because the snapshot cannot
classify them: a ``method abstract protected`` or ``method abstract private``
line that vanished (the snapshot does not render concrete protected or
private methods, so a hook given a body and a deleted one look the same), and a third-party interface added to an
abstract class (methods declared outside this extension and PHP are not
rendered, so the ones a subclass now has to implement are invisible). Read
the class; a deleted hook or a newly open method is breaking.

How something leaves the surface again is :ref:`api-deprecation`. Which
TYPO3 and PHP versions the promise is made on is :ref:`api-support-matrix`.

Versioning during 0.x
=====================

While the extension is pre-1.0, the promise applies one level down: in
0.x.y, the middle number *x* plays the role of the major and the last
number *y* the role of minor and patch together.

- **x rises only for a breaking change, by one, to 0.x+1.0.** A breaking
  change is either a breaking diff of the frozen ``@api`` surface (the
  classifier of ``Tests/Unit/Api/api-surface.txt`` reports *breaking*, see
  `Enforcement`_ — a new method on an ``@api`` interface, a new abstract
  method on an ``@api`` abstract class and a new case of an ``@api`` enum
  included, as 0.41.0 and 0.36.0 recorded the first and the last), or a
  behaviour change that consumers have to handle — for example 0.44.0 added
  the run status ``waiting_for_dependency``, which a consumer has to know
  about.
- **y rises for everything else**: new features, fixes, an additive
  ``@api`` diff (a new class, interface or enum, a new concrete method of a
  class),
  and additive database changes (new columns or tables with defaults that
  need only the schema update).
- A release that raises x carries at least one top-level CHANGELOG bullet
  that opens with ``- **BREAKING`` (upper case, followed by no letter, digit
  or underscore) and says what a consumer has to change. A release that
  keeps x carries none. The word may stand only there, once: every other
  occurrence, in any case and form (``**Breaking:**``, a nested bullet,
  prose such as "not a breaking change"), is refused unless it is quoted as
  inline code.

The reason is Composer's caret on a 0.x version: ``^0.54`` admits 0.54.*
and refuses 0.55.0. Every rise of x therefore forces each extension that
depends on nr_llm to widen its constraint and publish a release before an
installation can update. 0.45.0 to 0.55.0 raised x eleven times without a
BREAKING entry; six of their release pull requests named new features or
additions as the reason.

``Build/Scripts/check-changelog-version-bump.php`` (part of
``composer ci:test:changelog:sections`` and ``make changelog``, run in CI)
compares each release after 0.55.1 with the highest earlier version. It
refuses a release that raises x without a ``- **BREAKING`` bullet, one that
raises it by more than one or to a last number other than 0, one that keeps
x with such a bullet, and any ``## `` heading (older ones included) other
than ``## [Unreleased]`` and ``## [N.N.N]``, such as ``## [v0.56.0]``,
``## [0.56.0-rc.1]`` or ``## 0.56.0``. In those sections, in
``[Unreleased]`` and in ``changelog.d/`` fragments it refuses every
occurrence of the word "breaking" outside inline code and fenced code that
is not the one marker of a marker line. Whether a change is
breaking stays the release author's decision: the check reads the
CHANGELOG, not the API diff.

From 1.0.0 on the promise applies as written above.
