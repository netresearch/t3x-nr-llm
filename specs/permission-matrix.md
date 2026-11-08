# Management permission matrix (C11)

Status: current contract; reviewed against the TCA and controller boundaries on
2026-11-08. This replaces the three-table restriction in draft 003. It does not
propose new blanket administrator restrictions. A module menu entry is never an
editing grant.

## Native List, FormEngine and DataHandler

For every row below, `rootLevel=-1` permits both placements structurally. It does
not allow a non-admin to insert/update at pid 0. No listed TCA opts out of the
root restriction. A non-admin at pid 0 is refused; an administrator is permitted.
On a page, a non-admin needs an accessible web mount, the relevant page permission,
table operation grants and any excluded-field grant. Lacking one refuses that
operation. Native List/read access uses module/table-select/page rights; FormEngine
editing and DataHandler writes retain their own native checks. A visible list row
therefore does not imply write permission.

| Table | Administrator CRUD | Non-admin at pid 0 | Non-admin on authorized page | Field-specific boundary |
|---|---|---|---|---|
| `tx_nrllm_provider` | Native checks permit | Refused | Native grants permit | Excluded fields require grants; credentials remain nr-vault UUID references |
| `tx_nrllm_model` | Native checks permit | Refused | Native grants permit | Excluded fields require grants; discovered metrics are read-only in forms |
| `tx_nrllm_configuration` | Native checks permit | Refused | Native grants permit | Excluded fields require grants; configuration use has separate actor/group checks |
| `tx_nrllm_task` | Native checks permit | Refused | Native grants permit | Excluded fields require grants; running a task is separately authorized |
| `tx_nrllm_promptsnippet` | Native checks permit | Refused | Native grants permit | Excluded fields require grants |
| `tx_nrllm_glossary` | Native checks permit | Refused | Native grants permit | Excluded fields require grants; translation service use is not a CRUD grant |
| `tx_nrllm_term_list` | Native checks permit | Refused | Native grants permit | Excluded fields require grants; mandatory runtime screening does not authorize editing |
| `tx_nrllm_skill` | Native checks permit | Refused | Native grants permit | Backend-source authoring exposes identifier/name/description/body/tool/process/page fields; synced versions remain form-read-only |
| `tx_nrllm_skill_source` | Native checks permit | Refused | Native grants permit | Source-type restrictions and sync/approval controls remain separate |

Form-read-only is a form behavior, not a universal service or storage ACL. A synced
skill modified outside the form is checked against its approved version digest;
changing source does not bless changed content. Only authorized backend authoring
and the explicit administrator approval route produce approved behavior.

## Controller and service operations

| Entry point / operation | Administrator | Non-admin | Boundary and evidence |
|---|---|---|---|
| Provider/model/configuration AJAX mutations (toggle, default, discovery/test/import where guarded) | Allowed through own route and validation | Refused by `denyNonAdmin()` at protected endpoints | [RequiresBackendAdminTrait](../Classes/Controller/Backend/RequiresBackendAdminTrait.php), corresponding controller tests |
| Skill version review/approve/revoke route | Allowed with route/CSRF token and digest validation | Refused even with skill table editing rights | [SkillApprovalController](../Classes/Controller/Backend/SkillApprovalController.php), [functional approval fixture](../Tests/Functional/Controller/Backend/SkillApprovalControllerTest.php) |
| Native backend skill form authoring | Native record/field rules | Permitted only with the native grants above | [skill TCA](../Configuration/TCA/tx_nrllm_skill.php), [form-link fixture](../Tests/Functional/Controller/Backend/SkillSourceListBackendSkillsTest.php) |
| Configuration resolution/use with explicit actor | Actor authorization applied | Actor/group authorization applied | [ConfigurationResolver](../Classes/Service/ConfigurationResolver.php), [site actor fixture](../Tests/Unit/Service/ConfigurationResolverSiteTest.php) |
| Configuration resolution without actor | Restrictive no-actor path | Restrictive no-actor path | Restricted explicit site selection is refused; L04 |
| Trusted PHP management/repository services | Caller owns authorization | Caller owns authorization | Calling a service directly is not a backend route or a grant; no universal implicit actor is invented |
| Usage/budget public readers | Caller owns authorization | Caller owns authorization | User IDs/filter omission do not enforce visibility; L05 and spec029 |

Ownership: [ADR169](../Documentation/Adr/Adr169RecordManagementUsesTypo3Permissions.rst),
[TCA](../Configuration/TCA/tx_nrllm_provider.php),
[backend skill contract](012-approved-skill-versions/spec.md).
The [functional management permission matrix](../Tests/Functional/Permissions/ManagementPermissionMatrixTest.php) exercises native DataHandler and
FormEngine checks; controller fixtures cover protected direct actions. These are
server-side fixtures, not a claim of newly executed browser/List UI acceptance.
