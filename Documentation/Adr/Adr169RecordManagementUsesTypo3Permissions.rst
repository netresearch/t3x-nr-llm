.. include:: /Includes.rst.txt

.. _adr-169:

============================================================================
ADR-169: Record management belongs to TYPO3's permission model
============================================================================

:Status: Proposed
:Date: 2026-08-13
:Authors: Netresearch DTT GmbH

.. _adr-169-context:

Context
=======

Issue `#691` declines to add a ``MANAGE`` grant case until a management surface
exists, under :ref:`ADR-130 <adr-130>`'s rule that a case arrives together with
its enforcement point (``Adr130BackendUserGrants.rst:32-36``). That rule exists
because :ref:`ADR-023 <adr-023>` shipped backend-group checkboxes that gated
nothing and :ref:`ADR-117 <adr-117>` had to remove them: "a control that has to
be labelled 'has no effect' is worse than its absence"
(``Adr117WithdrawCapabilityPermissions.rst:78-79``).

Issue `#768` asks seven questions that have to be answered together before that
surface is built. This record recommends answers and settles none of them.

**Version scoping.** Every claim about core reach is read off the TYPO3 version
resolved in this worktree — 14.3.5 (``Typo3Version.php:22``). The blocking
matrix is ``^13.4 || ^14.3`` (``composer.json``). 13.4 reaches the same
capability through :php:`BackendUtility::isRootLevelRestrictionIgnored()`,
deprecated in 14.0 in favour of the Schema API
(``BackendUtility.php:2963-2967``); its call sites were not enumerated here.

.. _adr-169-decision:

Decision
========

.. _adr-169-q1:

1. Where management-owned records live
--------------------------------------

`#768` asks how ``rootLevel => -1`` is solved technically. The prior question is
where the records should live.

**``rootLevel => -1`` is not root-only.** ``RootLevelCapability.php:26-28``
defines the three values: ``TYPE_ONLY_ON_PAGES = 0``,
``TYPE_ONLY_ON_ROOTLEVEL = 1``, ``TYPE_BOTH = -1 // does not matter``. All nine
``tx_nrllm_*`` tables use ``-1``, and none sets
``security.ignoreRootLevelRestriction`` (zero hits repo-wide).

:php:`DataHandler::isTableAllowedForThisPage()` (``DataHandler.php:7484-7503``)
splits on the pid, not on the flag:

- the rootLevel early return is skipped entirely for ``TYPE_BOTH`` (``:7491-7496``);
- at pid 0 the test is :php:`isAdmin() || shallIgnoreRootLevelRestriction()`
  (``:7498-7499``);
- at a non-zero pid the only remaining test is
  :php:`isRecordTypeAllowedForDoktype()` (``:7502``).

The permission check runs alongside it and splits the same way.
:php:`hasPermissionToInsert()` passes :php:`VirtualRecord::RootPage` only when
the pid is 0 (``:7466``), and the update path resolves the real page record
whenever ``pid > 0`` (``:872-876``). :php:`hasPageContextPermission()` then
answers a root page from the flag (``:7669-7674``) but a real page from the web
mount (``:7677``) and the ``perms_*`` bitmask (``:7696-7705``).

.. list-table::
   :header-rows: 1

   * - Where the record lives
     - Non-admin holding ``tables_modify``
   * - pid 0
     - Refused. Needs ``security.ignoreRootLevelRestriction``.
   * - On a page in a web mount, with content-edit permission
     - Permitted today. No TCA change.

**This is not theoretical for this extension.** The setup wizard renders a
"Storage Folder (Page ID)" input (``Backend/SetupWizard/Index.html:328-331``),
whose value reaches ``saveAction`` (``SetupWizardController.php:301``) and is
written onto the provider (``:372-374``), every model (``:449``) and every
configuration (``:525``) with no page check. All eight Extbase repositories set
:php:`setRespectStoragePage(false)`, and the ninth table's plain repository
issues no pid predicate at all (``McpServerRepository.php:48-49``, ``:83-85``,
``:98-100``). A page-stored record is found by the runtime exactly like a
pid-0 one.

