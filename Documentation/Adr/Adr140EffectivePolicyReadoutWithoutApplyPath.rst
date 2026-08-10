.. _adr-140:

=========================================================
ADR-140: The effective-policy readout has no apply path
=========================================================

:Status: Accepted (the readout gains consumers — see :ref:`ADR-145 <adr-145>`)
:Date: 2026-08-09
:Amended: 2026-08-10 by :ref:`ADR-145 <adr-145>`

Context
=======

Governance is spread over ``ext_conf_template.txt``, several TCA tables,
``be_groups`` and three dashboard widgets. There was no single place where an
operator could see the **effective** state — the values the runtime actually
applies right now. Four keys carry real decision content:

- ``privacy.level``
- ``privacy.retentionDays``
- ``tools.dataClassEnforcement``
- ``skills.minTrustLevel``

The obvious next step after showing a value is letting the operator change it
there. That step is the decision this ADR records, and the answer is no.

Decision
========

A read-only view, in an existing module, reading through the runtime resolvers.

1. **Read through the same resolver as the runtime, never a second parser.**
   :php:`ToolCallPolicy::enforcing()` moved verbatim into
   :php:`Service\\Tool\\DataClassEnforcementResolver`, which both the gate and
   the view now ask. :php:`SkillComposerFactory` exposes the previously private
   :php:`minTrustLevel()`. Privacy keeps its existing
   :php:`PrivacyPolicyInterface`. A view with its own copy of the parsing rules
   would drift from behaviour on the first change to either side — and a
   governance view that is *almost* right is worse than none, because an
   operator acts on it.

2. **The value shown is what the gate DOES, not the literal setting.**
   ``tools.dataClassEnforcement`` is fail-closed (:ref:`ADR-113 <adr-113>`):
   only a literal ``observe`` observes. A typo (``observ``) therefore reads as
   ``enforce`` in the view, because that is what the runtime applies. Echoing
   the raw string would tell an operator the axis is off while it is enforcing.

   The same rule forces a qualification on ``observe``. The gate computes
   :php:`$this->enforcement->enforcing() || $tool instanceof RemoteToolInterface`
   (:php:`ToolCallPolicy::decide()`), so observe mode covers builtins only —
   an MCP tool above the ceiling is denied outright whatever the setting says
   (:ref:`ADR-115 <adr-115>`). The row therefore carries a note saying so. The
   scenario is not exotic: :php:`DataClassEnforcementDefaultUpdateWizard` pins
   any upgraded install that already has providers and never chose a mode to
   ``observe``, and a server whose trust zone is unset falls back to
   ``EXTERNAL_GLOBAL`` — the lowest ceiling there is. An
   unqualified ``observe`` would send the operator whose MCP tool is being
   dropped looking anywhere but at the trust zone.

3. **A resolver that cannot be asked yields "unknown", never a value.** No
   substituted default, no reconstruction from the raw setting. A row that
   admits it does not know is safe; a plausible wrong value is not.

4. **No apply path.** See below — this is the actual decision.

5. **No provenance column** ("shipped default" vs "explicitly set"). No
   resolver exposes provenance, and it is not reconstructable from the stored
   configuration either: TYPO3's own
   :php:`ExtensionConfiguration::synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions()`
   (``ExtensionConfiguration.php:197-218``) merges every ``ext_conf_template.txt``
   default into ``settings.php`` whenever an unknown key is read or the Install
   Tool is entered. By the time anything could ask, the shipped defaults are
   already stored values.

6. **No new backend module.** :ref:`ADR-119 <adr-119>` already calls twelve flat
   entries a dumping ground. The readout is a **Governance** tab of the
   Overview module (``nrllm_overview``, action ``governance``), not a
   thirteenth admin entry. Overview rather than Analytics: Analytics answers
   "what did this install spend and do over time" from usage rows; the readout
   answers "what does this install allow right now" — a static property of the
   install, which is what the Overview already reports through its readiness
   cards. Because
   :php:`LlmModuleController::buildDocHeaderTabMenu()` builds links to real
   routes rather than in-page tabs, the tab needed a real action, a template,
   registration in ``Configuration/Backend/Modules.php`` and XLIFF keys in both
   language files.

