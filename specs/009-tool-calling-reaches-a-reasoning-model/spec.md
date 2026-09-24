# Tool calling reaches a reasoning model (#965)

`OpenAiProvider::chatCompletionWithTools()` posts every tool request to
`chat/completions`. On an OpenAI reasoning model that request is refused before
any tool runs:

```
Function tools with reasoning_effort are not supported for gpt-6-luna in
/v1/chat/completions. To use function tools, use /v1/responses or set
reasoning_effort to 'none'.
```

The extension sends no `reasoning_effort` at all — the string appears nowhere in
`Classes/`. The effort the message names is the model's own default, which OpenAI
documents as `medium` for GPT-6 Sol and Luna. So the refusal needs no
misconfiguration to trigger: it is what a default GPT-6 model does with the
payload this extension builds.

## What actually happens today, in order

1. `ToolLoopService::runLoop()` calls the adapter through
   `LlmServiceManager::chatWithToolsForConfiguration()`.
2. `OpenAiProvider::chatCompletionWithTools()` builds `model`, `messages`,
   `tools`, `max_completion_tokens` and the sampling parameters, and posts them
   to `chat/completions`.
3. OpenAI refuses the request. `AbstractProvider::handleResponse()` maps the 4xx
   to `ProviderResponseException` and does not retry it.
4. The operator turns the Playground's "Thinking" switch off and nothing
   changes. `ChatOptions::$think` is written into the options array, and the
   Ollama adapter is the only reader of that key. `ChatOptions::getThink()` has
   no caller anywhere in `Classes/`.

The second point is the one that makes this more than a missing parameter.
The switch that the interface offers for exactly this situation is wired to one
provider out of seven, and nothing anywhere says so.

## What the models actually allow

Taken from the model pages on 2026-09-23, not from the error message:

| Model | `reasoning.effort` | Function tools on Chat Completions |
| --- | --- | --- |
| `gpt-6-astra` | `low`, `medium`, `high`, `xhigh`, `max` | not supported at all |
| `gpt-6-sol` | `none`, `low`, `medium` (default), `high`, `xhigh`, `max` | only at `none` |
| `gpt-6-luna` | `none`, `low`, `medium` (default), `high`, `xhigh`, `max` | only at `none` |

GPT-6 Astra supports no `none` effort — the reasoning guide states that setting
it returns HTTP 400. So for Astra there is no Chat Completions payload that
calls a tool, and a `reasoning_effort: "none"` fix would close the issue for two
of the three models and leave the third with a different error.

## What it must do

- A tool request to a model that cannot take function tools on `chat/completions`
  — today the three GPT-6 models — goes to `/v1/responses`, with the
  tool definitions in the flat Responses shape, and the model's tool call comes
  back as an nr_llm `ToolCall` exactly as it does today.
- A tool result goes back as a `function_call_output` item whose `call_id` is the
  one the `function_call` item carried.
- Across the steps of one run, the items the model produced are replayed into
  the next request **verbatim** — the reasoning item with its
  `encrypted_content`, the `function_call` item, and then the
  `function_call_output`. OpenAI's reasoning guide requires everything between
  the last user message and the function-call output to be passed on untouched.
- The replay survives a suspend and a resume, because a run that waits for an
  approval is written to the database and read back.
- Reasoning effort is a request option in its own right, carried from
  `ChatOptions` to the provider, and the value that was actually applied is
  readable on the response.
- `think = false` selects the lowest effort a GPT-6 model allows: `none` where the
  model has it, `low` on GPT-6 Astra. The applied value is recorded, so a
  clamped request is distinguishable from an honoured one.
- A model whose profile says it is not a reasoning model keeps using
  `chat/completions`, unchanged. `gpt-4.1-mini` is the reporter's own control
  and must stay on the path it works on.
- An endpoint that is not `api.openai.com` keeps using `chat/completions` unless
  the configuration opts in with `"openai_tools_transport": "responses"` in its
  options JSON. An OpenAI-compatible gateway need not implement
  `/v1/responses`, and a transport switch it cannot serve turns a working
  installation into a broken one.
- Structured outputs keep working on the new transport: `response_format` becomes
  `text.format`, with the `json_schema` configuration flattened as the Responses
  API defines it.
- The setup wizard lists the GPT-6 models. Today `isRelevantOpenAIModel()` has no
  `gpt-6` pattern, so live discovery drops every one of them.
- Sampling parameters are stripped for `gpt-6*` as they already are for `gpt-5*`
  and the o-series. `isReasoningModel()` matches `/^(o[1-9]|gpt-5)/` and misses
  GPT-6 entirely.

## What it explicitly does not do

- **No Responses transport for plain chat, streaming or vision.** A reasoning
  model answers an ordinary completion on `chat/completions` at its default
  effort; only *tools* are refused there. Moving the other three call paths would
  change behaviour for every OpenAI user of this extension to fix a fault none of
  them have.
