.. include:: /Includes.rst.txt

.. _adr-070:

==================================================================
ADR-070: User-less configuration resolution by identifier
==================================================================

:Status: Accepted
:Date: 2026-07-16
:Authors: Netresearch DTT GmbH

.. _adr-070-context:

Context
=======

Downstream extensions pin their LLM calls to a named
:php:`LlmConfiguration` record and dispatch through the
``*ForConfiguration()`` entry points. The documented lookup path,
:php:`LlmConfigurationServiceInterface::getConfiguration()`, enforces a
backend-user access check — unusable from user-less contexts such as CLI
commands, Symfony Messenger consumers, or anonymous frontend requests.
Consumers therefore resolved records through
:php:`LlmConfigurationRepository::findOneByIdentifier()` directly (the
pattern the Integration Guide documented in Step 5), which silently
skipped two guards:

- the ``isActive`` flag — a deactivated record kept serving traffic;
- the ``beGroups`` access restriction — a record an admin restricted to
  specific backend groups was resolvable by anyone.

:php:`ConfigurationResolver` already existed as the access-check-free
resolution collaborator for the *default*-configuration path, with a
deliberate refusal policy: in a context without a backend user, an
access-restricted default is not auto-applied, because there is no user
to enforce the group membership against.

.. _adr-070-decision:

Decision
========

:php:`ConfigurationResolver` gains
:php:`getActiveByIdentifier(string $identifier): LlmConfiguration` as the
supported identifier lookup for user-less contexts. It throws typed
exceptions instead of returning ``null``:

- :php:`ConfigurationNotFoundException` — no record with the identifier
  exists;
- :php:`ConfigurationInactiveException` (new, implements
  :php:`NrLlmExceptionInterface`) — the record exists but is deactivated;
- :php:`AccessDeniedException` — the record is restricted to backend
  groups.

**Access-restricted records are refused in user-less contexts.** This
extends the resolver's existing default-path policy to identifier
lookup: ``beGroups`` restrictions express "only these backend groups may
use this configuration", and a context without a user cannot prove
membership — resolving the record anyway would turn the restriction into
a no-op exactly where nobody is watching (unattended workers). A
consumer that needs an access-restricted configuration in a worker
context must attribute the call to a user via the options-carried
``beUserUid`` (:ref:`ADR-052 <adr-052>`) and resolve through the
user-aware :php:`LlmConfigurationServiceInterface`, or the admin removes
the group restriction from the record.

Unlike the default path, no directly assigned model is required:
criteria-mode configurations carry no ``model_uid`` and resolve their
model at call time (:ref:`ADR-066 <adr-066>`).

No pinned-client facade is added. The per-capability
``*ForConfiguration()`` methods are already the one-line pinned call;
option building (attribution, detail levels, tool defaults) is consumer
policy that a generic facade cannot guess.

.. _adr-070-consequences:

Consequences
============

- User-less consumers get one supported lookup with the ``isActive``
  and access-restriction guards applied, instead of re-implementing them
  (or forgetting to) around ``findOneByIdentifier()``.
- Typed ``inactive`` vs. ``not found`` outcomes let consumers degrade
  differently (e.g. log-and-skip vs. configuration error).
- The Integration Guide's Step 5 now routes through the resolver; the
  previous example also called a non-existent ``findByIdentifier()``
  (actual method: ``findOneByIdentifier()``).
- Access-restricted configurations are unavailable to user-less callers
  by design — an intentional behaviour change against raw repository
  lookup, which ignored the restriction.
- One new exception class on the public surface
  (:php:`ConfigurationInactiveException`).
- :php:`ConfigurationResolver` stays a private, constructor-injected
  service: no ``public: true`` entry is added, so the audited count in
  :ref:`ADR-028 <adr-028>` / :ref:`ADR-065 <adr-065>` (as reduced by
  :ref:`ADR-069 <adr-069>`) is unchanged.