**Option R — open ``security.ignoreRootLevelRestriction`` and keep the records
at pid 0.** It is a table capability, so it answers for the whole installation
at once. Every core pid-0 branch opens with it: the FormEngine edit and create
forms, which set :php:`Permission::ALL` on the root node
(``DatabaseUserPermissionCheck.php:100-102``, ``:133-135``), clipboard
copy/cut/paste (``Clipboard.php:709-712``), the suggest wizard on any relation
field in any extension (``SuggestWizardController.php:213-217``), record
information (``ElementInformationController.php:109``, ``:769``), history and
rollback (``ElementHistoryController.php:539-541``, ``RecordHistory.php:527``)
and the list module's hide/show toggle (``RecordListController.php:273-279``).

**Option P — store management-owned records on a page inside a web mount.** It
needs no core-permission change and gives page-level granularity a global flag
cannot express: which group reaches which records is decided by web mounts and
``perms_*``, per page. It splits by table *and* by page, where the flag splits
only by table.

**Recommendation: Option P.** Two costs, both real:

- Existing installations hold these records at pid 0. Moving them is a
  deliberate migration, not a side effect of shipping a module.
- Page storage is a permission boundary, not a scoping boundary. Nothing filters
  by pid at runtime, so a configuration created on any page is live for the
  whole installation. A "storage folder" is a convention.

**Saying no** means Option R, and then the reach list above is the accepted
blast radius.

**What the corrected premise already opens.** An installation that used the
storage-folder field and granted a non-admin group ``tables_modify`` on a
``tx_nrllm_*`` table has an editing surface nobody decided to open. ``api_key``
and ``auth_credential`` are still gated there, because
:php:`VaultFieldHelper::getSecureFieldConfig()` sets ``exclude => true``
(``VaultFieldHelper.php:94-97``, ``:120``) and ``DataHandler.php:1114`` reads
it. Nothing else is: the extension's own TCA declares zero ``exclude`` keys, so
``endpoint_url``, ``allowed_groups``, the ``cost_*`` fields and the
``max_*_per_day`` caps travel with ``tables_modify``. That is not acceptable,
and choosing a UI does not fix it — the remedy is the field boundary in
:ref:`section 4 <adr-169-q4>` and the upgrade note in
:ref:`Consequences <adr-169-consequences>`.

.. _adr-169-q2:

2. Which records a non-admin may manage
---------------------------------------

**Recommendation: ``tx_nrllm_task``, ``tx_nrllm_promptsnippet`` and
``tx_nrllm_configuration``, the third with its governance and spend fields
excluded.** Those three hold prompt text and task shape. The six exclusions rest
on different reasons, which matters when one of them is argued back in:

- Provider, MCP server and skill source hold credentials and name the host the
  extension talks to. ``trust_zone`` on the provider is the ceiling forced
  context sources bind against (:ref:`ADR-164 <adr-164>`;
  :php:`TrustZoneResolver::zoneForProvider()`, ``TrustZoneResolver.php:48-50``).
- ``tx_nrllm_skill`` is written by the sync and its ``trust_level`` is
  denormalised from the source, where the authoritative edit lives
  (``Configuration/TCA/tx_nrllm_skill_source.php:119-122``).
- ``tx_nrllm_model`` holds no credential. It is out because
  ``cost_input`` / ``cost_output`` are what :php:`Model::estimateCost()`
  multiplies (``Model.php:668-673``) when the provider reports no cost
  (``UsageMiddleware.php:186-187``). Halving them doubles everyone's effective
  spend without touching a budget record.
- ``tx_nrllm_user_budget`` names a ``be_user`` and sets that user's ceiling.

