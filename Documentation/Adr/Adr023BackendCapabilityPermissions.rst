.. include:: /Includes.rst.txt

.. _adr-023:

==================================================
ADR-023: Native Backend Capability Permissions
==================================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-023-context:

Context
=======

Until now, the only gate on who could invoke an AI capability (vision,
tools, embeddings, ...) was the per-configuration ``allowed_groups`` MM
relation. That is coarse: an editor with access to the "creative writing"
configuration could invoke any of its capabilities — text, tool-calling,
embeddings — even if the administrator only intended them to use chat.

Administrators also had no native UI surface to revoke a single capability
site-wide without editing every affected configuration.

.. _adr-023-decision:

Decision
========

Register every :php:`ModelCapability` enum value as a native TYPO3 BE group
permission under
:code:`$TYPO3_CONF_VARS['BE']['customPermOptions']['nrllm']`. The BE group
edit view now shows a checkbox per capability (chat, completion,
embeddings, vision, streaming, tools, json_mode, audio). A new service,
:php:`CapabilityPermissionService`, resolves the check against the
currently logged-in backend user.

Resolution order:

1. No BE user in context (CLI, scheduler, frontend) — allowed.
2. User is admin — allowed.
3. Otherwise — delegate to
   :php:`$backendUser->check('custom_options', 'nrllm:capability_X')`.

.. _adr-023-scope:

Scope
=====

This ADR ships the **registration + check primitive**. It does NOT
retroactively gate calls inside :php:`CompletionService`, :php:`VisionService`,
etc. — that is a deliberate follow-up concern, because it is a larger
behavioural change than a single-PR feature warrants.

Consumers can opt in today:

.. code-block:: php

   if (!$this->capabilityPermissions->isAllowed(ModelCapability::VISION)) {
       throw new AccessDeniedException('Vision capability not permitted for this user', 1745712100);
   }

.. _adr-023-relation:

Relation to existing access control
===================================

``allowed_groups`` on ``tx_nrllm_configuration`` gates access to a named
*configuration* (API keys, preset parameters, system prompt). Capability
permissions gate which *operations* a user is allowed to invoke against
any configuration they already have access to. The two are complementary:

* **Configuration ACL:** "Can this editor use the 'creative-writing'
  configuration at all?"
* **Capability permission:** "Can this editor invoke vision against any
  configuration?"

Both checks must pass.

.. _adr-023-alternatives:

Alternatives considered
=======================

* **Per-capability flags on** ``tx_nrllm_configuration``. Rejected:
  capability is an editor-role concern, not a configuration concern.
  Duplicating the checkbox on every row is worse UX than a single
  per-group toggle.

* **A sibling MM table** (configuration-to-capability). Rejected as
  another bespoke access model on top of TYPO3's native one. The whole
  point of this ADR is to use the native mechanism.

* **Inject the check into every feature service now.** Rejected to keep
  the PR small and the regression surface narrow. See the Scope note
  above — follow-up work.
