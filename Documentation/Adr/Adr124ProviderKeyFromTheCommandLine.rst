.. include:: /Includes.rst.txt

.. _adr-124:

============================================================================
ADR-124: A provider key can be set from the command line
============================================================================

:Status: Accepted
:Date: 2026-08-04
:Authors: Netresearch DTT GmbH

.. _adr-124-context:

Context
=======

nr-llm owns provider credentials. The setup wizard takes a plaintext key,
generates the identifier, stores the secret and writes that identifier onto the
provider record (:ref:`ADR-012 <adr-012>`). A consuming extension therefore
never needs to know where the secret ends up — it configures a provider and
refers to it.

That encapsulation held only for someone sitting in the backend. No console
command stored a key, so every unattended install — a container entrypoint, a
DDEV ``install`` script, CI provisioning, a throwaway review instance — had to
call nr-vault's :bash:`vault:store` itself and then hand-write the identifier
into the provider record.

Two things followed. Consuming extensions grew a dependency on nr-vault's CLI
and on the knowledge that nr-llm keeps its keys there; nr_repurpose documented
exactly that as part of its own setup. And the two paths produced different
records: the wizard writes provenance metadata alongside the secret, a
hand-rolled :bash:`vault:store` writes none, so what an audit sees depends on
how the instance happened to be provisioned.

.. _adr-124-decision:

Decision
========

Add :bash:`nrllm:provider:set-key <provider>`, which does from a script exactly
what the wizard does from the backend.

1. **The secret arrives on STDIN, and only there.** An argument would be
   visible in the process list and recorded in the shell history, and no option
   flag can take that back afterwards. A terminal is refused rather than read:
   prompting would hang a provisioning script in a way that looks like a freeze.

2. **Re-running replaces, it does not re-issue.** When the provider already
   references a stored credential, the secret is rotated under the existing
   identifier. Anything already pointing at that identifier — most importantly
   ``providers.openai.apiKeyIdentifier`` in the extension configuration, which
   the specialized speech and image services read — keeps working. A provider
   that references an identifier the vault no longer knows is re-stored under
   that same identifier rather than given a new one.

3. **Both paths write the same provenance.** The command records ``table``,
   ``field`` and ``source`` with the secret, as the wizard intends to. The
   wizard passed those keys at the top level of the options array, where
   nr-vault's :php:`store()` ignores them — it reads provenance from the
   ``metadata`` key. That is corrected here, so the audit trail no longer
   depends on which path created the provider.

.. _adr-124-consequences:

Consequences
============

- A scripted install provisions a provider end to end without invoking any
  ``vault:*`` command; nr-vault stays nr-llm's implementation detail rather
  than a consumer's setup step.
- The command is registered with ``schedulable: false``. It reads STDIN, which
  the scheduler cannot supply.
- Secrets stored by the wizard before this change carry no provenance metadata.
  Nothing reads that metadata for behaviour, so no migration is needed; older
  secrets simply stay unlabelled until they are next replaced.
- The provider record still has to exist first. Creating providers from the
  command line is a separate concern and is not addressed here.