Within ``tx_nrllm_configuration``, ``allowed_groups`` (``:413``) is the one
gate that already works for non-admins
(:php:`LlmConfigurationService::hasAccess()`, ``:127-143``, per
:ref:`ADR-070 <adr-070>`). Whoever can edit it can grant their own group access
to every configuration.

**Saying no** to the three-table subset means naming which of the six returns
and what replaces the reason above.

.. _adr-169-q3:

3. What the existing write paths do not run
-------------------------------------------

Both wizards write through Extbase repositories and :php:`persistAll()`:
:php:`TaskWizardController::wizardCreateAction()` (``:234-235``, ``:254-255``)
and :php:`SetupWizardController::persistWizardResult()` (``:376-377``,
``:379-383``). Nothing in ``Classes/`` constructs a :php:`DataHandler` for a
``tx_nrllm_*`` table. Five tools construct one, and they write ``tt_content``,
``pages`` and ``sys_file_metadata``: :php:`CreateContentElementDraftTool`,
:php:`MoveContentElementTool`, :php:`CreateTranslationDraftTool`,
:php:`SetFileAlternativeTextTool` and :php:`UpdatePageMetadataTool`.

**Does not run on the repository path:** record permissions
(``DataHandler.php:7428-7443``, ``:7657-7707``), the insert check
(``:7466-7473``), exclude fields (``:1114``), TCA ``eval`` / ``required`` /
``range`` / ``items``, ``sys_history``, the reference index, and the
extension's own hook :php:`ProviderEndpointNormalizationHook`
(``:39-60``).

**Does run:** whatever the controller wrote by hand. ``TaskWizardController``
clamps temperature, ``max_tokens``, ``top_p`` and both penalties (``:224-228``)
and allow-lists ``category`` and ``output_format`` (``:243-251``);
``SetupWizardController`` truncates the label (``:367``). Those are hand-rolled
equivalents of TCA ``range``, ``eval`` and ``items``, kept in step with the TCA
by nothing.

.. _adr-169-q4:

4. FormEngine or a purpose-built UI, and the field boundary
------------------------------------------------------------

**Option A — link into FormEngine.** What it buys is already written: 27
``required`` declarations, 7 ``eval`` rules, 30 ``select`` fields with their
item sets, 3 MM relations, 3 ``displayCond``, three ``itemsProcFunc`` providers
(``tx_nrllm_configuration.php:433``, ``:443``, ``:453``), the
``modelIdWithFetch`` render type (``tx_nrllm_model.php:132``), the
``modelConstraintsWizard`` field wizard (``tx_nrllm_configuration.php:152-153``),
``sys_history`` with rollback, the reference index, and the exclude boundary at
``DataHandler.php:1114``.

**Option B — a purpose-built write path, TCA untouched.** One door, so an
nr_llm grant would be a real control. It costs every validation above,
re-expressed in PHP and kept in step by hand; it costs ``sys_history``, so no
audit of who changed a system prompt and no rollback; and the exclude boundary
becomes an explicit field allow-list, because a generic field mapper writes
``github_token`` precisely because FormEngine cannot see it
(``type => 'passthrough'``, ``tx_nrllm_skill_source.php:114-118``).

**Option C — Option A plus ``exclude => true`` on the fields that must not
travel with ``tables_modify``:** ``tx_nrllm_configuration``'s
``system_prompt_data_class`` (``:222``), ``max_requests_per_day`` (``:339``),
``max_tokens_per_day`` (``:351``), ``max_cost_per_day`` (``:363``),
``allowed_groups`` (``:413``), ``allowed_tool_groups`` (``:427``),
``allowed_guardrails`` (``:437``), and ``tx_nrllm_promptsnippet``'s
``data_class`` (``:100``).

