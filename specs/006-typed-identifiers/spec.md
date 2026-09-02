# Typed provider identifiers (#893), step one

Four concepts travel through this extension as bare `string`: the provider
ROW identifier, the provider ADAPTER key, the configuration identifier and the
model identifier. They are not interchangeable, and one of the confusions has
already shipped.

## The defect this exists to prevent, as the record describes it

0.32.0 gave `vision()` and `embed()` the default-configuration fallback `chat()`
already had, and handed `KeyedProviderRegistry` the wrong kind of key. 0.33.0
fixed it and named the shape exactly (#873):

> the registry is keyed by `ProviderInterface::getIdentifier()` — the adapter's
> own name, `openai` — while `Domain\Model\Provider::getIdentifier()` is the
> `tx_nrllm_provider` row's identifier, `openai-dcbd8f` on any installation set
> up through the wizard. **Two namespaces behind one method name.**

A type system can make that unrepresentable. Nothing else can: the two values
are both non-empty strings, both come from a method called `getIdentifier()`,
and the wrong one produces a plausible "Provider … not found" rather than an
error that points at the mistake.

## Scope of this step

**One** value object, landing with the reader that gives it meaning:
`ProviderAdapterKey`, read by `KeyedProviderRegistry` — the class where the
shipped confusion actually lived.

The other three are NOT added here, and the reason is the same for all of them:
a declaration nothing reads is worse than none.

`ProviderIdentifier` was drafted for this step and pulled back out. Its reader
would be `ProviderRepository::findOneByIdentifier()`, and converting that turned
out to reach about twenty call sites across controllers, services and the E2E
suite — with the added trap that `findOneByIdentifier()` is the name of a method
on FOUR repositories, so a mechanical conversion touches Model, Configuration
and PromptSnippet lookups that must keep taking strings. That is its own
reviewable change, not an appendix to this one.

`ConfigurationIdentifier` and `ModelIdentifier` arrive with their own conversion
steps for the same reason.

## What it must do

1. One `final readonly` value object, `@internal`, refusing a blank value.
2. `KeyedProviderRegistry`'s four identifier-taking methods take it.
3. `LlmServiceManager` converts at its public boundary, so callers outside this
   extension see no change.

## What it must NOT do

- **Not move the frozen surface.** `LlmServiceManager::configureProvider(string
  $identifier, …)` and its siblings are in `Tests/Unit/Api/api-surface.txt` and
  keep their `string` parameters until the 1.0 freeze decides otherwise (#895).
  `KeyedProviderRegistry` appears there only as a constructor PARAMETER TYPE, so
  its own method signatures are free.
- **Not convert what it cannot give a reader.** See the three deferred objects
  above.
- **Not add a runtime validation the type already carries.** A method taking
  `ProviderAdapterKey` does not re-check that the string is non-empty; the value
  object did that at construction, once.

## Which suite proves what

| Requirement | Suite | Assertion |
|---|---|---|
| The object refuses a blank value | unit | and a whitespace-only one |
| The registry looks up by adapter key | unit | an existing registration is found under its adapter name |
| A row identifier no longer reaches the registry as itself | unit | the manager's boundary is the only place a string becomes a key |
| The public boundary is unchanged | unit | `api-surface.txt` does not move |
| Fixtures use visibly different values | unit | `openai` vs `openai-dcbd8f`, so a swap fails rather than passing by coincidence |
| The public boundary tolerates an empty string | unit | it yields no key, as `null` already did, rather than a new refusal |

## Public surface

Unchanged, and asserted by the snapshot. The value object is `@internal`; it
becomes public only if #895 finds a consumer that needs it.

## Gate

`make gate`.
