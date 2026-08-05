.. _adr-129:

==================================================
ADR-129: In-repo consumers of structured output
==================================================

:Status: Accepted
:Date: 2026-08-05

Context
=======

ADR-126/128 built strict, provider-enforced structured completions — and a
recon showed that nothing in the repository used them. The wizard
(:php:`WizardGeneratorService`) bypassed :php:`CompletionService` entirely,
calling :php:`chatWithConfiguration()` with a hand-rolled three-stage JSON
parse cascade; :php:`TaskExecutionService` never told the provider about a
task's ``output_format`` at all (a JSON task's content was JSON only if the
model felt like it); the LLM judge grader prompt-begged for JSON and
``json_decode``-d bare.

Decision
========

Three consumers move onto the structured pipeline:

1. **WizardGeneratorService** calls
   :php:`completeStructuredForConfiguration()` with strict-subset schemas
   for its three shapes; the system prompt travels via
   :php:`ChatOptions::withSystemPrompt()` (the manager turns it into a real
   system message). The parse cascade is deleted. The ``normalize*()``
   coercion and the fallback-on-failure contract stay — with one deliberate
   exception: a :php:`BudgetExceededException` is **rethrown**, because a
   budget denial disguised as "the LLM produced nothing" is a
   mis-diagnosis, not a fallback.
2. **TaskExecutionService** requests JSON mode when the task's
   ``output_format`` is ``json`` — as ``['response_format' => 'json']``
   option overrides on the configured path, as :php:`ChatOptions` on the
   default path — and appends a JSON instruction to the prompt. The
   instruction is load-bearing twice: user-authored ``prompt_template``\ s
   cannot guarantee the word "json" that OpenAI-dialect JSON mode requires.
3. **LlmJudgeGrader** grades via :php:`completeStructured()` with a
   two-field verdict schema.

Two schema-design rules, both consequences of the adversarial review:

- **No numeric bounds in consumer schemas.** ``minimum``/``maximum`` are
  outside the OpenAI strict-mode profile (the schema would silently degrade
  to plain JSON mode), and a bound violation would spend a paid repair
  round-trip only to end in a fallback — strictly worse than the existing
  ``normalize*()``/clamp semantics, which remain the authority on ranges.
  A judge scoring "8.5" still clamps to 1.0 instead of failing the grading.
- **All properties required, ``additionalProperties: false`` on every
  object, empty strings valid.** That is exactly the OpenAI strict-mode
  qualification; on the other providers the same schema is enforced through
  their native mechanisms (ADR-128).

:php:`CompletionService::decodeAndValidate()` additionally strips one
wrapping Markdown fence before giving up: a model routed through a provider
that ignores JSON mode (OpenRouter third-party routing) occasionally fences
despite the instruction, and the strip saves the paid repair round-trip.
The strict validation still decides.

Consequences
============

- Wizard calls now run through :php:`completeForConfiguration()` and
  therefore gain two behaviours they previously lacked: the generation
  configuration's **skills are injected** into the prompt, and the
  **budget middleware** sees the call (with the rethrow above making a
  denial visible instead of silent).
- Wizard requests carry the schema JSON in the prompt (more input tokens)
  and can spend one repair round-trip where the old cascade did a single
  call; in exchange the response shape is enforced natively on all seven
  providers and validated strictly instead of being scraped out of prose.
- A JSON task on OpenAI-dialect providers, Gemini, Ollama now returns
  machine-parseable content by construction. The task result is still
  passed through as text — parsing stays the consumer's job.
- ``ConfigurationGenerator`` (setup wizard) deliberately stays on its raw
  HTTP path: it runs before any configuration exists, outside the
  DI-managed provider stack (own SSRF gate and vault audit), and is not a
  candidate for this pipeline.