- **No `previous_response_id`, and `store` stays `false`.** Server-side retention
  of a conversation is a data-protection decision for the installation, not a
  transport detail, and this change is not the place to take it. Stateless replay
  gives the same continuity: in stateless mode the reasoning items carry
  `encrypted_content` by default.
- **No new `ModelCapability` case.** `CapabilitySeedTest` fails the build for a
  case no discoverer writes, and the enum's own docblock explains why a case
  nothing populates can only subtract models from a criteria match. Which
  transport a model needs is the adapter's business and stays inside it.
- **No built-in tools.** Web search, file search, code interpreter and the rest
  are reachable over the Responses API and are a feature, not this defect.
- **No schema change and no new TCA field.** The opt-in for a compatible endpoint
  is a key in the configuration's options JSON, and reasoning effort is a request
  option. Neither is a column. The provider record would be the better home for
  the opt-in, but `createAdapterFromModel()` drops the provider's extra options
  when it reconfigures the adapter — a defect of its own, fixed separately.
- **No live call to OpenAI.** No credential for it exists on this machine or in
  the Vault mounts that were searched (`netresearch/actors/sebastian.mendel/priv`,
  `IT`, `ci`, `operations`, `projects`, `netresearch`, `agent-registry`), nor in
  the environment or the DDEV configuration. Every request and response shape
  here comes from the published API reference, and the suites drive a faked
  PSR-18 client. The first real call is the measurement, and the PR says so.

## The two decisions that had a real alternative

### Responses transport, not `reasoning_effort: "none"`

Setting `reasoning_effort: "none"` on Chat Completions is three lines and makes
the reported Playground run pass. It also turns reasoning off to do it, and it
cannot work at all for GPT-6 Astra, which rejects that value. The result would be
an extension whose tool calling silently degrades the model it was pointed at,
and which still fails on the most capable one. The alternative is kept as the
behaviour of `think = false`, where turning reasoning off is what the operator
asked for — not as the way tool calling works.

### The opaque items ride on `ChatMessage`, and not in `toArray()`

Replay needs the provider's own items to survive from one step to the next, and
across a suspend. The transcript is a list of `ChatMessage`, so that is where
they belong: a new `?array $providerItems` at the end of the constructor.

They must not appear in `ChatMessage::toArray()`. That method is the OpenAI
Chat Completions wire shape, and four adapters — Groq, Mistral, OpenRouter and
Ollama — put its result straight into a request payload. An unknown key there
reaches four live APIs, and any OpenAI-shaped adapter added later inherits the
same fault. So `toArray()` is unchanged and a second, explicitly named
`toTranscriptArray()` carries the field for persistence, with `fromArray()`
reading it back. Two serialisers with almost the same output is the cost; the
alternative was four strip sites and a trap for the fifth.

## Where the values land

| Value | Home | Absent means |
| --- | --- | --- |
| Requested reasoning effort | `ChatOptions::withReasoningEffort()`, options key `reasoning_effort` | the model's own default applies |
| Applied reasoning effort | `CompletionResponse::$metadata['nrllm_reasoning_effort']` | the provider reported none, and none was sent |
| Opaque provider items | `ChatMessage::$providerItems`, `CompletionResponse::$metadata['nrllm_provider_items']` | this turn produced none — an empty array and a missing key stay different |
| Transport actually used | `CompletionResponse::$metadata['nrllm_transport']` | the response predates this change |

`CompletionResponse` is frozen in `api-surface.txt` and `AbstractOptions` argues
in its own docblock against growing it, so these three ride in the existing
`?array $metadata` slot under named constants, not as new constructor
parameters. `GuardrailMiddleware` already preserves `metadata` when it rebuilds a
screened response.

`ReasoningEffort` reaches `ToolOptions` through the inherited fluent setter, not
through a constructor parameter. `ToolOptions` repeats its parent's thirteen
parameters positionally before adding its own three, so appending to
`ChatOptions` alone would push `toolChoice` one place along — a breaking
reorder for a positional caller. The repository already treats
`suppressRequestCount` and `responseSchema` this way, and says so.

## Which suite proves each requirement

