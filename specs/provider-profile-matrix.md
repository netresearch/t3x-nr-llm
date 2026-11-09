# Bundled OpenAI profile conformance (C12)

Revision: 2026-11-09. Scope: the named families recognized by `OpenAiProvider`,
including compatible gateways using that adapter. Other adapters, including
OpenRouter, retain their own wire protocols. This is a maintained encoding
contract; the GPT-6 scales below rest on the live measurement named in the
table.

| Identifier / snapshots | Reasoning scale sent by this profile | `None` / `think=false` | Default tool route at an official OpenAI endpoint | Verification source |
|---|---|---|---|---|
| `gpt-6.1-sol`, dated snapshots | Low, Medium, High, XHigh | Low | Responses | Live measurement 2026-11-09 (below) |
| `gpt-6-astra`, dated snapshots | Low, Medium, High, XHigh | Low | Responses | Live measurement 2026-11-09 (below) |
| `gpt-6-sol`, dated snapshots | None, Low, Medium, High, XHigh | None | Responses | Live measurement 2026-11-09 (below) |
| `gpt-6-luna`, dated snapshots | None, Low, Medium, High, XHigh | None | Responses | Live measurement 2026-11-09 (below) |
| `gpt-6.1-terra`, dated snapshots | None, Low, Medium, High, XHigh | None | Responses | Live measurement 2026-11-09 (below) |
| Any other `gpt-6*` | No scale invented or sent | No guessed effort | Responses | GPT-6 family default; the two facts every measured member shares |
| `gpt-5*` | No scale invented or sent | No guessed effort | Chat Completions | Preserved compatibility policy; not a claimed scale for every model |
| `o[1-9]*` | No scale invented or sent | No guessed effort | Chat Completions | Preserved compatibility policy; family members differ |
| Unknown deployment/gateway or future family | No scale; no inferred reasoning capability | No guessed effort | Legacy Chat Completions route | Conservative unknown profile; no live capability claim |

Automatic Responses selection also requires `endpointIsOpenAi()`. Compatible
gateways retain Chat Completions by default, even for a recognized GPT-6 name;
only an explicit transport override changes that route. The profile does not
attest a gateway's upstream capabilities.

For the five named GPT-6 families, only the exact base name and a
`-YYYY-MM-DD` dated suffix get that family's scale. A new name is not
silently granted another family's scale. Any other id starting with `gpt-6`
gets the GPT-6 family default instead: a reasoning model, so sampling
parameters are stripped, with tools through Responses and no effort scale, so
no effort is sent. Every measured GPT-6 model refuses a non-default
`temperature` and refuses function tools on `chat/completions` at its default
effort; treating an unlisted member as an unknown model would send both and
fail. Known profiles strip incompatible sampling parameters; unknown profiles
preserve the prior transport behavior instead of claiming a new capability.
Explicit transport options follow the adapter's validation and replay rules;
they are not permission to send unsupported tools.

Reasoning metadata records the applied/provider-reported effort where available.
Resume retains provider-native replay items; fallback resolves the actual model's
profile rather than assuming the primary's settings. Fixture success proves
encoding and replay behavior, not external model availability or invoice amounts.

## Live measurement, 2026-11-09

Endpoint `https://api.openai.com/v1/chat/completions`, one netresearch
service-account key, each GPT-6 id that `GET /v1/models` listed for that key
(`gpt-6-sol`, `gpt-6-luna`, `gpt-6-astra`, `gpt-6.1-sol`, `gpt-6.1-terra`).

- `reasoning_effort` `minimal` and `max` returned HTTP 400 for all five. The
  error text named the supported values: `none`, `low`, `medium`, `high`,
  `xhigh` for Sol, Luna and 6.1 Terra; `low`, `medium`, `high`, `xhigh` for
  Astra and 6.1 Sol. The model pages read on 2026-09-23 also listed `max`.
- `gpt-6.1-terra`, which the table did not list before this revision:
  `temperature: 0.3` returned HTTP 400 ("Only the default (1) value is
  supported"); a function tool without `reasoning_effort` returned HTTP 400
  ("Function tools with reasoning_effort are not supported … use
  /v1/responses or set reasoning_effort to 'none'"); a plain request and
  `reasoning_effort: none` returned HTTP 200.
- `stop: ["\n"]` returned HTTP 400 ("Unsupported parameter: 'stop' is not
  supported with this model") for all five GPT-6 ids, `gpt-5.2` and `o3`;
  `gpt-4o` returned HTTP 200. A reasoning profile therefore drops `stop`
  together with the sampling parameters.
- `GET /v1/models/<id>` returned HTTP 404 for `gpt-6-sol-latest`,
  `gpt-6-astra-pro` and `gpt-6.1-terra-pro`. The model object carries only
  `id`, `object`, `created`, `owned_by` and `shutdown_date`; it states no
  reasoning, effort or tool capability.

Implementation and acceptance:

- [Profile selection](../Classes/Provider/OpenAi/OpenAiModelProfiles.php) and
  [profile behavior](../Classes/Provider/OpenAi/OpenAiModelProfile.php).
- [Profile vectors](../Tests/Unit/Provider/OpenAiReasoningEffortTest.php),
  [wire/Responses replay](../Tests/Unit/Provider/OpenAiResponsesToolCallTest.php),
  [OpenAI payload fixtures](../Tests/Unit/Provider/OpenAiProviderTest.php).
- [Public option manual](../Documentation/Api/OptionClasses.rst).

A profile change updates this table, declared source/date, option guidance and
request/replay fixtures in the same change. Unknown identifiers stay conservative
until such a change is reviewed. A live qualification receipt must separately
name provider endpoint, model identifier/revision, date and tested operations.