**Recommendation: Option C**, and it does not depend on section 1's outcome.
:php:`TcaItemsProcessorFunctions::getGroupedExcludeFields()` skips a table only
when it is ``TYPE_ONLY_ON_ROOTLEVEL`` without the flag
(``TcaItemsProcessorFunctions.php:271``), so these ``TYPE_BOTH`` tables already
appear in the be_groups picker. The flags are assignable today, and under
Option P they are the field boundary from the first day the surface exists.

Option C has a precondition. While ``SetupWizardController`` and
``TaskWizardController`` write through :php:`persistAll()`, the exclude flags
run on one path and not the other, and a reader of the TCA would assume
otherwise. Those two move onto the DataHandler or stay admin-only.

**Saying no** and choosing B means accepting the second list as the build scope
and naming who keeps the PHP validations in step with the TCA. Choosing plain A
means stating that ``tables_modify`` on ``tx_nrllm_configuration`` is an
acceptable grant of ``allowed_groups``.

.. _adr-169-q5:

5. The grant's name
-------------------

``tasks_manage`` is reserved in three places: ADR-130 named constraint 4
(``Adr130BackendUserGrants.rst:84-88``), ADR-131's "what stays out"
(``Adr131EditorModule.rst:67-68``) and the enum docblock
(``Classes/Domain/Enum/BackendUserGrant.php:24-27``). `#691` asks for something
wider — providers, models, configurations and tasks.

**Recommendation: neither name. Close `#691` as answered.** Section 2 removes
providers and models from the manageable set, and what remains is governed by
``tables_modify`` and ``non_exclude_fields``, not by an nr_llm grant. The wide
grant has no records to gate; the narrow one has none left that need it. The
reservation in ADR-130 and ADR-131 is retired rather than fulfilled.

**Saying no** — keeping a grant — requires naming the action it gates that
:ref:`section 7 <adr-169-q7>` does not already place elsewhere.

.. _adr-169-q6:

6. Module placement
-------------------

:ref:`ADR-119 <adr-119>` decided "keep the modules under Administration for
now" (``Adr119BackendModulePlacement.rst:94``) and already carries the status
``Accepted (deferred — the placement is not finally settled, see Revisit)``. Its
trigger is "the first cross-consumer editor surface … a personal
usage-and-budget view, a personal run history, or a lead-editor view over a
group" (``:105-108``), and it pre-settles four answers for that case
(``:110-129``): the section is called "AI"; the identifier is
``netresearch_ai``, never a bare ``ai``; the flat entries are grouped by subject
first; old routes keep working through kept identifiers plus
``'aliases' => ['nrllm']``.

**Has the trigger fired?** Not by ADR-131's own account. ``nrllm_aitasks`` ships
an actor-scoped run viewport, but the record states that "'Own runs' for editors
is mostly the approver's view", that agent runs "are currently started from
admin surfaces", and that the ownership filter "matters the moment any non-admin
path starts runs" (``Adr131EditorModule.rst:69-72``). A personal run history is
what that surface will become, not what it is.

What has changed is the count and the constraint. ADR-119 describes twelve
submodules; ``nrllm`` now has fourteen children, with ``nrllm_aitasks``
registered outside it under ``parent => 'web'``
(``Configuration/Backend/Modules.php:342-344``; 16 ``'parent'`` keys in the
file). It sits there for a verified platform reason: the module menu drops every
top-level module whose own access check fails, so a child of the admin-only
``nrllm`` (``:53``) would be invisible to non-admins
(``Adr131EditorModule.rst:29-33``). A management surface has that same
constraint.

**Recommendation: reopen ADR-119 — recommended here, not done here.** Without
it, the management surface becomes the second flat ``web``-parented module and
ADR-119's "twelve flat entries do not move as they are" arrives by accretion
instead of by decision. The ``:Status:`` and ``:Amended:`` edits belong to the
change that accepts the recommendation, per the lifecycle rule in
``Documentation/Adr/Index.rst``.

**Saying no** is defensible on cost. ADR-119's status already says it is not
settled, so nothing is misrepresented by leaving it.

