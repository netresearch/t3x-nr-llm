<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A model record carries its reasoning profile

Governing decision: ADR-275. A new OpenAI model needs a release before the
adapter sends it correctly, because whether it is a reasoning model, which
efforts it accepts and which tool route it takes live only in
`OpenAiModelProfiles`. This feature lets the model record supply that
profile, from the operator or from discovery, without a release.

## Requirements

- **R1 Operator override.** A model record has three optional fields:
  reasoning (empty / yes / no), effort scale (empty, or a list of
  `ReasoningEffort` values) and tool route (empty / Chat Completions /
  Responses). A non-empty field wins over every other source.
- **R2 Resolution order.** For each field: operator override, then the
  bundled table for an exact family name or dated snapshot, then the
  discovered profile, then the family default in code, then the unknown
  profile. The tool route skips the discovered profile.
- **R3 Discovered profile, provider's own API.** Discovery for an
  OpenRouter provider stores, per model, reasoning yes/no and the effort
  scale from OpenRouter's `reasoning` object, with source `discovery` and
  the run's timestamp.
- **R4 Discovered profile, third-party catalogue.** When the extension
  setting is on, discovery for an OpenAI provider looks up
  `openai/<model id>` in OpenRouter's public model list and stores only
  reasoning yes/no, with source `third_party_catalog`. Off by default. One
  anonymous `GET`, no credential, no content; never at request time; a
  failure stores nothing and changes nothing.
- **R5 Not measured stays distinguishable.** A value discovery did not
  find is stored as empty, never as "no". A discovery run that finds no
  data for a model clears nothing the operator set.
- **R6 Adapter.** An OpenAI adapter created from a model record sends by the
  resolved profile for that record's model id. A request whose `model`
  option names a different id uses the table alone. An adapter created
  without a record behaves as before.
- **R7 Form constraints.** The configuration form offers no temperature for
  a model whose resolved profile is a reasoning model. The profile applies
  only to adapter types served by `OpenAiProvider` (OpenAI, Azure OpenAI,
  Together, Fireworks, Perplexity, custom); an OpenRouter model's
  discovered profile is shown on the record and applied nowhere, because
  the OpenRouter adapter sends `temperature` regardless.
- **R8 Backend display.** The model record editor shows the override
  fields, and read-only the discovered profile with source and date. The
  model list shows the resolved reasoning flag and its source.
- **R9 Compatibility.** Existing records keep their behaviour: all new
  fields default to empty. Public API changes are additive and recorded in
  the API snapshot. `CapabilitySource` gains `ThirdPartyCatalog`.

## Out of scope

- Sending a reasoning effort through the OpenRouter adapter.
- Reasoning profiles for Mistral, Gemini, Anthropic, Groq or Ollama.
- Changing the bundled table's measured rows (`specs/provider-profile-matrix.md`).
- Any lookup while a request is being sent.
- An override meaning "this endpoint accepts no effort". An empty effort
  override defers to the next source, so a model id the bundled table knows
  keeps the table's scale, and a request that asks for an effort sends one.
  No endpoint serving such a model id is known to refuse the parameter; one
  that does would need a new override value.

## Verification

| Requirement | Evidence |
| --- | --- |
| R1, R2, R6 resolution order and adapter | unit tests per source and per field, wire tests on `OpenAiProvider` with a record-built adapter |
| R3 OpenRouter discovery | unit test on the OpenRouter discoverer with a recorded `/models` fixture |
| R4 opt-in catalogue, no credential, failure | unit tests: off by default, request carries no `Authorization`, failure leaves the record unchanged |
| R5 not measured | unit and functional tests: empty stays empty across a discovery run |
| R7 form constraints | `ConfigurationControllerTest` with an override on a non-table model id |
| R8 backend | functional TCA test, model list template test |
| R9 compatibility | API surface test; functional schema test with defaults |
| Final checks | `make gate`; GitHub matrix |

The catalogue lookup adds one outbound request to a third party during
discovery, off by default, carrying no credential or content. No other
boundary changes.