Why there is no apply path
--------------------------

The only API for writing extension configuration is
:php:`ExtensionConfiguration::set()`. Three properties, each verified against
the shipped core:

- **It is ``@internal``** (``ExtensionConfiguration.php:150``) and documented as
  *"Set a full extension configuration"* — it takes the whole array and calls
  :php:`setLocalConfigurationValueByPath('EXTENSIONS/' . $extension, $value)`
  (``:166``). There is no per-key write.
- **It materialises every shipped default.** A caller has no source for the
  array other than :php:`get()`, which returns the template-merged result. Our
  own :php:`DataClassEnforcementDefaultUpdateWizard::executeUpdate()`
  (``Classes/Updates/DataClassEnforcementDefaultUpdateWizard.php:77-85``) does
  exactly this: ``get()`` → mutate one key → ``set()``. Every default in
  ``ext_conf_template.txt`` becomes an explicitly stored value.
  :php:`storedEnforcement()` (``:121-131``) — the "did the operator ever choose
  a mode?" probe :ref:`ADR-115 <adr-115>`'s wizard depends on — could then never
  return ``null`` again.
- **``additional.php`` spoils it.** The core docblock says so outright
  (``ExtensionConfiguration.php:145-146``): if that file overwrites a setting,
  ``->set()`` "may not end up as expected". An apply button would report success
  and the next request would serve the old value — the worst failure mode a
  governance UI can have.

The Install Tool stays the place where instance-wide keys are set. It owns the
write, the synchronisation and the cache flush; a second writer in a module
would only add a way to get them out of step.

Constraints and honest limits
-----------------------------

- **The ADR-115 argument is weaker than it looks, and does not carry the
  decision alone.** ``storedEnforcement()`` reads
  ``$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']``, whose docblock calls it the raw
  stored configuration "pre-template-merge". It is not: the core
  synchronisation writes the merged template into ``settings.php`` and into that
  same global. On an install that has entered the Install Tool since the default
  flipped, the "default vs chosen" distinction is *already* gone without any
  apply path. The apply path would guarantee the loss rather than cause it. The
  ``@internal`` full-array write and the ``additional.php`` hazard are the
  load-bearing reasons; ADR-115 is corroborating, not decisive.
- **The seven ``privacy.retention.*`` overrides are shown only where they
  deviate.** On the *shipped defaults* they all read ``0`` and resolve to the
  global window, so listing them adds seven rows reading "30" and informs
  nobody — that argument covers a stock install and nothing else. Once an
  operator sets one, ``privacy.retentionDays`` is no longer the window that
  category is purged on, and a page promising what the install "actually
  applies" would carry a single retention number that is wrong for it. A
  category whose :php:`PrivacyPolicyInterface::retentionDaysFor()` differs from
  :php:`retentionDays()` therefore gets its own row, directly under the global
  one; the rest stay in the documentation
  (:ref:`administration-data-retention`).
- **"Unknown" is a guarantee, not a common state.** All three current resolvers
  are fail-closed and swallow their own read errors, so on a working install
  every row is answered. The guarantee exists so a future resolver — or a
  partially booted container — can never be rendered as a value it did not
  return.
- **Read-only means read-only.** The view has no state, no AJAX route, and no
  write of any kind; it is registered as an ``admin``-only module action like
  every other nr_llm admin surface.

Consequences
============

- The enforcement read has exactly one implementation. The functional test
  wires the gate and the view from the same container resolver and asserts that
  flipping ``tools.dataClassEnforcement`` moves both — the view cannot drift.
- :php:`ToolCallPolicy` no longer depends on :php:`ExtensionConfiguration`; it
  takes :php:`DataClassEnforcementResolver` as a required constructor argument.
  Behaviour is unchanged, including the "no configuration at all enforces" case
  the old nullable dependency produced.
- :php:`SkillComposerFactory::minTrustLevel()` is public. The composer is still
  built through :php:`create()`; the accessor only exposes what it already
  computed.
- Operators still change these keys in the Install Tool. The readout links
  nowhere and changes nothing; it tells them what is in force.