| Requirement | Proof |
| --- | --- |
| A tool request to a reasoning model goes to `/v1/responses` | `OpenAiResponsesToolCallTest::aToolRequestToAReasoningModelIsPostedToTheResponsesEndpoint` — asserts the request URI and the flat tool shape |
| A tool request to a non-reasoning model still goes to `chat/completions` | `OpenAiResponsesToolCallTest::aToolRequestToGpt41MiniStaysOnChatCompletions` — the reporter's control, asserted as a URI |
| A `function_call` item becomes a `ToolCall` | `OpenAiResponsesToolCallTest::aFunctionCallItemBecomesAToolCall` — id, name and decoded arguments |
| A tool result is sent as `function_call_output` with the matching `call_id` | `OpenAiResponsesToolCallTest::aToolResultIsSentBackWithTheCallIdItCameWith` |
| Reasoning and call items are replayed verbatim on the next step | `OpenAiResponsesToolCallTest::theSecondRequestReplaysTheReasoningItemUntouched` — asserts the second request's `input` contains the first response's reasoning item **identically**, including `encrypted_content` |
| The replay survives suspend and resume | `ToolLoopServiceTest::providerItemsSurviveASuspendAndResume` — persists through `toTranscriptArray()` and `json_encode()`, and asserts the items on the continuing request |
| A resumed run's stored items never reach a Chat Completions request | `ToolLoopServiceTest::aResumedTurnSendsItsItemsOnlyAsAValueObject` — the stored turn reaches the adapter as a `ChatMessage`, whose `toArray()` has no `provider_items` |
| A queued run keeps its items | `AgentRunRequestCodecTest::aQueuedTranscriptKeepsItsProviderItems` |
| GPT-5 and the o-series receive no effort | `OpenAiProviderTest::thinkingOffSendsNoEffortToGpt5OrTheOSeries` |
| A multimodal message reaches the Responses API as input parts | `OpenAiResponsesToolCallTest::anImagePartBecomesAnInputImage`, and `aPartOfAnUnknownTypeIsRefusedByName` |
| Tool definitions stay non-strict on the Responses transport | `OpenAiResponsesToolCallTest::aToolRequestToAReasoningModelIsPostedToTheResponsesEndpoint` — asserts `strict: false` |
| A failed reply raises instead of returning an empty answer | `OpenAiResponsesToolCallTest::aFailedReplyRaisesAProviderResponseException` |
| `toArray()` does not carry the opaque items | `ChatMessageTest::theWireShapeCarriesNoProviderItems` — and the mutation that adds the key reddens it |
| Requested effort reaches the payload | `OpenAiResponsesToolCallTest::theRequestedReasoningEffortReachesThePayload` |
| Applied effort is recorded | `OpenAiResponsesToolCallTest::theAppliedReasoningEffortIsReadableOnTheResponse` — read from the response's own `reasoning.effort` |
| `think = false` becomes `none` where the model allows it | `OpenAiResponsesToolCallTest::thinkingOffSendsTheNoneEffortToLuna` (on the wire), `OpenAiReasoningEffortTest::thinkingOffSelectsTheNoneEffortOnLuna` (the profile) |
| `think = false` is clamped on GPT-6 Astra, and the clamp is visible | `OpenAiResponsesToolCallTest::thinkingOffIsClampedToLowOnAstraOnTheWireAndInTheMetadata` — asserts `low` on the wire **and** in the metadata, with the source `request` |
| Sampling parameters are stripped for `gpt-6*` | `OpenAiProviderTest::chatCompletionStripsSamplingParamsForReasoningModel` — the data provider gains the three GPT-6 ids |
| Structured output works over the Responses transport | `OpenAiResponsesToolCallTest::aJsonSchemaBecomesTextFormat` |
| A non-OpenAI endpoint keeps Chat Completions | `OpenAiResponsesToolCallTest::aCompatibleEndpointKeepsChatCompletionsUnlessItOptsIn` — both directions in one case |
| Discovery lists the GPT-6 models | `ModelDiscoveryTest::gpt6ModelsAreRelevantAndCarryTheirPublishedSpecs` |
| A registered tool's turn replays its items on the next request of a run | `ToolLoopServiceTest::providerItemsReachTheNextRequestOfARun`, and `aTurnWithoutProviderItemsCarriesNone` for every other adapter |
| The input guardrail keeps the items when it rewrites a message | `InputGuardrailScreenerTest::aRedactionKeepsTheProviderItemsOfTheTurn` |
| A resumed run keeps its reasoning effort | `ToolOptionsTest::aResumedRunKeepsItsReasoningEffort` |
| A multi-step call / result / answer sequence completes | `OpenAiResponsesToolCallTest::aCallAResultAndAnAnswerCompleteInThreeRequests` |

Every case above is a unit test. The provider cases drive a faked PSR-18 client;
the `ToolLoopServiceTest` cases script the manager, and the suspend case sends the
state through `json_encode()` and `SuspendedRunState::fromArray()` the way a real
suspension is stored. `make gate` runs all of them.

Each assertion the change exists for was seen to fail: with the verbatim replay
removed, with `provider_items` added to `toArray()`, with the transport switch
removed, with the `think` translation removed, with the suspend written in the
wire shape again, with the items not attached to the turn, and with the
guardrail rebuild dropping them. Each of those seven mutations reddened the
test written for it. Removing the transport switch reddened 14 of the 16
provider cases, because nearly every one of them needs the Responses endpoint;
the other six mutations reddened one or two cases each.

## Records

- ADR-203 — the transport decision and the carrier for the opaque items.
- ADR-204 — reasoning effort as a request option, and what `think` now means.
