<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A configuration may exclude single tools

An LLM configuration narrows its tools only by whole groups (`allowed_tool_groups`,
ADR-043). Keeping one tool from it means dropping its group, for example all
twenty-two writing tools of `editing` to withhold `delete_record`. Decision
record: ADR-261.

## What it must do

- Add the field *Excluded tools* (`excluded_tools`) to the LLM configuration,
  a choice of the registered tools grouped by tool group. Empty excludes
  nothing; existing rows keep their tools.
- Refuse an excluded tool with the reason `configurationExcluded` when the
  tools are offered to the model and when a call is executed, also when the
  model calls it although it was not offered.
- Only narrow: the exclusion applies after the global state, the admin
  requirement, the skill allow-list and the group gate, and before the trust
  zone; it never makes a refused tool available.
- Leave other configurations untouched: a run with another configuration is
  still offered the tool.
- Store the exclusions with the run when it starts. A queued, suspended or
  approval-waiting run is refused the union of the stored and the live
  exclusions: a change while it waits can take a tool away, not give one back.
- Not exclude a tool registered later until it is selected.
- Apply to the skill load tool as well.
- List an excluded tool in the unavailable-tools answer (ADR-201) with its own
  reason.
- Show in the Tools module, per tool, the active configurations that exclude
  it, and on the Governance tab of the overview module the excluded tools per
  active configuration.
- Keep a stored name whose tool is no longer registered visible and removable
  in the field.

## What it explicitly does not do

- No allow-list of single tools and no patterns or wildcards.
- No exclusion per backend user, per task or per run.
- Configuration presets do not carry the field.
- No change to the group gate, which stays read live.

## Security boundaries

- The field is a deny list. No code path reads it to add a tool.
- A stored run list whose exclusions are not a list admits no tool, the skill
  load tool included.

## Which suite proves each requirement

| Requirement | Suite |
|-------------|-------|
| Gate order (also before the trust zone in observe mode), reason, no grant, load tool, stored ∪ live, tampered stored value | unit: `ToolCallPolicyTest` |
| Not offered, refused on execution (also a call offered before the exclusion), other configuration, later tool, resume both ways, approved call not executed, queued run | unit: `ToolLoopServiceExcludedToolsTest` |
| Stored form, intersection, declaration | unit: `SkillToolAllowListTest` |
| Run list carries the exclusions | unit: `AllowedToolsResolverTest` |
| Unavailable-tools reason | unit: `UnavailableToolsResolverTest` |
| Field items, unregistered names | unit: `ToolItemsTest` |
| Readout from the database column, NULL column | functional: `ToolExclusionReadoutTest` |
| Tools module and Governance tab, through their controller actions | functional: `ToolControllerTest`, `GovernanceTabRenderTest` |