.. _adr-169-q7:

7. Enforcement points
---------------------

``grep -rn 'denyNonAdmin()) instanceof' Classes/Controller/`` returns 40 sites.
All 40, by what the action touches:

- **Credential- or egress-bearing** (18): ``SetupWizardController`` ``:144``,
  ``:170``, ``:204``, ``:241``, ``:288``;
  ``ProviderController::testConnection`` ``:203``;
  ``ConfigurationController::testConfiguration`` ``:369``;
  ``ModelController::verifyCapabilities`` ``:238``;
  ``ModelDiscoveryController`` ``:63``, ``:156``; ``ModelTestController``
  ``:97``; ``SpecializedTestController`` ``:69``, ``:154``;
  ``SkillSourceController::sync`` ``:107`` and ``::setToken`` ``:176``;
  ``McpServerController::namedServer`` ``:240``; ``LlmModuleController``
  ``:206``, ``:249``.
- **Arbitrary table reads** (3), reserved admin-only by ADR-130 constraint 5:
  ``TaskRecordsController`` ``:67``, ``:90``, ``:158``.
- **Agent execution on the admin playground** (3, :ref:`ADR-038 <adr-038>`):
  ``ToolPlaygroundController`` ``:155``, ``:294``, ``:367``.
- **Site-wide tool kill switch** (2, :ref:`ADR-039 <adr-039>`):
  ``ToolController`` ``:80``, ``:108``.
- **Record-state toggles duplicating a TCA field** (6):
  ``ProviderController::toggleActive`` ``:150``; ``ModelController`` ``:164``,
  ``:200``; ``ConfigurationController`` ``:256``, ``:290``;
  ``SkillSourceController::toggleSkill`` ``:132``.
- **Read-only lookups behind the admin gate** (6):
  ``ModelController::getByProvider`` ``:287``; ``ConfigurationController``
  ``:321``, ``:448``; ``SpecializedTestController::translators`` ``:135``;
  ``PresetController`` ``:71``, ``:145``.
- **Bulk creation of AI-behaviour records, no credential** (2):
  ``PresetController::import`` ``:112``, ``::update`` ``:183``.

Two module-route actions write without a per-action gate, module-gated only, as
ADR-130 constraint 4 states: :php:`TaskWizardController::wizardCreateAction()`
(``:193``) and :php:`UseCasePackController::installAction()` (``:129``, via
``UseCasePackInstaller.php:150-172``).

**Recommendation: no grant case is added; the last bucket moves onto the
DataHandler.** Once preset import and use-case-pack install write as the acting
user, ``tables_modify`` on the three manageable tables authorises them — the
same control as every other write.

**The tool kill switch does not fit that argument.** ``tx_nrllm_tool_state`` has
no TCA
(``Classes/Service/Tool/ToolStateRepository.php:30``; ``Configuration/TCA/``
holds nine files, none of them this table), so ``tables_modify`` cannot express
it even in principle. It stays admin-only because :ref:`ADR-039 <adr-039>`
decided it is a hard admin kill switch — an earlier decision, not a consequence
of this one. So the claim is not that TYPO3's permission model covers all 40:
each of the 40 is credential-bearing, reserved admin-only by an earlier ADR, or
duplicated by a TYPO3 permission on the same field. Retiring ``tasks_manage``
survives on the full set.

.. _adr-169-fields:

Which fields stay admin-only
============================

Four extension-owned fields. Three are protected by where the code is, not by
the TCA.

