..  include:: /Includes.rst.txt

..  _developer-capability-permissions:

==========================================
BE group permission checks
==========================================

Every :php:`ModelCapability` enum value is registered as a native
TYPO3 ``customPermOptions`` entry under the ``nrllm`` namespace.
Administrators see a checkbox per capability (chat, completion,
embeddings, vision, streaming, tools, json_mode, audio) on the
:guilabel:`Backend Users > Access Options` tab when editing a BE
group. Consumer code asks the
:php:`\Netresearch\NrLlm\Service\CapabilityPermissionService`
whether the capability is allowed for the current user.

..  _developer-capability-permissions-check:

Running a check
===============

Inject the service and call :php:`isAllowed()` before dispatching.
The method accepts an optional :php:`BackendUserAuthentication` for
tests; when omitted it reads :php:`$GLOBALS['BE_USER']`:

..  code-block:: php
    :caption: EXT:my_ext/Classes/Service/Caption.php

    use Netresearch\NrLlm\Domain\Enum\ModelCapability;
    use Netresearch\NrLlm\Exception\AccessDeniedException;
    use Netresearch\NrLlm\Service\CapabilityPermissionService;

    final class Caption
    {
        public function __construct(
            private readonly CapabilityPermissionService $permissions,
        ) {}

        public function describe(string $imageUrl): string
        {
            if (!$this->permissions->isAllowed(ModelCapability::VISION)) {
                throw new AccessDeniedException(
                    'Vision capability not permitted for this user',
                    1745712100,
                );
            }
            // ... dispatch to VisionService ...
        }
    }

..  _developer-capability-permissions-rules:

Resolution order
================

The check resolves in this order:

1.  No BE user in context (CLI, scheduler, frontend) → **allowed**.
    Capability gating is a backend-editor concern; background jobs
    and frontend rendering are not subject to it.
2.  User is admin → **allowed**. Admins bypass the native TYPO3
    permission machinery by convention.
3.  Delegates to
    :php:`$backendUser->check('custom_options', 'nrllm:capability_X')`
    — the native TYPO3 permission check. Returns what it returns.

..  _developer-capability-permissions-scope:

Complementary to configuration ACL
===================================

The ``allowed_groups`` MM relation on
:sql:`tx_nrllm_configuration` gates access to a specific preset
(API keys, system prompt, etc.). Capability permissions gate which
*operations* a user may invoke against any preset they can already
reach. The two are orthogonal and both checks must pass.

-   **Configuration ACL:** "Can this editor use the
    'creative-writing' configuration at all?"
-   **Capability permission:** "Can this editor invoke vision
    against any configuration?"

..  _developer-capability-permissions-helpers:

Stable keys
===========

:php:`CapabilityPermissionService::permissionString()` returns the
TYPO3 permission string (e.g. ``nrllm:capability_vision``) for any
enum case. Use it when you need to check directly without going
through the service, for example in a Fluid ViewHelper or a TCA
display condition:

..  code-block:: php
    :caption: Permission-string lookup

    use Netresearch\NrLlm\Domain\Enum\ModelCapability;
    use Netresearch\NrLlm\Service\CapabilityPermissionService;

    $permString = CapabilityPermissionService::permissionString(
        ModelCapability::TOOLS,
    );
    // => "nrllm:capability_tools"

See :ref:`adr-023` for the full design rationale and the
alternatives (per-configuration flags, bespoke MM table, inline
enforcement) we ruled out.
