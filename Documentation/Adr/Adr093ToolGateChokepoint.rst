.. include:: /Includes.rst.txt

.. _adr-093:

============================================================================
ADR-093: One tool gate, in the loop — not in the controller
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-093-context:

Context
=======

The tool subsystem enforces its policy in several places, and an audit found
that three of those places disagreed with what the documentation claimed.

**The per-configuration gate was in the wrong layer.** A configuration's
``allowed_tool_groups`` and its skills' declared allow-list were applied by
``ToolPlaygroundController``, not by the loop. That was defensible while the
playground was the only entry point. It stopped being defensible when
``ToolLoopServiceInterface`` was published in 0.23.0 so downstream extensions
could drive the loop: every such consumer bypassed the configuration's own
restriction entirely and received the full globally-enabled set.

**Two schema tools disagreed on what may be read.** ``get_full_tca`` routed its
decision through ``TableReadAccessService``, whose sensitive-table denylist
holds for administrators too. ``get_tca`` checked ``tables_select`` directly —
and ``BackendUserAuthentication::check()`` returns true for every table for an
admin, so ``get_tca`` described ``tx_nrllm_provider`` and the ``nr_vault``
tables to any admin-run loop, vault key references included.

**A documented egress invariant was false.** ``EgressPolicyService`` stated that
a group without an entry in its map "cannot egress anywhere" and listed ``rag``
among the groups that "read local state only". Meanwhile the RAG tools reach
``SolrSearchBackend``, which assembles a ``scheme://host:port`` URL from the site
configuration and hands it to an HTTP client without ever consulting the policy.

.. _adr-093-decision:

Decision
========

**The loop is the chokepoint.** ``ToolLoopService::resolveOfferedNames()`` now
applies the per-configuration gate itself, alongside the global enablement
intersection and the fail-closed admin filter it already applied. The caller's
tool list is a *request*; the configuration is the *grant*. ``resume()`` runs
the same resolution, so a run suspended before a configuration was tightened is
re-checked at approval time.

``ToolLoopServiceInterface``, ``runLoop()`` and ``resume()`` are unchanged —
both already receive the ``LlmConfiguration``. The playground keeps passing the
admin's checkbox selection and simply stops applying the gate a second time, so
its observable behaviour is identical.

**One table policy.** ``get_tca`` routes both its list and its describe path
through ``TableReadAccessService::canReadTable()``. A denied table returns the
same neutral ``Unknown TCA table.`` string as an unknown one, so the tool never
confirms a table's existence.

**The egress map tells the truth.** A new scope,
``ToolEgressScope::CONFIGURED_ENDPOINT``, expresses an operator-declared service
host that is not a site base — ``OWN_SITE`` cannot describe a Solr host, so
without it the map could only be satisfied by misdeclaring the group. ``rag``
maps to it, and ``SolrSearchBackend`` validates its assembled URL through
``EgressPolicyService::resolveConfiguredEndpoint()``: http(s) only, no userinfo,
exact host:port match against the configured host. A denial returns null, the
method's established "not configured" path, which the retrieval service treats
as an unavailable backend and skips.

.. _adr-093-consequences:

Consequences
============

- **The published ``ToolLoopServiceInterface`` contract narrows.** A consumer
  that previously received tools outside its configuration's
  ``allowed_tool_groups`` now receives fewer. No shipped behaviour changes —
  the only production consumer already applied the intersection — but this is a
  deliberate tightening and belongs in the release notes.
- ``get_tca`` no longer describes the extension's own or ``nr_vault``'s tables,
  to anyone, including administrators. That is the point.
- The RAG egress gate is an **audit and consistency gate, not a new
  confidentiality boundary**. The Solr host was always operator-supplied from
  the site configuration and never model-supplied; what changes is that the
  invariant this class documents is now checkable in code instead of merely
  asserted. Overselling it would be dishonest.
- Both new collaborators are optional constructor arguments so the existing
  lean test wiring keeps working. In production the container injects them; a
  unit test that omits them exercises the pre-existing gates only.
- ``Classes/Service/Retrieval`` now references ``Classes/Service/Tool``. That
  crosses a horizontal seam ADR-090 names but does not yet enforce. The
  alternative — duplicating URL validation inside the retrieval module — would
  be a second policy, which is the very failure this ADR is correcting.
- Still open, and deliberately not addressed here: tools carry no data
  classification and providers no trust zone, so nothing yet prevents a
  diagnostics tool's output from egressing to an external provider. That is the
  next step and needs its own ADR.