``tx_nrllm_provider.api_key`` and ``tx_nrllm_mcp_server.auth_credential`` carry
``exclude => true`` from ``VaultFieldHelper.php:120``, read at
``DataHandler.php:1114``. That holds on the DataHandler path only:
``SetupWizardController.php:370`` and ``SetProviderApiKeyCommand.php:161`` write
``api_key`` directly, while ``auth_credential`` has no setter in ``Classes/`` at
all, only a read (``McpServerRepository.php:174``) — safe by the absence of a
writer, which is a fact about the current code, not a boundary.
``tx_nrllm_provider.endpoint_url`` has no flag on either path
(``tx_nrllm_provider.php:145-155``). ``tx_nrllm_skill_source.github_token`` is
``type => 'passthrough'`` (``:114-118``) and written only by
``SkillSourceController.php:190`` behind :php:`denyNonAdmin()`; a generic field
mapper would write it precisely because FormEngine cannot see it.

Two more are outside nr_llm's authority. Vault configuration lives in nr-vault's
modules, which are ``access => 'user'`` with per-action ``VaultPermission``
assertions and a comment forbidding re-tightening to ``admin``
(``nr-vault/Configuration/Backend/Modules.php:33-39``). Extension configuration
is edited in the Settings module, ``'access' => 'systemMaintainer'``
(``cms-install/Configuration/Backend/Modules.php:32``) — stricter than admin.

.. _adr-169-routing:

Routing policy: closed, not deferred
====================================

:ref:`ADR-140 <adr-140>` decided a read-only view — decision item 4 is "No apply
path. See below — this is the actual decision"
(``Adr140EffectivePolicyReadoutWithoutApplyPath.rst:64``, reasoning at ``:88``);
:ref:`ADR-145 <adr-145>` amends it as "the readout gains consumers, not an apply
path" (``:10``, ``:47``); the code repeats it at
``EffectivePolicyReadout.php:38``, ``GovernanceProfileEvaluator.php:21``,
``GovernanceProfileDeviation.php:15`` and ``LlmModuleController.php:296``. The
policy mode itself is a per-call argument
(:php:`RoutingDecisionService::decide()`, ``:62``), defaulting from extension
configuration (``:106``, ``:110``, ``:120-122``; ``ext_conf_template.txt:132``)
in the ``systemMaintainer`` Settings module. A grant over it would sit in front
of a read-only view and a function argument. Dropped from scope.

.. _adr-169-consequences:

Consequences
============

If the recommendations are accepted as they stand:

- :ref:`ADR-119 <adr-119>` is reopened by the accepting change, which edits its
  ``:Status:`` and ``:Amended:`` fields and applies the four pre-settled answers.
- :ref:`ADR-130 <adr-130>` named constraint 4, :ref:`ADR-131 <adr-131>`'s
  ``tasks_manage`` bullet and the docblock at
  ``Classes/Domain/Enum/BackendUserGrant.php:24-27`` are amended together: the
  grant is retired, not pending. Issue `#691` closes as answered.
- **Upgrade note.** A group holding ``tables_modify`` on a ``tx_nrllm_*`` table
  can already edit any record of that table sitting on a page it can edit —
  today, with no flag and no module. Such an entry is inert only where every
  record is still at pid 0. Option C's exclude flags narrow what it conveys, so
  the note names the fields that become gated and states that the entry itself
  is not new.
- Under Option P, moving existing records off pid 0 is a separate, deliberate
  migration. Under Option R, section 1's reach list is the blast radius.

.. _adr-169-revisit:

Revisit when
============

- A non-admin path starts writing ``tx_nrllm_*`` records outside FormEngine:
  Option C's precondition becomes a hole.
- A field is added to one of the three manageable tables. The split is per
  field; a new governance or spend field needs ``exclude => true`` in the same
  change.
- ``tx_nrllm_model`` is argued back in. It is out for the cost multipliers, not
  for credentials; if the usage path stops reading them, the reason expires.
- Anything starts filtering ``tx_nrllm_*`` reads by pid — that turns page
  storage into a scoping boundary and changes section 1's second cost.
- TYPO3 changes what ``ignoreRootLevelRestriction`` grants. Section 1 is read
  off 14.3.5; the 13.4 leg of the matrix was not enumerated.
