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

``@api`` extension point — implement it
---------------------------------------

Interfaces and attributes third parties *implement*:
:php:`ToolInterface`, :php:`GuardrailInterface`, :php:`ProviderInterface`
and the capability interfaces, :php:`TranslatorInterface`,
:php:`SearchBackendInterface`, the preset/evaluation providers, the
middleware contracts, and the ``#[AsLlmProvider]`` / ``#[AsTranslator]``
attributes. These carry the stricter promise implementation forces:
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

Versioning during 0.x
=====================

While the extension is pre-1.0, the promise applies one level down, as is
conventional: **minor releases (0.N → 0.N+1) may break**, and do so only
with a CHANGELOG entry under a BREAKING heading; patch releases do not
break. From 1.0.0 on the promise applies as written above.
