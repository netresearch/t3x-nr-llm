# Bundled OpenAI profile conformance (C12)

Revision: 2026-11-08. Scope: the named families recognized by `OpenAiProvider`,
including compatible gateways using that adapter. Other adapters, including
OpenRouter, retain their own wire protocols. This is a maintained encoding
contract, not a live-provider interoperability receipt.

| Identifier / snapshots | Reasoning scale sent by this profile | `None` / `think=false` | Default tool route at an official OpenAI endpoint | Verification source |
|---|---|---|---|---|
| `gpt-6.1-sol`, dated snapshots | Low, Medium, High, XHigh, Max | Low | Responses | Model reference cited in the option manual, checked 2026-11-07; profile/wire fixtures |
| `gpt-6-astra`, dated snapshots | Low, Medium, High, XHigh, Max | Low | Responses | Bundled profile model-page review dated 2026-09-23; profile/wire fixtures |
| `gpt-6-sol`, dated snapshots | None, Low, Medium, High, XHigh, Max | None | Responses | Same bundled model-page review; profile/wire fixtures |
| `gpt-6-luna`, dated snapshots | None, Low, Medium, High, XHigh, Max | None | Responses | Same bundled model-page review; profile/wire fixtures |
| `gpt-5*` | No scale invented or sent | No guessed effort | Chat Completions | Preserved compatibility policy; not a claimed scale for every model |
| `o[1-9]*` | No scale invented or sent | No guessed effort | Chat Completions | Preserved compatibility policy; family members differ |
| Unknown deployment/gateway or future family | No scale; no inferred reasoning capability | No guessed effort | Legacy Chat Completions route | Conservative unknown profile; no live capability claim |

Automatic Responses selection also requires `endpointIsOpenAi()`. Compatible
gateways retain Chat Completions by default, even for a recognized GPT-6 name;
only an explicit transport override changes that route. The profile does not
attest a gateway's upstream capabilities.

For the four named GPT-6 families, only the exact base name and a
`-YYYY-MM-DD` dated suffix are recognized. A new prefix is not silently granted
another family's scale. Known profiles strip incompatible sampling parameters;
unknown profiles preserve the prior transport behavior instead of claiming a
new capability. Explicit transport options follow the adapter's validation and
replay rules; they are not permission to send unsupported tools.

Reasoning metadata records the applied/provider-reported effort where available.
Resume retains provider-native replay items; fallback resolves the actual model's
profile rather than assuming the primary's settings. Fixture success proves
encoding and replay behavior, not external model availability or invoice amounts.

Implementation and acceptance:

- [Profile selection](../Classes/Provider/OpenAi/OpenAiModelProfiles.php) and
  [profile behavior](../Classes/Provider/OpenAi/OpenAiModelProfile.php).
- [Profile vectors](../Tests/Unit/Provider/OpenAiReasoningEffortTest.php),
  [wire/Responses replay](../Tests/Unit/Provider/OpenAiResponsesToolCallTest.php),
  [OpenAI payload fixtures](../Tests/Unit/Provider/OpenAiProviderTest.php).
- [Public option manual](../Documentation/Api/OptionClasses.rst) and its
  [GPT-6.1 Sol source](https://developers.openai.com/api/docs/models/gpt-6.1-sol).

A profile change updates this table, declared source/date, option guidance and
request/replay fixtures in the same change. Unknown identifiers stay conservative
until such a change is reviewed. A live qualification receipt must separately
name provider endpoint, model identifier/revision, date and tested operations.
