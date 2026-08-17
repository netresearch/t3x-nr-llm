## AI feature obligations — specification stage

These are the questions an AI feature gets wrong in the same way every time. Answer them here, in prose, before anything picks a provider or a class.

1. **Which provider capability does this assume?** Structured output, tool calling, streaming, vision, a context window of a given size. Name it.
2. **What happens on a provider that lacks it?** There are three defensible answers and you must pick one: refuse the call, fall back to a provider that has it, or degrade to a weaker behaviour. Say which — and say whether the fallback is automatic or something the caller opts into, because "it falls back" without that is not a specification.
3. **How does the caller find out which one happened?** A feature whose answer was produced by a degraded path, indistinguishable from one that was not, cannot be debugged or trusted downstream.
4. **What leaves the instance, and to whom?** The prompt content, the documents attached to it, personal data, customer data. Name the recipient. If the answer is "whatever the editor pastes in", that is the answer and it has consequences worth writing down.
5. **Model output is untrusted input.** Say where it goes: rendered as HTML, stored, passed to a shell, used as a file path, fed back into a prompt. Each of those is a different risk and the specification is where the difference gets noticed.
6. **What does a run cost, and what is stored when the provider does not say?** Token counts and cost are reported by some providers and not others. A model priced at zero and a provider that reported nothing must not end up as the same recorded value — that distinction is the single most common defect in AI telemetry.
7. **What is the assertion, given the output varies?** Equality is almost never it. Name what must hold: a schema, a set of required fields, a range, a refusal, an invariant that survives rewording. A requirement that can only be checked by eye is not testable.
8. **What happens when the provider changes the model behind the same name?** Say whether the feature pins a version, tolerates drift, or has to be re-evaluated — and if it has to be re-evaluated, what would signal that.

**Do not name a provider adapter, a middleware or a table here.** Which provider serves the capability is the plan's decision; this document says what the capability has to be.
