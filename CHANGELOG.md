# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **An editor action is declared metadata on a writing tool, not a second kind
  of thing** (ADR-152). The opt-in `EditorActionInterface` returns an
  `EditorAction` carrying a translatable label, a human description distinct
  from the model-facing one, an icon and the record types the action addresses;
  all five writing tools declare one. Nothing about execution changes — an
  editor action runs on the tool path, behind the same fence, approval pause and
  audit. Deliberately not built, each with its reason in the record:
  `bulkCapability`, a caller-facing preview service with a structured
  before/after diff, and a per-action grant.

- The Tools module renders a writing tool as its icon, translated name, human
  sentence and applicable record types instead of a bare wire name and the
  paragraph written for the language model. Tool groups have translatable names
  through the new `ToolGroup` enum; a third-party group keeps its raw
  identifier. `ToolInterface`'s docblock listed the group taxonomy without
  `editing` — the group every writer uses — which is fixed.

- A side-effecting tool that cannot be fenced is refused before it runs
  (`WriteWithoutDurableExecutionException`, ADR-141). The fencing hook is
  installed unconditionally now; a segment holding no persisted run or no lease
  used to get no hook at all, and its write proceeded silently. This is what
  makes the guarantee hold for entry points that do not exist yet: a new caller
  that forgets to claim its run cannot execute a write. Read-only tools are
  unaffected — repeating a read is always safe.

- `WritePathAcceptanceTest` asserts the fence on the segment that executes the
  write: stamped with the declared effect before the tool, cleared after, both
  under the lease the resume claimed. Its docblock carried a detailed account of
  why the fence could never arm; that account is now the history of what
  ADR-141 closed.

- `AgentRuntimeWiringTest` asks the container whether the write fence's effect
  resolver is actually injected. Without it every tool reads as READ_ONLY, so no
  write is fenced and none is refused — the axis fails open silently while every
  hand-wired test still passes.

- **An operator can declare that a snippet or a skill must not leave a trust
  zone** (ADR-144, closes #689). Tool OUTPUT has been data-classified since
  ADR-094; what a run puts IN was not, so a configuration in the least-trusted
  zone could receive any snippet and any skill. Both entities gain a
  `data_class` column on the same `ToolDataClass` scale, and a gate refuses a
  configuration-driven send whose injected context is classified above the trust
  zone it can reach — fallbacks included, because a configuration that can fail
  over to an external provider really can send there.
  - **Undeclared is not a class.** An empty value means no statement was made,
    and a source that made no statement places no constraint. An installation
    that has classified nothing behaves exactly as before, which is what makes
    the axis safe to ship enforcing: the migration risk was never in the switch,
    it is in guessing a value for data that already flows.
  - The gate reads the existing `tools.dataClassEnforcement` switch rather than
    adding a sibling — it is the same question (does a declared class bind
    against a provider's trust zone), asked in the other direction. Observe mode
    records the refusal and lets the call through, as it does for tools.
  - The refusal names the source and the zone, never the text. The governance
    audit gains a `context_blocked` decision, kept separate from `tool_denied`
    so "which direction leaks" stays answerable.
  - The system prompt and task input are deliberately not classified: neither
    has a per-record home for a declaration.

- **The Governance tab can compare against a named profile, and answer "would
  this be allowed"** (ADR-145). ADR-140 gave operators a read-only view of the
  effective governance and argued the apply path down. The two questions that
  follow — is this right, and would this specific call pass — are answerable
  without one.
  - Four profiles ship as pure definitions: `local-only`, `controlled-cloud`,
    `enterprise-strict`, `development`. A profile enforces nothing, resolves
    nothing and is never consulted at runtime. Selecting one compares its
    expected values against the rows already on the page and lists the
    differences, each with **where the value is set** — a deviation that only
    said "wrong" would be half an answer when there is deliberately no apply
    path.
  - A key a profile does not name is not compared. Silence is a position: a
    profile with an opinion on every key would force operators to disagree with
    it about things it never described.
  - The comparison takes the readout's rows as an argument rather than fetching
    them, so it compares exactly what the operator is looking at and cannot read
    the resolvers a second time and disagree with the table above it.
  - The simulator runs `ToolCallPolicy::decide()` — the call the runtime makes,
    not a copy of its rules — for a chosen configuration and tool, and renders
    the decision with the data class, the reachable trust zone and its ceiling.
    It answers for the operator running it, using their own permissions.
  - The profile values are judgement, not derivation. Nothing measured that 30
    days is right for a controlled cloud, and a deviation is a question worth
    asking rather than a defect.

- **Three more editorial writing tools** (ADR-146, closes #702). Two shipped;
  the write fence now arms on every executing segment (ADR-141), so a third,
  fourth and fifth inherit a fenced path rather than each re-establishing one.
  All three ship disabled, sit in the `editing` group, pause for a human
  decision, preview at suspend, write through the `DataHandler` as the acting
  user and verify their write by reading it back.
  - `move_content_element` moves one element to a page and a column. It creates
    nothing and destroys nothing — the record keeps its uid, content, language,
    history and references — which is why it is the only one of the three that
    is idempotent. **Both** pages are authorised: moving an element out of a
    page edits that page's content as much as moving one in. An anchor on the
    wrong page is refused rather than silently corrected.
  - `create_content_element_draft` creates one element, **always hidden**, with
    no argument to switch that off. The content type is an allow-list (`header`,
    `text`, `textmedia`, `bullets`) intersected with the live TCA, so `list`,
    `html` and `shortcut` are unreachable. If the acting user lacks the
    exclude-field grant for `hidden`, the `DataHandler` drops it silently and
    the element would be live — the read-back catches that and **deletes the
    element again**, because a half-made element nobody approved is worse than
    none.
  - `create_translation_draft` runs core's own `localize` command, so connected
    translations, inline children and localisation hooks behave as they do in
    the backend. It adds the part core does not: the result is hidden, since
    `localize` copies the source's visibility. An existing translation stops the
    call and is named; `overwrite` is the only way past it, deletes that
    translation recoverably, and gets its own line on the approval card.
  - The effects diverge for the first time: two of the three declare
    `NON_IDEMPOTENT_WRITE`. ADR-135 kept `getEffect()` per tool against exactly
    this possibility, so the prediction is now an observation — and the review
    that ADR scheduled for the third writer is recorded too. What the three new
    tools share because they were written together — the row lookup, the
    unknown-argument refusal and the viewer gate — is extracted into
    `PlansOneEditorialWriteTrait`. `WritesThroughDataHandlerTrait`, which carries
    what all five writers share, does not grow, and the two shipped writers are
    not touched.

### Changed

- The `main-branch-rules` ruleset requires `All security checks` instead of the
  nine individual contexts that gate job already covers. gitleaks, zizmor,
  dependency-review, scorecard and pr-quality run on every pull request and
  could not block one; they can now. This is what `checks.yml`'s own header
  comment always described, and what this repository was not doing.

- `fuzz-mutation / Fuzz Tests` replaces `fuzz / Fuzz Tests` as a required
  context. The required one came from `checks.yml`, which passes no inputs to
  the fuzz reusable and is therefore always `skipped` — a requirement that
  enforced nothing. The fuzzy suite that actually runs is `ci.yml`'s, verified
  green on three merge-queue runs before it was required.

- `BASELINE.md`, `SECURITY_AUDIT.md` and the CI diagram state the new
  enforcement: 16 required contexts, and SonarCloud as the one check that
  reports without blocking.

- The four documentation diagrams that stood as `.. TODO:` placeholders are
  drawn: architecture overview, tool-calling sequence, streaming data flow, CI
  pipeline. Committed as SVG rather than PNG so they are reviewable in a diff
  and need no build step. Each was traced against the code it depicts, so the
  streaming figure shows the sliding redaction window and the tool figure shows
  the gate running before the model is offered anything.

- The PlantUML source block on the architecture page is marked `text`. It was
  labelled `plantuml`, which no highlighter in the docs build knows, and every
  render emitted a warning for it.

- **Every executing segment holds a lease, so writes are fenced wherever they
  run** (ADR-141). 0.27.0 shipped two writing tools and stated the gap in the
  same breath: the ADR-112 write fence armed only under a worker lease, and no
  shipped entry point produced one. The gap was wider than "the interactive path
  is unfenced" — `AgentRuntime::enqueue()` had no caller outside `Tests/`, so
  the queue, worker, lease and fence were a complete mechanism nothing entered;
  and because a declared write suspends BEFORE it runs (ADR-134), the tool
  executes on the resume, where `claimForResume()` explicitly *cleared*
  `claimed_by` and `lease_expires`. The one segment that ran side effects was
  the one segment that dropped its claim. Now a synchronous run claims at birth,
  a resume claims the run it continues, and both pass that identity to the
  executor — so `pending_effect` is stamped before the tool and cleared after,
  under the same ownership guard a queue worker uses. Identities name their
  segment (`resume:web-01:4711`, `interactive:…`, `worker:…`), so a lease left
  behind says which entry point abandoned it. Routing interactive writes through
  `enqueue()` instead was rejected: it loses the result and the `$onStep`
  closure the streamed Playground run is built on, and — decisively — it does
  not reach the resume at all, which is where writes actually execute.

- A stale RUNNING run that stored no request payload is dead-lettered rather
  than reclaimed onto the queue. A leased synchronous run or resume is now
  visible to `nrllm:agent:reap` (which is the point — an abandoned one settles
  instead of staying RUNNING forever), but neither stores a `queued_request`,
  and a worker refuses a QUEUED row it cannot rehydrate. Reclaiming one would
  strand it QUEUED forever. The fence check keeps priority, so a run caught mid
  non-idempotent write is still refused for that stronger reason first.

- An interactive run renews its lease at every step boundary. Previously only
  queue workers did, and a test pinned that as an invariant; it now pins the
  opposite, because the heartbeat is what an ownership-guarded fence write needs
  to match against.

- **An automatic model selection can say why** (ADR-142). Criteria-mode routing
  always had exactly one production path, but it returned a model and nothing
  else: a model that never appeared in a call was indistinguishable from one
  that lost on cost. It now produces a `RoutingDecision` naming the selected
  model and, for every other active model, either a score or the hard
  constraint that refused it. Eligibility and ranking are separate by
  construction — a rejected candidate carries no score at all, so no signal can
  bring it back. The rejection reasons have a deliberate order: the operator's
  own criteria are evaluated first and the operation capability (ADR-138) last,
  so `OPERATION_CAPABILITY_MISSING` means "would have served, but not this
  operation" and the misconfiguration error it raises cannot name a model the
  criteria excluded anyway.

- The routing predicates exist once. `ModelSelectionService::modelMatchesCriteria()`
  and the decision point share one `EligibilityEvaluator` instead of keeping two
  copies that could drift, and the candidate comparator moved into
  `CandidateRanker` with it. Behaviour is unchanged, which the existing suite
  passing untouched is the evidence for.

- Evaluation quality (ADR-060) and recent provider health (ADR-063) can now
  rank candidates, under a new `routing.policyMode` setting. It defaults to
  `providerPriority`, which reproduces the previous ordering exactly; the three
  measured modes (`balanced`, `quality`, `economy`) are opt-in because such
  signals change which model serves a call and an upgrade must not switch that
  on. Two rules keep them safe: provider priority outranks every measurement — a
  priority is an instruction, a score is evidence — and a model nobody measured
  contributes nothing rather than a zero, so an absence never reads as a bad
  score. `ProviderHealthService::reorder()` is untouched: it orders the fallback
  chain, which is a different axis.

- ADR-060 left first-class quality wiring as a follow-up and shipped
  `QualityAwareModelSelector` as an opt-in hook meanwhile. That follow-up is
  this. The hook is unchanged and still has no core consumer — its hard
  `minQuality` filter has no equivalent in the ranking on purpose, because a
  minimum quality is a constraint and constraints stay out of ranking.

- `TrustZoneResolver` and `DataClassEnforcementResolver` moved from
  `Service\Tool` to `Service\Governance`. Neither is about tools — a trust zone
  is a property of a provider, the enforcement switch governs an axis — and they
  sat in the tool namespace only because the tool gate was their first consumer.
  Core needs both to gate the send path, which turned the misfiling from
  cosmetic into load-bearing. `ModuleSeamTest`'s exception for
  `EffectivePolicyReadout` existed for the same reason and is gone with them, so
  the seam rule now passes with a shorter exception list than before. Both
  classes are `@internal`.

- `Skill` gains `getDataClass()` / `getDataClassEnum()` on the `@api` surface.


### Fixed

- **A criteria-mode configuration was sized against the wrong context window**
  (ADR-143). `ContextWindowManager` read the window from
  `$configuration->getLlmModel()`, but a criteria-mode record carries no model
  relation — `ConfigurationCallPlanner` deliberately does not write the
  resolution back, because that would mark the entity dirty and Extbase would
  persist `model_uid`, silently converting a dynamic configuration into a fixed
  one. So every dynamically-selected call fell through to the unknown-model
  fallback and budgeted against a number unrelated to the model on the wire; the
  response reserve came off the same empty relation. The window and the reserve
  now come from the model that actually serves the send. This is a behaviour
  change on the paths that already bound a window: a transcript that previously
  slipped through against a 128k assumption is pruned against the 4k model
  answering it.

- **Chat, tool calling and streaming through `LlmServiceManager` are bounded
  like a conversation or an agent loop** (ADR-143, closes #688). A tool-calling
  send counts its tool schemas against the same budget, because they are on the
  wire with the transcript. Embeddings are deliberately not bounded: they carry
  neither skills nor snippets nor a transcript, and their limit is the
  provider's own input limit rather than a window to prune turns out of. Only
  `ConversationService` and `ToolLoopService` bound their sends, so which API a
  consumer happened to call decided whether a long transcript was pruned or
  handed to the provider whole. The bind sits inside the middleware-pipeline
  terminal, the first point that knows the resolved model; for a stream it runs
  inside the opener, before the adapter is asked for the first chunk, because
  once a stream is open there is nothing left to prune.

- A completion **reports** rather than prunes. A raw prompt is a single unit —
  there are no older turns to drop, and shortening a caller's prompt behind
  their back would change what they asked for. An overflowing completion is now
  named, with the model and the budget it exceeded, instead of surfacing later
  as an opaque provider error.

- A payload that overflows even at its floor is still sent, matching what
  `ConversationService` already did: the estimate errs high, so it may well
  succeed, and if it does not the provider's own error is what the caller would
  have received anyway.

- `LlmServiceManager` gained two optional constructor arguments (the context
  window manager and a logger). Both default to null and every existing
  construction keeps its exact previous behaviour — a null context window means
  "bounded by the provider", which is what these paths did.

## [0.28.0] - 2026-08-10
Four user-facing changes, three of them found by looking at the demo instance
rather than at the code: a backend module that told an administrator nothing
he could act on, a page that never said what its button would do, and a card
that drew a broken-image placeholder.

### Added

- The task execute form says what it is for before it asks for input. It
  states that the output is shown and stored nowhere — `TaskExecutionService`
  writes to no repository — and, more importantly, that the run is a real
  billed request counted against the budget, which nothing on the screen had
  said. It also names what the task expects and returns, taking the input
  wording from `requiresManualInput()` so it describes the field that is
  actually rendered.


- `Tests/Unit/AdrLifecycleTest.php` fails when a record uses an unknown status
  word, when a status names another record without the matching lifecycle
  field, when a cross-reference in a lifecycle field or a status line points at
  a record that does not exist, or when only one end of an ADR-to-ADR
  amend/supersede pair is written. Field values are read across RST
  continuation lines, so a wrapped field cannot hide a reference.
- `Tests/Unit/ProductFactsConsistencyTest.php` derives the tool count, group
  count and read/write split from `Classes/Service/Tool/Builtin/` and fails
  when the README, the administration guide or the landing-page data
  contradict it, or advertise a version other than `ext_emconf.php`'s.
- `Tests/Unit/BaselineConsistencyTest.php` fails when `BASELINE.md` names a
  TYPO3 matrix `ci.yml` does not run, or calls the MSI target a minimum while
  the mutation job is gated on the weekly schedule.
- `Tests/Unit/AgentDocsCountConventionTest.php` rejects a reintroduced file
  count in any `AGENTS.md` — both `<number> <file-noun>` and the
  `<noun> (<number>)` form the removed `Controller/Backend/` row used. It does
  not assert the counts: a number that changes when anyone adds a file would
  turn every new file into a red build.

### Fixed

- A misconfigured provider reports the setting at fault instead of "LLM
  provider error. See system log for details." REC #8b keeps raw provider
  response bodies out of the UI, and it still does; a
  `ProviderConfigurationException` is not one of those — every message of that
  type is authored here and names what to change ("API key identifier is
  required for provider OpenAI"). Applied at all five places that had the same
  gap. They do not sanitize alike: two reach the client through `ErrorResponse`
  and two do not, so the redaction now lives in one trait that every site goes
  through.
- The "AI Cost this month" dashboard card showed the missing-icon placeholder.
  `actions-currency` is not a registered identifier — the TYPO3 icon set has no
  money-themed icon at all, so the name could never have resolved. An
  unregistered identifier fails silently, so a test now asserts every `nrllm-*`
  widget icon against the `IconRegistry`.

### Changed

- Requires nr-vault `^0.15.0`. The previous cap held the whole dependency tree
  below it, not just this package.

- `ROADMAP.md` lists only unbuilt work, and every item in its two roadmap
  sections is an open issue. Its top "Next" entry claimed all 41 builtin tools
  were read-only and that the first writer had yet to arrive — two had shipped.
  Everything already released moved out to `CHANGELOG.md` and the ADRs.
- ADR-090 decides the package-split timing; the README repeats it where it
  explains the anticipated seams and must keep matching. `ROADMAP.md` said
  "only after 1.0" while ADR-090 and the README said "with or before 1.0", and
  cited ADR-090 for the opposite of what it decided.
- ADRs carry a lifecycle. `Documentation/Adr/Index.rst` documents the status
  vocabulary and the paired `:Amends:` / `:Amended:` and `:Supersedes:` /
  `:Superseded:` fields. ADR-122 stood at plain `Accepted` while asserting
  "all 44 builtin tools read"; it and ADR-084 now name what expired. The twelve
  early records that kept their status in a prose section were converted to the
  field form.
- `SECURITY_AUDIT.md` no longer presents the 2026-01-05 self-audit as current.
  That report is archived unchanged as
  `Audits/2026-01-05-internal-self-audit.md` with the list of statements that
  have expired — it attested `ApiKeyEncryptionService` and
  `sodium_crypto_secretbox`, neither of which exists since the move to
  nr-vault. The root file now states what is actually verified, and says
  outright that there is no current full-scope audit.
- `BASELINE.md` states what CI enforces instead of what it aims at: mutation
  testing is a weekly report-only target, not a "minimum MSI 70%" gate, and the
  TYPO3 matrix is `^13.4` / `^14.3`, not 13.4 / 14.0. Branch rules are read from
  rulesets — 23 required status contexts, one required approving review, thread
  resolution — because the legacy branch-protection endpoint reports `null` for
  all of them. The real gaps are admin bypass on both rulesets and the security
  checks that run without blocking.
- `SECURITY_AUDIT.md` marks per check whether it is one of the 23 required
  contexts. gitleaks, zizmor, dependency-review, scorecard and SonarCloud run
  but do not block. CodeQL analyses `actions` and `javascript-typescript`, not
  PHP; Opengrep is the PHP SAST. Tool egress is declared per tool *group*, and
  the four-layer tool gate is described as a narrowing cascade rather than
  fail-closed, because three of its four layers default open when unset.
- Landing-page copy matches the shipped tool set: 43 built-in tools in 9
  groups, 41 of them read-only. It advertised "41 read-only tools in 8
  toggleable groups" and version 0.22.0.
- The `AGENTS.md` files name what exists instead of counting it. Nine of the
  twelve hand-maintained file counts in them were wrong: 264 PHP source files
  against 638, 26 ADRs and 38 Architecture Decision Records against 139, 69 and
  86 RST files against 202, 9 API reference pages against 15, 7 workflow files
  against 12, 13 backend controllers against 21, 9 Response objects against 20,
  3 architecture test files against 5, 10 Playwright specs against 8. Three
  were right at the time — 4 request DTOs, 4 test-guide pages, 9 E2E backend
  files — and went with them, because the problem is the shape, not the
  arithmetic. Counts that do not move with a file stay and were verified: seven
  registered provider adapters, thirteen seeded demo tasks.
- The workflow tables in `AGENTS.md` and `.github/workflows/AGENTS.md` list the
  twelve workflows that exist. They named seven, two of which — `security.yml`
  and `ter-publish.yml` — are gone; the release note built on the latter now
  names `republish.yml`. The `Api/`, `Administration/` and `Developer/` rows in
  `Documentation/AGENTS.md` named 9, 5 and 4 pages against 15, 16 and 11, and
  ten rows of the `Classes/AGENTS.md` table were the same shape — `Domain/Enum/`
  named 5 of 32, `Exception/` 3 of 10, `Domain/Model/` 5 of 16. One of them said
  `ChatMessage` is "currently unused"; 32 classes use it.

## [0.27.0] - 2026-08-09

### Added

- A read-only **Governance** tab on the LLM Overview showing the effective
  value of the four governance keys that carry a decision —
  `privacy.level`, `privacy.retentionDays`, `tools.dataClassEnforcement`
  and `skills.minTrustLevel` — together with the FQCN of the resolver each
  value came from (ADR-140). Every value is read through the same resolver
  the runtime uses, so the view cannot drift from behaviour: a mistyped
  `tools.dataClassEnforcement` reads as `enforce` because that is what the
  gate applies. A resolver that cannot be asked yields "unknown", never a
  substituted default. There is deliberately no apply path and no
  provenance column — the Install Tool stays the place where instance-wide
  keys are set; ADR-140 records why. Two rows carry more than a value:
  `tools.dataClassEnforcement = observe` is annotated as applying to
  built-in tools only, because the gate enforces the trust-zone ceiling for
  every MCP tool regardless (ADR-115), and each
  `privacy.retention.<category>` override that deviates from
  `privacy.retentionDays` gets its own row — the overrides left at the
  shipped `0` resolve to the global window and stay out.

- **`set_file_alternative_text` — the second writing tool** (ADR-135). It sets
  the alternative text (`sys_file_metadata.alternative`) of exactly ONE managed
  file, identified by its `sys_file` uid, through the `DataHandler`, as the
  acting backend user — the accessibility gap editors most often leave behind.
  It joins the existing `editing` group on the same terms as the first writer:
  ships **disabled**, declares `IDEMPOTENT_WRITE`, so every call suspends for a
  human decision (ADR-134), live workspace only, no approval marker of its own,
  and a before/after on the approval card (ADR-136). Access is decided BEFORE
  the write by `FalStorageGate::isFileAccessible()` — the configured storage
  allow-list intersected with the user's file mounts, a barrier the `DataHandler`
  knows nothing about; core's own file-metadata permission check (a WRITABLE
  mount, plus `tables_modify`) then applies on top, so a read-only mount is
  refused there. Only the allow-list half of that gate asks about the explicit
  acting user; the file-mount half asks about the ambient backend user, because
  core attaches mounts to a request-shared storage object. That is pre-existing
  behaviour of `FalStorageGate`, shared with three read tools, and is tracked as
  issue #672. It **never creates** a metadata record: a file that carries none is
  refused, in the same neutral words as a file in a forbidden storage, a file
  outside the user's mounts and a uid no file carries — so a refusal cannot be
  used to probe `sys_file` for existence. It writes the **live**,
  **default-language** record only and takes no language argument: the metadata
  row is looked up by `file` rather than by uid, so the workspace, the language
  and the order are all pinned — otherwise a draft version of the same record
  could be written instead of the live one — exactly as core's
  `MetaDataRepository::findByFileUid()` pins them. The reasoning is in the
  ADR-135 amendment. An empty string is accepted and is the correct value for a
  decorative image. Success is verified by re-reading the record, as with the
  first writer, and `ToolEffectCoverageTest::DECLARED_WRITERS` now pins two
  names. With two writers in the tree, the errands they share — the
  backend-environment and live-workspace guards, the bounded `errorLog` summary,
  the TCA narrowing and the preview formatting — moved into a new
  `WritesThroughDataHandlerTrait`, following the `CollectsEnvironmentTrait`
  pattern. Deliberately NOT shared: the neutral refusal strings (each is paired
  with the READ tool of the same records), the authorisation, the read-back, the
  row lookup, and `isEnabledByDefault()` / `requiresAdmin()` / `getGroup()` /
  `getEffect()` — identical today, still declared per tool so a third writer
  decides rather than inherits.

- **A write preview, produced when the run suspends** (ADR-136). New opt-in
  `ToolPreviewInterface`: a tool that implements it describes, in plain lines,
  what the pending call would do. The lines are produced by `ToolLoopService` at
  the moment it suspends for approval — so the caller is the loop, the display
  is the approval card, and the reading identity is the RUN's actor context, not
  the reviewing administrator's request (which is what ADR-122 deferred the
  preview over). They are persisted as a new optional field on
  `SuspendedRunState` (`callPreviews`), inside the blob that ADR-114 already
  encrypts at rest, and are rendered in the approvals inbox and in BOTH
  playground approval responses (the JSON payload and the streamed
  `awaiting_approval` event) as `preview.lines` / `preview.failed`. A run
  suspended before this field existed still resumes: a missing or malformed
  value degrades to "no preview", never to an error. A preview that throws is
  caught, logged and rendered as a marked line stating that there is none — an
  approver must never mistake a broken preview for nothing to warn about. The
  exception text is withheld (only its class is shown), as everywhere else in
  the loop. `update_page_metadata` implements it with a field-by-field
  before/after: its arguments are the new values, and the card now also shows
  what they replace. The preview is a snapshot of the pause, NOT a precondition
  — a page edited by a human in between does not block the approval, because the
  tool writes absolute values; the ADR argues that case out in full. Reading a
  preview is authorised per record against the VIEWER, not only per tool:
  `agent_approve` (ADR-130) is a tool-level grant, so the card asks the tool
  whether the backend user it is being rendered for may see that record, and
  says the preview is withheld where the answer is no. It fails closed when the
  question cannot be asked — no viewer, or no registered tool under that name.

- **`update_page_metadata` — the first writing tool** (ADR-135). It sets a fixed
  allow-list of descriptive fields (`title`, `subtitle`, `nav_title`,
  `abstract`, `description`, `keywords`, plus the EXT:seo titles/descriptions
  when that extension is installed) on exactly ONE page, through the
  `DataHandler`, as the acting backend user. It ships **disabled** and sits in a
  NEW group `editing` — deliberately not `content`, so a configuration that
  already grants the read-only content group does not inherit write capability
  from an upgrade. Because it declares `IDEMPOTENT_WRITE`, every call suspends
  for a human decision (ADR-134); it carries no approval marker of its own. Any
  other page field is refused, and a call naming an unknown field is refused
  whole rather than applied in part. A page the acting user may not edit is
  refused with the same string as a page that does not exist. Writes outside
  workspace 0 are refused, and so is a process without the backend environment
  the `DataHandler` declares (`$GLOBALS['TCA']`, `$GLOBALS['LANG']`,
  `$GLOBALS['BE_USER']`) — the tool names the missing piece instead of
  populating globals it does not own. Success is verified by re-reading the
  record: the `DataHandler` silently drops a field the user lacks the
  "exclude field" grant for, and reporting that as a successful write would
  mislead the human who approved it. An empty value for a field the TCA marks
  `required` (`pages.title`) is dropped just as silently, so the argument gate
  refuses it up front rather than letting the read-back blame a field grant an
  admin cannot be missing; clearing an *optional* field still works.
  `ToolEffectCoverageTest::DECLARED_WRITERS`,
  empty since ADR-122, now pins this one name. **Not guaranteed:** the ADR-112
  write fence arms only under a lease owner, which only `AgentRuntime::enqueue()`
  produces — no shipped entry point calls it, so an interactive write runs
  unfenced. The fail-closed write audit and the approval pause hold everywhere.

- A configuration can attach prompt snippets by tag
  (`tx_nrllm_configuration.snippet_tags`, amendment to ADR-031). The
  active snippets carrying any selected tag are composed into the
  configuration's effective system prompt, so they reach chat,
  completion, streaming and the agent loop through one insertion point
  in `ConfigurationCallPlanner::callOptions()`. Before this the snippet
  library reached no production prompt at all — its only consumers were
  the tool playground's forced snippets and the codec that rehydrates
  them. The select lists the tags the snippet records actually carry, so
  the vocabulary stays consumer-owned; a snippet carrying two selected
  tags is composed once, and an unknown tag yields nothing rather than
  an error. Configurations without tags are unaffected. The `@api`
  surface gains `LlmConfiguration::getSnippetTags()`,
  `setSnippetTags()` and `getSnippetTagList()` (additive).
  A hidden snippet is not composed: the repository keeps ignoring enable
  fields (the backend module lists hidden records), so the filter sits in
  `ConfigurationSnippetResolver`, and `PromptSnippet::isHidden()` is
  mapped for it. In the tool playground a forced snippet the
  configuration already selects by tag is no longer added a second time.
  The composed prompt is passed to `ContextWindowManagerInterface::fit()`
  by `ToolLoopService` and `ConversationService` (new optional
  `$effectiveSystemPrompt` argument, defaulting to the previous
  behaviour), so the ADR-107 budget counts the snippet block that goes on
  the wire.

- New extension configuration key `skills.maxBytes` (default `24000`) for the
  byte budget of the composed skill block (ADR-036 §5). `SkillComposer` had
  accepted `maxBytes` as a constructor argument since the feature landed, but
  `SkillComposerFactory` — the only production construction path — never
  passed it, so the ceiling was fixed at the hardcoded default with no way for
  an operator to change it. The cap itself was never absent: the constructor
  default applied, so an unconfigured instance was capped at 24 000 bytes then
  and is capped at 24 000 bytes now. What changes is that it can be adjusted.
  A missing, unreadable, non-numeric or non-positive value falls back to
  24 000, so an emptied or fat-fingered field cannot remove the cap — `0`
  means "use the default", not "uncapped". This is the opposite fallback
  direction from `skills.minTrustLevel`, which fails towards the *lower* bar
  so a bad value cannot silently hide skills; a bad budget must not silently
  unbound the block. Lower `skills.maxBytes` to reserve more of the model's
  context window for the conversation itself.

- `InputPauseCoverageTest` pins that no builtin can suspend a run for
  operator input (#649). The input path authorises the submitter with
  `agent_approve` alone and never against the tool whose input they supply,
  while the resume executes under the run owner's context (ADR-083) — the
  confused deputy the approval path closed in #622. It is unreachable only
  because nothing implements `RequiresInputInterface`, so the first tool
  that does now turns a latent gap into a red build. The gate itself is
  deliberately not built ahead of that tool: #622's cannot be copied,
  because an input-requiring tool declares no effect to gate on (ADR-105
  makes the two markers mutually exclusive) and the input path has no turn
  digest (ADR-132).

- Telemetry records the configuration that actually SERVED a run, not only
  the one that was requested: `tx_nrllm_telemetry` gains
  `served_configuration_identifier`, `served_provider` and `served_model`.
  Until now a fallback showed only as `fallback_attempts > 0`, so the row
  credited a configuration that did not answer the call. Both paths write
  the new columns — the pipeline through a new `TelemetrySignals` signal
  (`recordServedBy()`, recorded in the API snapshot), the streaming
  lifecycle from the configuration `openWithFallback()` returns. A run
  nobody served (exhausted chain) keeps naming the requested configuration
  on both sides, and so does a run that needed no fallback, so "which
  configuration answered this call" is one column rather than a fallback
  chain of two. Rows written before this release carry an empty
  `served_configuration_identifier` and are not counted as a swap.
  The analytics module reads them back as a **Fallback rescues** list: the
  runs another configuration stepped in for, capped at the 200 newest. The
  cap counts rescues, not fallback attempts — the query narrows to the
  swaps, so an outage writing exhausted-chain rows in bulk cannot crowd the
  period's rescues out of the list. `ProviderHealthRepository` is
  unchanged — its scores deliberately count only `fallback_attempts = 0`
  rows, so a rescued run still counts against the primary.

- A **Provider health and circuits** table in the analytics module, the
  readout ADR-063 deferred: per provider the health score with the sample
  count and the rolling window it was taken over, plus the circuit state
  from the `nrllm_circuit` cache. A provider with no telemetry in the
  window shows "no data", never a zero — it was idle, not broken. Both
  gates are stated on the page, because a score that changes nothing is
  the likeliest misreading of such a panel: `health.reorderFallback` is
  off by default, and a disabled `circuitBreaker.enabled` means the
  circuit column is not being evaluated at all. No new backend module
  (ADR-119) — it is a section of the existing analytics view.
  `ProviderHealthServiceInterface` gains `windowSeconds()` and
  `reorderEnabled()` so the window and the switch are read from the
  advisor instead of restated by its consumers; neither the interface nor
  the new `Service\Analytics\ProviderHealthReport` is part of the `@api`
  surface, which is unchanged.
  The same "no data is not a zero" rule applies to the latency column: a
  provider present in the window whose every run a fallback rescued has no
  self-served run to measure, and the cell says so instead of showing
  `0 ms`. `ProviderHealthScore::$avgLatencyMs` is nullable for that case
  (the score is unchanged — an unknown latency carries no penalty, and the
  failure is already in the success rate). Circuits opened for direct calls
  with a pinned provider are NOT in the table: such a run records no
  provider and its circuit is keyed on the call identifier
  (`ad-hoc:chat:openai`); the limitation is stated on `providerKeys()` and
  in the administration docs instead of being claimed away.

- MCP servers carry an operator-declared `requires_approval` flag
  (ADR-134). When it is set, every tool imported from that server pauses
  the agent run for a human before it is called; the decision never comes
  from the server, whose `readOnlyHint` annotation stays unread. It is on
  by default for a newly configured server, and only a literal `0` reads
  as "no approval" — an unreadable or missing value means approval is
  required. The default reaches existing servers too: the schema update
  writes it on every pre-existing row, and no upgrade wizard switches it
  off again. Switch it off per server once you know what that server's
  tools do.

### Changed

- `ToolCallPolicy::enforcing()` moved verbatim into the new
  `Service\Tool\DataClassEnforcementResolver`, which the tool gate and the
  governance readout both ask. `ToolCallPolicy` no longer takes an optional
  `ExtensionConfiguration` and instead requires the resolver; behaviour is
  unchanged, including the fail-closed matrix of ADR-113.

- `SkillComposerFactory::minTrustLevel()` is public so the readout can show
  the level the composer is actually built with.

- The candidate walk over a primary configuration's fallback chain is
  implemented once, in the `@internal`
  `Provider\Fallback\FallbackCandidateResolver` (ADR-137). It owns the
  ADR-021 rules — shallow, no self-retry, missing and inactive entries
  skipped — while ordering, the primary attempt and the skip log lines stay
  with each caller: the health-aware reorder (ADR-063) remains on the
  pipelined path only, and streaming keeps the configured order.
  `FallbackMiddleware` and `StreamingDispatcher` now take the resolver in
  place of `LlmConfigurationRepository`; neither is part of the `@api`
  surface, which is unchanged. Streaming resolves the chain lazily now: when an
  early candidate serves, later entries are no longer looked up and a broken
  entry behind it no longer logs a skip warning.

- Internal signature change: `ModelSelectionServiceInterface::resolveModel()`
  and `ConfigurationCallPlanner::resolveModel()` take a required
  `?ProviderOperation`; `ConfigurationCallPlanner::adapterFor()` likewise.
  Neither class is part of the frozen `@api` surface. Callers with no
  operation pass `null` explicitly.

- A builtin tool that declares a write effect (`ToolEffectInterface`,
  ADR-111) now requires human approval in the agent loop even without the
  `RequiresApprovalInterface` marker (ADR-134). Both write cases count;
  `READ_ONLY` tools are unaffected, so nothing changes for the tools
  shipped today — every builtin reads. Remote (MCP) tools are exempt:
  `McpTool` declares `NON_IDEMPOTENT_WRITE` for every imported tool as a
  fail-closed assumption about a body that cannot be inspected, so
  coupling it to approval would suspend every remote call. The remote axis
  has an operator-declared server-level source instead, below.

- `ToolRegistry` now also rejects a non-remote tool that declares a write
  **and** implements `RequiresInputInterface`: the approval scan runs before
  the input scan, so such a tool would suspend for approval, be refused by
  the approval resume for its missing input, and suspend again — never
  executing. The existing `RequiresApprovalInterface` + `RequiresInputInterface`
  ban (ADR-105) now covers the implicit form as well.

### Fixed

- ADR-036 §5 claimed a ceiling derived from the model's context window ("with
  `Model::contextLength == 0` the absolute cap applies"). No such derivation
  was ever implemented. The section now says what the code does — the budget
  is instance-wide and window-independent — and records why deriving it per
  configuration is deliberately not done: `SkillComposer` is one shared
  service whose budget is fixed at construction, the configuration's
  `llmModel` is null in criteria selection mode (so the window read would not
  belong to the model that serves the call), and `ContextWindowManager`
  (ADR-107) already bounds the real send with a calibrated token estimator.

- The `allowed-tools` documentation claimed the tool union and the injected
  prompt block are the same set (`Tools.rst`, ADR-038 §5,
  `AllowedToolsResolver`'s docblock: "exactly what SkillComposer injects").
  They can differ: `effectiveSkills()` does not know the byte budget, which is
  applied afterwards in `composeBlock()`, so a budget-dropped skill still
  grants its tools while its usage rules never reach the model. Now that
  `skills.maxBytes` is operator-settable the divergence is reachable by
  configuration, so `Tools.rst`, `Skills.rst`, ADR-038 §5 and the resolver
  docblock now all state it. The resolver is deliberately left budget-blind:
  counting the budget there would let the drop of the last declaring skill
  collapse the allow-list to `null` ("no restriction" — every registered
  tool), making a tighter budget widen the gate.

- Criteria-mode configurations no longer resolve a model that cannot serve
  the running operation (ADR-138). The operation is merged into the criteria
  before selection, so a tool call picks a tool-capable model instead of
  failing later as a provider error. Only `chat`, `vision` and `tools` are
  enforced — no model discoverer writes `completion` or `embeddings`, and
  only four of seven write `streaming`, so requiring those would refuse
  working models. A model whose capability field is empty counts as
  undeclared and always passes. New extension setting
  `routing.operationCapabilityEnforcement` (default `enforce`, fail-closed
  like ADR-113) switches the axis to `observe`, which logs the mismatch and
  leaves selection untouched. Fixed-mode configurations are unaffected.

- `Model::$capabilities` was dropped on every load from the database.
  Extbase resolved the property as an array (Symfony PropertyInfo infers a
  collection from the `addCapability()` / `removeCapability()` pair) and its
  DataMapper has no array mapping, so every repository-loaded model came
  back with an empty capability set — which also made every `capabilities`
  selection criterion match nothing. Anything reading `getCapabilities()`
  off a repository-loaded model now sees the persisted value.

- The embedding cache key and the embedding call can no longer resolve
  different models for the same call. `embedForConfiguration()` resolves
  twice — once outside the pipeline for the key, once inside the terminal —
  and both now pass the same operation.

- Model discovery seeds capabilities the provider actually reports (#671).
  Six of the seven discoverers wrote tokens no response substantiated:
  Groq and OpenRouter a flat `chat`, Mistral `chat, tools` for every model
  including vision-only ones, Gemini `chat, vision` for any id outside its
  built-in table, and Ollama `tools` for any tag containing `qwen`,
  `llama3`, `mistral` or `mixtral` — a guess from the model NAME that was
  wrong in both directions. Mistral's per-model `capabilities` object,
  OpenRouter's `supported_parameters` and `architecture.input_modalities`,
  Ollama's `/api/show` `capabilities` array and Gemini's
  `supportedGenerationMethods` are read instead. Groq's listing carries no
  capability field, so its models stay `chat` and the administration
  chapter says so.
  This matters beyond the wizard: `Model::$capabilities` began reaching the
  entity in 0.26, so configurations selecting a model by criteria match
  against these values for the first time.
  Also dropped: the `reasoning` token, written by two discoverers, absent
  from `ModelCapability` — `CapabilitySet` discarded it and the TCA
  checkbox list could not show it.
  Existing `tx_nrllm_model` rows keep their values; re-run **Fetch Models**
  to refresh them.

- `OpenRouterProvider::fetchModels()` reported no capabilities for any
  model. It read `supports_function_calling`, which is not a field the
  catalogue returns, and compared `architecture.modality` against
  `"multimodal"`, which is not a value it takes — the field is a display
  string such as `text+image->text`. Measured against the live catalogue:
  400 models, 0 with either signal, against 333 that list `tools` among
  their supported parameters and 237 that accept image input.

- The tool-group table in the administration chapter renders its
  `configuration` row correctly (#673). The RST simple table's first column
  was two characters narrower than the longest key, so `render-guides`
  logged `Malformed table` and the row's closing backticks were swallowed:
  the group name printed as `` ``configuration `` in plain text instead of
  as inline code. The row itself was present — the render error is real,
  the missing row the issue suspected was not.

- `FalStorageGate::isFileAccessible()` enforces the mounts of the user it is
  passed, not of whoever is in `$GLOBALS['BE_USER']` (#672). The storage
  allow-list half already used the passed user; the mount half did not, and
  the two disagreed whenever the acting and ambient user differ — which is
  exactly the approval path, where a run resumes under its owner's identity
  (ADR-083) while the approver is ambient. A broader-mounted approver
  authorised a file the owner could not reach; a narrower one refused a
  legitimate read.
  Two request-shared caches caused it, and neither is reachable through a
  per-user API: `StorageRepository::findByUid()` returns the cached
  `ResourceStorage` whose mounts the core `StoragePermissionsAspect`
  attached once for the ambient user, and
  `BackendUserAuthentication::getFileMountRecords()` memoises into the
  runtime cache under one key with no user in it — so the first user to ask
  in a request answers for every later one. The gate now builds its own
  storage from the record via `createFromRecord()` (which dispatches no
  initialization event and never enters the instance cache) and reads the
  mount rows for that user's own mount uids.
  Behaviour is unchanged for the three read-only FAL tools shipped today:
  they run synchronously in their owner's request, where both users are the
  same person.

- The two FAL tools that read `sys_file_metadata` pin the live workspace
  (#674). The table carries `'versioningWS' => true`, so a file with a draft
  version has more than one row for the same `file` uid:
  - `read_fal_asset_meta` returned an arbitrary one — potentially an
    unpublished draft — as the current value, with nothing in the answer
    saying so. It now adds `WorkspaceRestriction(0)`, `ORDER BY uid ASC` and
    `setMaxResults(1)`, the same three things core's own
    `MetaDataRepository::findByFileUid()` pins.
  - `search_fal_files` joined the table without a workspace condition, so a
    search for text that exists only in a draft returned the file, and a file
    with a draft version was listed twice. The join now requires
    `t3ver_wsid = 0`.

  Both defects are read-only: the answer could be wrong, nothing was
  corrupted. The same defect in the writing tool is fixed separately in the
  `set_file_alternative_text` branch, where it mattered more.

- The Install Tool label for `tools.dataClassEnforcement` no longer promises
  a pin that usually does not happen (#675). It stated unconditionally that
  an upgraded install "is pinned to Observe by an upgrade wizard". The pin
  only happens if the wizard is run before the extension configuration is
  written: `updateNecessary()` requires a stored value of `null`, and
  `synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions()` —
  which runs on entering the Install Tool, on `extension:setup`, and from
  `ExtensionConfiguration::get()` for an absent key — writes the shipped
  `enforce` into exactly the place the wizard reads. After that it reports
  nothing to do, and the operator is on enforce while the label tells them
  otherwise, in the screen where they would look. The label now states the
  condition and says that the value shown is what applies.

- Three PHP test classes that no job executed now run in CI (#658).
  `Tests/E2E/TCA/` was in neither suite of `Build/FunctionalTests.xml`, and
  the two workflow tests directly under `Tests/E2E/` were in no suite at
  all — `Build/phpunit.xml` declares an `e2e` suite over that directory, but
  no `runTests.sh` selector invokes it (`-s e2e` runs Playwright). Verified
  rather than assumed: all three answered `No tests executed` before, and
  run now (15 + 11 tests). A test no job runs is worse than no test, because
  it reads as coverage — which is how a failing assertion sat in
  `TcaFieldCompletionTest` unnoticed.
  The parallel functional runner globs its directories separately, so
  `Tests/E2E/TCA` is added there too; without it the new suite would be
  silently skipped in that mode, exactly as the comment above that line
  warns.

- `TcaFieldCompletionTest` passes again. It flagged
  `tx_nrllm_configuration.preset_checksum` as label-less, which it is: the
  column is `type => passthrough`, written by the preset importer and never
  rendered by FormEngine, so a label would be a declaration nothing reads.
  Passthrough columns are now skipped by their TCA type rather than by name,
  so the rule holds for the next one instead of growing an exemption list.

- The skill block reaches a multimodal user turn (#645).
  `SkillInjectionService` recognised an array-shaped message as a user
  message only when its `content` was a **string**, so a conversation whose
  first user turn carries content as a list of parts had the block prepended
  to some later text turn — or, with no later text turn, not at all. Silently.
  Multimodal content is a supported input shape, not an accident:
  `MessageShaper::normalise()` converts only the exact 2-key string/string
  form into a `ChatMessage` and passes everything else through for the
  adapters to convert. The block is now prepended as a leading
  `{type: 'text'}` part, the shape every adapter reads
  (`ClaudeProvider::convertMultimodalContent()` translates it into Claude's
  own format). Existing parts keep their order and are never merged into,
  because a part may be an image.
  An associative content array is still not an injection site — prepending
  to it would produce a shape no adapter reads — so such a message is left
  for the next candidate.

- A composed skill block that finds no user message is logged instead of
  vanishing. The message list is still returned unchanged, since the block is
  never escalated into the system role, but skills carry instructions and
  constraints and "silently absent" is the failure mode that matters. A call
  with no skills stays silent — the warning marks a lost block, not every
  skill-less call.

- The conversation context budget counts the skill block (#625).
  `ConversationService` fitted the transcript and then dispatched into
  `LlmServiceManager::chatForConfiguration()`, which prepends up to 24 000
  bytes of skill block to the first user message — the fitted list and the
  sent list were different lists. A criteria-mode configuration has no model
  relation, so its window falls back to 8192 tokens, of which a full block is
  roughly 7 900 estimated tokens against a budget of 6 946 (8192 minus the
  1000-token response reserve minus the 3 % safety margin): the whole
  configuration class overran. `ContextWindowManagerInterface::fit()` takes an
  optional trailing `$injectedText` for payload that reaches the wire outside
  the message list, and the conversation path composes the block once and
  passes it. The injection stays where it is — the first user turn is the
  never-droppable head, so injecting before the fit would drop the entire
  history and still overflow. Known limit: a session opened without a
  configuration resolves the installation default inside the manager, so its
  block is still unaccounted for.

- **The approval of a write is fail-closed and bound to the turn that was
  reviewed** (ADR-132). Two defects on the same path:
  `AgentRunPersister::recordApproval()` swallowed a store error and
  `ResumeCoordinator::approve()` executed anyway, so a write could run with no
  record of who authorised it; and the stale-review digest existed only in the
  `AgentRunController`, so the Tool Playground could approve a turn it had never
  displayed. `recordApproval()` now returns `bool` (same shape as
  `recordStep()`), and `approve()` refuses to execute a turn declaring a write
  whose decision could not be recorded. The verified digest moved into
  `ApprovalDecision` and is compared — timing-safe — against the state loaded
  AFTER the resume claim, so a decision made on a turn a concurrent approval
  already replaced is refused as well. Read-only turns stay fail-soft
  (deliberately), and a denial still passes: it executes nothing, so refusing it
  would only leave the write pending. A refused decision RELEASES the run back
  to `WAITING_FOR_APPROVAL` — nothing runs, nothing settles, the operator can
  re-review and decide again. The playground's `awaiting_approval` payload and
  streaming event now carry `turnDigest`, and `resumeAction()` reads it back.
  **Breaking:** `ApprovalDecision`'s constructor takes a third argument,
  `?string $turnDigest`. It is optional in the signature for source
  compatibility but MANDATORY at runtime — a `null` is refused exactly like a
  wrong digest, so any third-party caller that constructs the decision itself
  must supply the digest of the turn it displayed or every approval will fail
  with `StaleApprovalTurnException`. `WaitingRunViewFactory::pendingTurnDigest()`
  and `WaitingRunViewFactory::turnDigestForRun()` are gone; the computation lives
  in the new `PendingTurnDigest` service, and the rendered card is the only
  carrier of the value. (Neither was tracked public API.)
  A run whose `suspended_state` is already unreadable is still refused BEFORE
  the resume claim, so it stays `WAITING_FOR_APPROVAL` with its state intact
  instead of being claimed and settled `FAILED` — the guarded terminal settle
  clears `suspended_state`, which would destroy the only copy a repair could
  work from. The decode after the claim remains and now covers only the race it
  is there for: the state CHANGED between the two reads and the row the claim
  won is the unreadable one.

- **An approver may only release a write they could run themselves** (ADR-133).
  A resumed turn executes under the run OWNER's identity (ADR-083, unchanged),
  but the approver was never checked against the tool they were releasing:
  `mayActOnRun()` grants the decision on the `agent_approve` grant alone, so a
  non-admin holding it could release an admin-only write that then ran with the
  owner's privileges — a confused deputy. `ResumeCoordinator::approve()` now
  resolves the APPROVER's live backend user and asks `ToolCallPolicy::decide()`
  about every pending call that declares a write; a denial refuses the release
  with `ApproverNotPermittedException` and RELEASES the run back to
  `WAITING_FOR_APPROVAL`, so somebody who does hold the permission can still
  decide it. A **service account may not release a write-declaring turn at
  all**: it has no backend-user uid and `hasGrant()` is false for it, so
  `decide()` would check only the `requiresAdmin` axis — refusing is the only
  fail-closed variant. Read-only turns and denials are unaffected (they execute
  nothing that needs the approver's authority), and no builtin declares a write
  today (ADR-122), so the shipped catalogue behaves as before. The module shows
  a new `runs.error.approverNotPermitted` flash; the playground answers 403 and
  re-signals `awaiting_approval`.

## [0.26.0] - 2026-08-06

### Added

- An editor-facing **AI Tasks** backend module in the Web area (ADR-131).
  Editors with the module permission AND the `Execute AI tasks` grant run
  prepared tasks (minus table-input tasks, whose record picker stays
  admin-only) through a slim surface without any management affordances;
  the approvals inbox is shared with the admin module and scopes
  visibility by actor — admins and `Approve suspended AI runs` holders
  see every run, everyone else only their own. Both ADR-130 grants are
  observable for the first time. The runs infobox now states the actual
  visibility rule instead of claiming to be admin-only.
- Capability grants for backend users (ADR-130). Two grants, assigned per
  backend group through TYPO3's own permission mechanism (`be_groups`
  access lists): `Execute AI tasks` opens the two task-execution AJAX
  endpoints to non-admins (bounded by the per-user budget), `Approve
  suspended AI runs` lets a granted user decide other users' suspended
  runs. Nothing changes for anyone until a grant is ticked; admins hold
  every grant implicitly. Grants live on `AiActorContext` — the door
  ADR-117 left open — and `backendUser()` gained an optional `$grants`
  parameter (additive; recorded in the API snapshot). The task module's
  management surface and a non-admin editing module are the committed
  follow-up milestone.
- Task execution enforces the executing user's budget (audit REC #4,
  closing the hook documented in `TaskExecutionService` since slice 13c).
  The controller passes the backend user's uid; the `BudgetMiddleware`
  pre-flights the per-user cap before the provider is paid and the
  recorded usage is attributed to that user. A denial surfaces as its own
  localized message instead of the generic failure text. Note: the task
  module is admin-only, so this can now block an administrator whose
  personal budget is exhausted — configuration limits applied before and
  still do.
- The `@api` surface (ADR-127) is frozen in `Tests/Unit/Api/api-surface.txt`:
  a snapshot test renders every marked class's declared public signatures and
  fails on an unintended change; the same pass asserts the closure rule —
  every type an `@api` signature mentions is `@api` itself.
- The wizard, JSON tasks and the LLM judge now use the structured-output
  pipeline (ADR-129). The wizard generates through strict-subset schemas
  (provider-enforced, locally validated, one repair round-trip) instead of
  scraping JSON out of prose; its generation configuration's skills and the
  budget middleware now apply, and a budget denial surfaces instead of
  degrading to the fallback answer. A task with `output_format: json` gets
  real JSON mode on every provider plus an explicit JSON instruction. The
  judge grader receives a schema-validated verdict. Schemas carry no
  numeric bounds — the existing clamps stay authoritative, so out-of-range
  numbers degrade gracefully instead of spending a repair round-trip.
- Every class states its API stability: `@api` for the callable surface
  (semver-covered, including every type its signatures mention), `@api`
  extension points for the interfaces third parties implement (no new
  abstract member within a major version), `@internal` for everything else.
  `Documentation/Api/Stability.rst` states the promise in consumer terms;
  ADR-127 records the rules. No runtime behaviour changes.
- Structured completions are enforced natively where the provider can
  (ADR-128). The pre-flighted schema rides along as
  `ChatOptions::withResponseSchema()` and each adapter emits its dialect:
  OpenAI/Groq/Mistral/OpenRouter send `json_schema` strict mode when the
  schema qualifies for it (JSON mode otherwise), Gemini sets
  `responseSchema` + `responseMimeType`, Ollama the `format` field, Claude
  a single forced tool whose input is the answer. Plain JSON mode
  (`completeJson()`) now reaches all seven providers instead of OpenAI
  alone. The local strict validation stays authoritative; the prompt
  instruction and repair round-trip are unchanged.
- Tools from an external MCP server can be used in an agent run. An
  administrator configures a server, imports the catalogue it advertises and
  enables individual tools; import is the only network call outside a run and
  happens on an explicit action, never because a page rendered. Each server
  declares what class of data its tools may see, and a server without that
  declaration supplies nothing rather than falling back to a default nobody
  chose. A remote tool always requires an administrator, counts as a write that
  is not replayed on a retry, and is never waved through the trust-zone gate in
  observe mode. The number of remote calls one run may make is bounded, because
  a remote call crosses the network while a backend user waits and nothing else
  limits how many a model asks for at once.

- `TranslationOptions::withTranslator()` forces a specific translator by
  identifier (e.g. `'deepl'`, `'llm'`) on `translateWithTranslator()` /
  `translateBatchWithTranslator()`, bypassing configuration-based resolution.
  Callers that already know which translator they want (e.g. having picked
  one via `TranslationService::findBestTranslator()`) previously had no way
  to act on that choice without pinning an entire `LlmConfiguration`. The new
  `$translator` constructor parameter is appended after the budget fields
  (`$beUserUid`/`$plannedCost`) rather than inserted before them, so a
  positional caller relying on those being the last two arguments is
  unaffected.

- `LlmTranslator::IDENTIFIER` — the translator registry identifier (`'llm'`)
  as a public constant, so a caller distinguishing "a specialized translator
  was selected" from "the universal fallback was selected" (e.g. after
  `TranslationService::findBestTranslator()`) doesn't have to hardcode the
  string.

- Translation and image generation can be tried from the backend. The test page
  gained two cards: translate a snippet with a chosen translator, or generate a
  single image with the OpenAI or the FAL service. Until now nothing in the
  backend reached these services — the Playground drives the agent runtime, and
  every "Test" button issues a plain chat completion — so an operator who
  configured a DeepL or FAL identifier had no way to find out whether it works
  short of writing consumer code. A missing credential is reported as such and
  names the Extension Configuration, rather than surfacing as a generic
  failure. Nothing is stored: the translation is text, the image is rendered
  from what the provider returned and is gone on reload. Taking an image into
  the file storage remains the consuming extension's job. See ADR-118.
- `ImageGeneratorInterface`, the one call `DallEImageService` and
  `FalImageService` have in common. Their `generate()` signatures differ beyond
  the prompt and stay that way; the interface serves callers that want the
  service's own defaults, and makes the two mockable.

- A long conversation now shortens instead of failing. `ConversationService`
  replays the whole history on every turn, so it was the one path that grew
  without bound until the provider refused it. It is now bounded against the
  model's context window, the same mechanism the agent loop already used: the
  oldest exchanges drop out of what is sent, while the system prompt, the
  opening exchange and the newest turn always survive.

  The stored history is never shortened — it is the audit record. What the model
  actually saw is recorded separately, as a count of dropped turns on the user
  row of each turn (`tx_nrllm_ai_session_message.dropped_turns`). NULL there
  means no fit was evaluated, 0 means it ran and kept everything. Without that
  a reader could not tell why an answer ignored an earlier turn. Run
  `extension:setup` or the Database Analyzer for the new column; no data
  migration is involved.

  When even the shortest permissible transcript does not fit, the request is
  sent anyway and a warning is logged. The estimate errs high on purpose, so
  this often succeeds; when it does not, the provider's own error is what the
  caller would have got before. See ADR-121.

### Changed

- **`netresearch/nr-vault` is now required at `^0.14.0`** (was `^0.13.0`). 0.14
  extends `VaultServiceInterface` with `retrieveForFrontend()`,
  `assertDeletable()` and `setEnabled()`, adds `$includeDisabled` to `list()`
  and removes `clearCache()`. No production class here implements that
  interface, so only the in-memory test double changed. Operators upgrading a
  site: 0.14 replaces nr-vault's admin-only model with grantable operation
  permissions — backend users who reach an API key through nr-llm need
  `tx_nrvault:secret.use`, and `secret.create` to store one. See nr-vault's
  0.14.0 migration notes.
- **BREAKING for external `completeStructured()` callers:** the schema is now
  validated against a named strict subset (ADR-126). Every subset keyword —
  enum, pattern, bounds, `oneOf` and friends — is enforced on the response,
  and a schema carrying unknown keywords (notably `$ref`) is rejected up
  front with its own error code, before any provider call. Annotations
  (`description`, `format`, `$schema`, …) stay accepted. The tool-input gate
  and the evaluation grader are unchanged. No in-repo caller is affected.

- OpenRouter's model routing lives in its own class
  (`Provider/OpenRouter/ModelRouter`), the first per-adapter collaborator
  (ADR-125). Same strategies, same keyword heuristics, same fallbacks; a call
  that names its model explicitly still triggers no catalogue fetch. Purely

- The byte caps on tool output live in their own class
  (`Service/Tool/ToolResultBounder`): UTF-8 coercion plus the independent
  content and artifact bounds, moved verbatim out of the agent loop. The loop
  still applies them at its single invoke seam; the bounder is a defaulted,
  non-nullable collaborator, so no wiring can leave the caps absent. Purely
  internal.

- Model discovery is split into one discoverer class per provider
  (`Classes/Service/SetupWizard/Discovery/`); `ModelDiscovery` remains as the
  facade with an unchanged interface and unchanged behaviour, including each
  provider's fallback policy and result order. Purely internal — nothing to
  reconfigure.

- The service manager's per-configuration call planning (model resolution,
  adapter choice, effective options) and its pipeline-metadata builders live in
  two collaborators (`ConfigurationCallPlanner`, `CallMetadataFactory`) —
  ADR-059's stage-2 seams, moved verbatim. The public API, the middleware
  metadata keys and their union semantics are unchanged. Purely internal.

- The agent loop's tool gate is no longer something a wiring can omit. The
  composite tool-call policy is a required constructor argument on
  `ToolLoopService`, the per-configuration allow-list resolver is gone, and the
  input schema validator is defaulted rather than optional. An absent policy
  used to fall through to a narrower legacy chain with no trust-zone axis — a
  weaker gate that nothing made visible. That chain is deleted, and with it the
  resolver, which only ever ran inside it and whose check the policy performs
  itself. The availability service also leaves the constructor: nothing in the
  loop read it once the chain was gone.

  No `Null` gate implementation was written — an allow-all one would have been
  weaker than the `null` it replaced, which is the failure mode the change
  exists to remove. Tests construct the real policy instead. Doing so revealed
  that fixtures passing a provider-less configuration were relying on a gate
  that was never wired: such a configuration fails closed to the external trust
  zone, so a real run withholds tools above the editor-content class. See
  ADR-120.

- The model backend controller no longer carries the provider round trips.
  Probing a model and discovering what a provider offers moved into
  `ModelTestController` and `ModelDiscoveryController`; `ModelController` keeps
  the record list and the state toggles. The three AJAX routes keep their
  identifiers and their paths, so nothing in the backend UI or in a consuming
  extension's route lookup changes — only the class behind the route does. A
  consumer calling one of the three action methods directly on
  `ModelController` has to call it on the new controller instead. Same split by
  request pathway as ADR-027.
- The agent runtime no longer carries the queued-request serialisation. Turning
  an `AgentRunRequest` into the JSON stored on a queued run row, and reading it
  back, moved into `AgentRunRequestCodec`. The two directions have to agree
  field for field — including the budget and idempotency values that
  `ToolOptions::toArray()` drops and the codec therefore carries out of band —
  and that agreement is now stated in one class and tested directly rather than
  inferred from a run. `AgentRuntime` loses its `SkillRepository` and
  `PromptSnippetRepository` constructor arguments, which only the serialisation
  used; the codec takes them instead. Consumers use `AgentRuntimeInterface` and
  are unaffected. First step of the runtime decomposition on the roadmap.
- What becomes of a failed queued run moved out of the agent runtime into
  `QueuedRunFailureRecovery`. Four independent conditions can each force a
  dead-letter instead of a retry — a non-retryable error class, a
  non-idempotent write fenced in flight, an exhausted requeue budget, and a
  lost claim on the row — and the order between them is the whole of the
  behaviour, which is easier to review in a class named after the decision than
  inside the run loop. The retry budget and the backoff stay `AgentRuntime`
  constants; only the decision reading them moved. Second step of the runtime
  decomposition.
- Driving a run from its first round to a settled outcome moved into
  `AgentRunExecutor`. This is the lifecycle ladder: the catch order ADR-084
  makes a hard guarantee, ADR-103's cancellation probe, ADR-104's lease
  heartbeat, ADR-111's write fence and fail-closed audit. It no longer knows
  where a run came from — a request, a claimed queue row and a resumed
  suspension all reach it as a handle, a trace and a closure producing the next
  tool-loop result, so the ordering guarantees hold identically on all three
  paths and can be exercised without a queue row, a resume claim or a provider.
  The two resume paths, which previously repeated trace-then-context-then-ladder
  each in their own method, now share one entry point that fixes that order in
  one place. Third step of the runtime decomposition.
- Putting a run on the queue and picking it back up in a worker moved into
  `QueuedRunCoordinator`. The two halves are one protocol and only make sense
  together: what `enqueue()` stores is what `runQueued()` has to be able to
  claim, position and rehydrate, and both are fail-closed in the same direction
  — a queued run that cannot be stored, dispatched, positioned or rehydrated is
  settled rather than left as an orphan a worker would later find in an
  impossible state. Fourth step of the runtime decomposition.
- Picking a suspended run back up moved into `ResumeCoordinator`. An approval
  and a submitted input follow the same claim protocol, and the order in it is
  the safety property: probe, win the atomic claim, then re-resolve the
  event-stream position from a fresh row because the claim moved it. The one
  deliberate divergence — a submitted input is validated against the tool's
  declared schema before anything is claimed, so a rejection leaves the run
  resumable — is now visible as a divergence inside one class instead of as a
  difference between two long methods. Fifth step of the runtime decomposition.

  `AgentRuntime` is now 266 lines, down from 1218. What remains is the
  interface contract, the published constants, the retry predicate and
  delegation; every algorithm lives in a class named after it.

### Removed

- **Breaking:** the backend capability permissions are gone —
  `CapabilityPermissionService`, `CapabilityPermissionServiceInterface`, the DI
  alias, the `customPermOptions` registration and the developer guide that
  described them. A consumer injecting either class must drop the dependency;
  nothing inside the extension called them.

  Every backend group record showed eleven checkboxes named after the model
  capabilities. They looked like an access control and changed nothing: ADR-023
  shipped the registration and the check primitive deliberately without gating
  any call, and the follow-up never came. Enforcing them was rejected rather
  than deferred again — streaming bypasses the pipeline so one capability would
  be unenforceable by construction, the check returns "allowed" when no backend
  user is present so a queued run would pass where the synchronous one failed,
  and every nr_llm module is admin-only, so the setting would only ever affect
  third-party consumers. See ADR-117.

  Ticked values stay in `be_groups.custom_options` as inert strings and are
  never read again. Access control is unchanged: the gate that works is the
  per-configuration `allowed_groups` relation.

### Fixed

- `DeepLTranslator` sent `preserve_formatting` as the string `"1"`/`"0"` in a
  JSON request body. DeepL's `/v2/translate` accepts that string form only on
  the classic form-encoded API (`PreserveFormattingOptionStr`); over JSON it
  expects a genuine boolean (`PreserveFormattingOption`) and rejects the
  string with `{"message":"Value for 'preserve_formatting' not supported."}`.
  Since `TranslationOptions` defaults `preserveFormatting` to `true`, this
  affected the specialized-translator path on effectively every call that
  reached DeepL with default options, not just callers who opted into
  formatting preservation explicitly. Both payload builders now pass the
  native `bool` through unchanged; verified against the live API.
  `split_sentences` is intentionally left as the string cast — DeepL's schema
  has no boolean variant for it in either request-body form
  (`SplitSentencesOption` is `'0'|'1'|'nonewlines'` for both).

- `LlmTranslator::getPriority()` returned `100`, higher than
  `DeepLTranslator`'s `90`. Per the "higher priority sorts first" contract
  on `TranslatorInterface::getPriority()`, that put the universal LLM
  fallback ahead of every specialized translator in the tagged iterator —
  and since `LlmTranslator::supportsLanguagePair()` always returns `true`,
  `TranslatorRegistry::findBestTranslator()` always returned it first,
  regardless of whether a specialized translator (DeepL) was actually
  available. Lowered to `-1000` so the fallback sorts last, as its name
  implies.

- `DeepLTranslator::supportsLanguagePair()` rejected `'auto'` as a source
  language — it isn't in `SUPPORTED_SOURCE_LANGUAGES` and
  `normalizeLanguageCode()` doesn't map it to a real code, so the check
  always failed and DeepL could never be selected by
  `TranslationService::findBestTranslator($sourceLanguage, ...)` for a
  caller doing source-language auto-detection. `'auto'` (case-insensitive)
  and `''` are now treated as "any source is fine" — DeepL already
  auto-detects whenever `source_lang` is omitted from the actual translate
  request, so this only affects the selection check, not the request
  itself. `TranslatorInterface::supportsLanguagePair()`'s docblock now
  documents this as part of the contract for other implementations.
- A requeued agent run no longer inherits the write fence of its previous
  attempt. The requeue cleared the worker claim and the lease but left
  `pending_effect` standing, so a later failure could be judged against a write
  that was no longer running — and a standing non-idempotent write dead-letters
  a run whatever its retry budget says. Narrow but reachable: a step naming a
  tool the registry no longer knows resolves fail-closed to non-idempotent, so
  a tool removed or renamed between attempts stamps the fence for real. See
  ADR-122.

- A configuration preset that cannot be imported now says what to do about it,
  and no longer renders a disabled button that looks exactly like the working
  one. Both branches used `btn btn-primary btn-sm` with the same "Import" label
  and the same download icon; the only difference was a `disabled` attribute
  and a tooltip, so an inert control was indistinguishable from a broken one —
  clicking it did nothing, by design, with no visible explanation.

  "Not satisfiable — missing: capabilities: chat, vision" also stated the
  diagnosis and left the operator to guess the cure. The preflight now
  distinguishes four cases from the records already present and names the
  action for each: a matching model exists but is switched off (activate it,
  with the model named); no model declares the capabilities (add one to a
  configured provider, or tick the capability on a model that has it, with the
  providers named); no provider is configured at all; or the capabilities are
  covered and a secondary requirement rules every model out. The first three
  link to the module that fixes them.
- A configuration in criteria mode can be tested again. The Test button
  resolved the model only through the direct relation, which criteria-mode
  records do not have (`model_uid = 0`), so it answered "Configuration has no
  model assigned" for a configuration that resolves and runs at call time —
  including every configuration created by a preset import. It now resolves
  through `ModelSelectionService`, the same path the runtime uses, which
  returns the directly configured model unchanged for fixed-mode records.
- The Test button on a model record no longer sends a chat prompt to a model
  that cannot answer one. A DALL-E or text-to-speech record got a chat
  completion, the provider rejected it, and the admin was shown "provider
  rejected the test request" — which says nothing about the model. The probe
  is now chosen from the record's declared capabilities: a chat-shaped model
  is prompted as before, an embedding model is embedded and reports the vector
  dimensions, and a model whose capabilities are served by the specialized
  services is not called at all. Those authenticate with the Extension
  Configuration rather than the provider record, so testing them from here
  would verify a different credential than the record declares; the message
  says so and points at the LLM test page. A record with no declared
  capabilities keeps the chat probe — the field is optional and routinely left
  empty.

## [0.25.1] - 2026-07-27

A translation fix reported from the field, plus Extension Configuration
settings that were unreadable or unsettable.

### Added

- Architecture tests for the horizontal module seams ADR-090 names. The
  specialized and tool/agent modules may no longer depend on each other in
  either direction, the guardrail module may depend on neither, and nothing
  outside the backend package may depend on controllers or dashboard widgets.
  Calls in the invoking direction stay allowed, since that is how the guardrail
  pipeline runs. ADR-090 kept these boundaries as a review responsibility and
  called extending phpat to cover them part of the split-readiness work;
  wrong-way coupling now fails CI. All four rules pass against the current tree
  without a baseline.

### Fixed

- Translation no longer requires a global default configuration.
  `translateForConfiguration()` auto-detects the source language when none is
  given, but the detection sub-call went through the default-resolving `chat()`
  entry point instead of the configuration the caller had passed. An instance
  with no configuration marked as default therefore failed with `No default LLM
  configuration found` even though a configuration was supplied explicitly. The
  configuration is now threaded through detection. Reported in #520, fixed in
  #524.
- `privacy.retention.governance` is now declared in `ext_conf_template.txt` and
  documented. The retention window added for governance events in 0.25.0 was
  read by the purge commands but had no Extension Configuration field, so the
  category silently fell back to the global `privacy.retentionDays` and the
  operator could neither see nor set it. A drift guard now asserts that every
  `PrivacyDataCategory` has both a template field and a documentation entry.
- Seven Extension Configuration settings showed a description truncated
  mid-sentence. TYPO3 splits an `ext_conf_template.txt` comment on `;` before
  splitting the label from its description, so a semicolon inside a label cuts
  everything after it — the DeepL identifier, minimum skill trust level,
  health-aware fallback, tool data-class enforcement, content privacy level and
  default retention window all lost part of their explanation in the Install
  Tool. A test now fails on a semicolon in any label.

## [0.25.0] - 2026-07-24

Backend dashboard widgets for the Agent Runtime, plus a queryable governance
audit trail.

### Added

- Agentic and telemetry dashboard widgets on existing data (no schema change):
  Agent runs by status (doughnut), Runs awaiting approval (count), Agent run
  outcomes / termination reasons (bar), and request success-rate + average
  latency (from `tx_nrllm_telemetry`). New read aggregates on
  `AgentRunRepository` (`countByStatus` / `countByTerminationReason` /
  `countInStatus`) and `TelemetryRepository` (`successRatePercent` /
  `averageLatencyMs`).
- Governance audit table `tx_nrllm_governance_event` (append-only) and the
  widgets it powers: governance blocks over time, tool denials by reason, and
  tool usage by name. Guardrail verdicts, tool-gate denials and provider
  content-filter blocks are now recorded (via `GuardrailMiddleware` and
  `ToolLoopService`) instead of only being reflected on an agent run — making
  blocked/flagged decisions and per-tool usage queryable.
- Retention coverage for the new table: `PrivacyDataCategory::GOVERNANCE`,
  `nrllm:governance:purge`, and `PurgePrivacyDataCommand` wiring.

## [0.24.0] - 2026-07-23

A focused hardening release for the Agent Runtime: identity and authorization,
queue delivery idempotency, fail-closed tool enforcement, and encryption of
queued/suspended state at rest.

### Security

- Agent-run identity and authorization (ADR-083/091/110): every stateful agent
  entry point now carries an explicit `AiActorContext` instead of reading the
  ambient `$GLOBALS['BE_USER']`, and authorizes it fail-closed — a run UUID or
  session UUID alone never suffices. Tools authorize against an explicit
  `ToolExecutionContext` resolved to the acting backend user, so a run authorizes
  identically synchronously and in a queue worker. Service accounts are limited
  to declared scopes and fail closed with none.
- Queue delivery idempotency (ADR-111/112): tools declare a `ToolEffect`
  (read-only / idempotent-write / non-idempotent-write). A writing tool's audit
  step is fail-closed, and a run reaped or failed mid non-idempotent-write is
  dead-lettered rather than retried — so an at-least-once retry never repeats a
  write that already ran once.
- Tool data-class enforcement is fail-closed and on by default (ADR-113/115): the
  trust-zone tool gate observes only on an explicit `observe`; a missing,
  malformed or unreadable setting now enforces. New installs enforce by default;
  existing installs are pinned to `observe` by an upgrade wizard so the change
  does not silently strip tools from a working setup.
- Agent run state encrypted at rest (ADR-114): the queued request and suspended
  transcript in `tx_nrllm_agentrun` are encrypted via nr-vault's managed-key
  envelope encryption (rotatable master key, per-column AAD). Rows written before
  this landed still rehydrate (no data migration).

### Changed

- **BREAKING** — Actor-context runtime surface (ADR-083): `AgentRuntimeInterface`
  `approve()`, `submitInput()`, `cancel()`, `status()` and `events()` take a
  required `AiActorContext` first parameter; `ToolInterface::execute()` takes a
  required `ToolExecutionContext`; `AgentRunPersister::recordStep()` returns
  `bool` (was `void`). In-tree callers are updated; out-of-tree consumers of
  these public surfaces must adapt.
- **BREAKING** — Per-configuration guardrail policies (ADR-106): `GuardrailInterface`
  and `InputGuardrailInterface` (the public `nr_llm.guardrail` /
  `nr_llm.input_guardrail` extension points) gained two methods via a shared
  `GuardrailIdentity` parent — `getIdentifier()` (a stable slug) and
  `isMandatory()`. An out-of-tree guardrail must implement both; input and
  output classes sharing a concept must return the same identifier and the same
  `isMandatory()` value (the container fails closed on disagreement). See the
  ADR-106 *Upgrading* note.

### Added

- Agent-loop context-window management (ADR-107): an optional
  `ContextWindowManager` keeps the tool loop's growing transcript within the
  model's context window by dropping the oldest whole tool-call turns —
  preserving the tool-call/tool-result pairing, the leading system/task
  messages and the newest turn — before each provider send. The token estimate
  errs high (a content-class-aware char heuristic that counts dense JSON/tool
  payloads more heavily, calibrated up against real usage), so it never
  under-prunes into an overflow. When even the pruned floor exceeds the window
  no request is sent: the run ends on the new non-retryable
  `AgentRunTerminationReason::CONTEXT_TRUNCATED` instead of a misclassified
  provider 4xx. Absent the collaborator (lean wiring) the loop is unchanged.
  Streaming is out of scope.
- Per-configuration guardrail policies (ADR-106): an `LlmConfiguration` can now
  select which OPTIONAL guardrails apply via a new `allowed_guardrails` field;
  MANDATORY guardrails (secret redaction) always run and are never selectable.
  The selection is honoured consistently on the output (`GuardrailMiddleware`),
  input (`InputGuardrailScreener`) and streaming (`StreamingDispatcher` — live
  redaction and end-of-stream audit) paths. Default-secure: an untouched
  configuration (empty selection) runs all guardrails exactly as before, and no
  selection value — empty, partial, unknown, or all-unknown — can drop a
  mandatory guardrail on any axis. The `provider-content-filter` guardrail is
  optional (it enforces the provider's own policy block, not secret leakage);
  secret redaction is mandatory. The TCA picker lists only optional guardrails,
  discovered from the registry. Schema: `allowed_guardrails` (additive,
  defaulted). New `GuardrailIdentity`, `GuardrailRegistry` (public, fail-closed
  on cross-side mandatory disagreement) and `GuardrailPolicyResolver`.

- Typed user-input suspension (ADR-105): a tool may implement the new
  `RequiresInputInterface` marker to declare an input schema and suspend the
  agent run WAITING_FOR_INPUT until the user supplies typed data — the input
  sibling of the ADR-084 approval flow. `AgentRuntimeInterface::submitInput()`
  validates the submission against the tool's schema BEFORE claiming the run
  (an invalid submission is rejected without consuming the claim, so it stays
  resubmittable), records an `INPUT` audit event, overlays the validated values
  onto the target tool's arguments (bounded to the schema-declared keys so
  neither the model nor the user can smuggle a value into the other's field),
  and resumes the loop. The playground exposes it at
  `POST /nrllm/tool/submit-input` (admin-gated; invalid input → 422 while
  re-signalling `awaiting_input`). Fail-closed throughout: a degenerate schema
  is treated as corruption (never "accept anything"), a tool may not be both
  approval- and input-gated (rejected at registration), and an unstorable
  suspension fails as `SUSPEND_FAILED`. `AgentRunOutcome` gained
  `AWAITING_INPUT`, `AgentEventKind` gained `INPUT`,
  `AgentRunRepositoryInterface` gained `suspendRunForInput()` and
  `claimForResumeFromInput()`, and `ToolLoopServiceInterface` gained
  `resumeWithInput()`. No schema change — the input schema rides inside the
  existing `suspended_state`.
- Worker heartbeat, stale-run reaper, retry and dead-letter (ADR-104): a queue
  worker now renews its lease at every step boundary; if the renewal fails
  (the run was reclaimed) it stops without settling — the run belongs to its
  new owner (`AgentRunOutcome::LEASE_LOST`). The new schedulable
  `nrllm:agent:reap` command finds RUNNING runs whose lease has expired (a
  dead worker) and either requeues them for another worker or, once the requeue
  budget is spent, dead-letters them; both mutations re-check staleness inside
  the UPDATE so a merely slow worker is left alone. A queued run that fails is
  classified through the `FailureClassifier` (extended so an exhausted fallback
  chain classifies by its most recent attempt): a non-retryable class
  dead-letters immediately (`AgentRunTerminationReason::NOT_RETRYABLE`), a
  retryable one is requeued with an exponential backoff `DelayStamp`
  (`AgentRunOutcome::REQUEUED`) until the budget is spent
  (`RETRIES_EXHAUSTED`). Dead-lettering stays on the existing FAILED status +
  reason axis — no new status, purge/retention untouched. Real backoff needs
  the doctrine transport (which honours the delay); the sync transport retries
  in-process, bounded by `AgentRuntime::MAX_REQUEUES` (3). Schema:
  `requeue_count`. `AgentRunRepositoryInterface` gained `renewLease()`,
  `requeue()`, `findStaleRunning()`, `requeueStale()` and `deadLetterStale()`.
- Cooperative cancellation (ADR-103): `nrllm:agent:cancel` (and any
  `AgentRuntimeInterface::cancel()` caller) now also stops an in-flight loop at
  its next step boundary — after the current provider response or tool
  execution, before the next one — instead of letting it run to completion
  with a discarded outcome. The boundary step stays on the audit stream; the
  run surfaces as the new `AgentRunOutcome::CANCELLED` (playground:
  `status: 'cancelled'` / `cancelled` stream event). The tool loop itself
  stays persistence-unaware; the probe lives in the runtime's trace hook.
- Queued agent runs (ADR-102): `AgentRuntimeInterface::enqueue()` persists a
  QUEUED run carrying its serialised request and dispatches a wake-up message
  on the TYPO3 message bus; `runQueued()` — behind the new
  `AgentRunQueuedMessage` handler — atomically claims the row (exactly one
  worker wins; a run cancelled while queued is unclaimable), rehydrates the
  request and drives the same fail-closed lifecycle as `run()`. On the default
  synchronous transport the run executes in-process; routing the message to the
  core doctrine transport plus `messenger:consume` makes it genuinely
  asynchronous — batch runs, review queues and scheduled agent work poll via
  `status()`/`events()`. Fail-closed on every seam: no orphaned QUEUED rows
  (a failed dispatch settles the row), no stranded RUNNING rows (rehydration
  failures settle the claimed run). Schema: `queued_request` (privacy-stripped
  from `status()` like the suspended state), `claimed_by`, `lease_expires`
  (worker lease, groundwork for the stale-run reaper).
  `AgentRunRepositoryInterface` gained `enqueueRun()` and `claimQueued()`.
- `AgentRuntimeInterface` (ADR-101): the agent-run lifecycle — begin, execute,
  persist, suspend for approval, approve/deny, cancel, event polling, status —
  as one public, fail-closed application service. The tool playground is now a
  UI adapter over it; scheduler tasks, queue workers and downstream extensions
  get the identical tested lifecycle instead of re-assembling loop + persister.
  Operator decisions are persisted as a new `approval` event
  (`AgentEventKind::APPROVAL`), a resume continues the event stream at
  `MAX(sequence)+1` (refused fail-closed when the position is unavailable), a
  failed re-suspension now fails the run on every path instead of promising an
  impossible resume, and `status()` never exposes the raw suspended transcript.
  Cancellation is now a real fence: a cancelled run can no longer be
  resurrected into the approval queue by an in-flight suspension
  (`suspendRun` became a guarded transition).
  **Breaking:** `ToolPlaygroundController`'s constructor takes the runtime
  instead of `ToolLoopService`/`AgentRunPersister`;
  `AgentRunPersister::resumeHandle()` returns `?AgentRunHandle`;
  `AgentRunRepositoryInterface` gained `maxEventSequence()` and
  `suspendRun()` returns `bool`.
- Specialized usage (images, characters, audio seconds) is now recorded by the
  pipeline through tagged `UsageMetricsExtractor`s instead of each service
  writing to the usage table itself (ADR-100). A service attaches a
  `SpecializedUsageIntent` before dispatch; the matching extractor (one per
  service, matched on operation and provider) reads it with the raw response and
  `UsageMiddleware` writes the row — one recorder for every AI call. The recorded
  rows are unchanged. DeepL's language-detection sub-call and the metadata calls
  set no intent and so record nothing (the double-count guard is now structural).
- The specialized HTTP egress fails closed (ADR-099): a provider call that
  dispatches without wrapping in `runLifecycle()` now throws a `LogicException`
  instead of silently spending against the provider with no telemetry, circuit
  breaker or usage. DeepL's `detectLanguage()` (a billable translate call) now
  routes through the pipeline too, and its `getUsage()` / `getGlossaries()`
  metadata lookups run under the new `ProviderOperation::Metadata` — observable
  and circuit-breaker guarded, labelled honestly rather than as a translation.
- The specialized services now screen their prompt through the input guardrails
  before it leaves the process (ADR-098): DALL·E (`generate` /
  `generateMultiple` / `edit`), FAL (`generate` / `generateMultiple`), TTS
  (`synthesize`) and DeepL (`translate` / `translateBatch`) run the same
  screening a chat prompt gets — a REDACT verdict rewrites the text that is sent,
  a DENY / REQUIRE_APPROVAL throws before any spend. `InputGuardrailScreener`
  gained `screenText(string)` for the single-string send path. Whisper is out of
  scope (its payload is audio, not a prompt). `AbstractSpecializedService` gained
  a required `InputGuardrailScreener` constructor parameter (**breaking** for a
  subclass or manual construction). With no input guardrails configured this is a
  pass-through — no behaviour change.
- `FailureClass` and `FailureClassifier` (ADR-095): one shared taxonomy behind
  the retry, circuit-breaker and streaming-retry decisions, which had each kept
  a private `instanceof` ladder that drifted. A 5xx is now classified as a
  provider-side fault.
- `SpecializedServiceException::getStatusCode()` exposes the upstream HTTP
  status the error carries (0 for a transport failure), so the specialized
  failures can be classified once they reach the shared pipeline.

- Tool egress is governed by a data classification instead of denylists alone:
  every tool has a `ToolDataClass` (from `publicContent` up to
  `secretAdjacent`), every provider declares a `TrustZone` (`local`,
  `privateHosted`, `externalEu`, `externalGlobal`) implying a ceiling, and the
  new public `ToolCallPolicyInterface` decides — registered, enabled, permitted,
  within the configuration's groups, within the ceiling — returning a typed
  reason instead of a silent absence (ADR-094).
  **Ships in observe mode**: `tools.dataClassEnforcement = observe` logs what
  enforcement would do without removing anything. Run the
  "Declare a trust zone for existing LLM providers" upgrade wizard and perform a
  database compare after upgrading; an un-stamped provider resolves to the
  strictest zone.
- `AgentRunTerminationReason` records **why** an agent run ended — completed,
  iteration cap, exhausted budget, policy denial, denied approval, provider
  failure or cancellation — in a new `termination_reason` column. A budget stop
  and an iteration cap were previously indistinguishable: both are completed
  and truncated (ADR-092).
- `nrllm:agent:cancel <uuid>` retires a run that is stuck queued, running or
  awaiting a decision. `CANCELLED` is now a state runs actually reach.
- `AiActorContext` — an explicit caller identity (backend user, service account
  or anonymous) for the stateful entry points, so a queue worker can act for the
  user who queued the work instead of inheriting the ambient backend user
  (ADR-091).
- `LlmServiceManagerInterface::chatForConfiguration()`, the message-list
  counterpart of `completeForConfiguration()`.
- `ConfigurationResolver::getActiveByIdentifierForActor()` evaluates activity
  and BE-group restrictions against a passed actor instead of
  `$GLOBALS['BE_USER']`.
- `AiSessionRepositoryInterface::appendMessageAtNextSequence()` allocates a
  message sequence race-free.
- Per-category data retention: `privacy.retention.conversation`,
  `.agentRun`, `.approval`, `.telemetry`, `.evaluation` and `.skillAudit`
  override the global `privacy.retentionDays` window, so conversation
  transcripts can expire long before telemetry does (ADR-064).
- `nrllm:privacy:purge` now covers **every** content-bearing table:
  conversation sessions (`tx_nrllm_ai_session`,
  `tx_nrllm_ai_session_message`) and agent runs (`tx_nrllm_agentrun`,
  `tx_nrllm_agentrun_event`) join evaluation results, the skill audit and
  telemetry. It reports the window and the row count per category.
- `AgentRunRepositoryInterface::purgeUnfinishedOlderThan()` reaps runs that
  never reached a terminal status on the separate, longer `approval` window.
- Administration guide "Data retention & purge"
  (`Documentation/Administration/DataRetention.rst`).

### Changed

- All five specialized services — DALL·E, FAL, Whisper, TTS, DeepL — dispatch
  through the shared middleware pipeline (ADR-097): each call now writes a
  telemetry row with a correlation id and is guarded by the provider circuit
  breaker, like a chat call. `AbstractSpecializedService` gained a required
  `MiddlewarePipeline` constructor parameter (**breaking** for a subclass or
  manual construction). Budget enforcement and usage recording are unchanged.

- The middleware pipeline's configuration moved from a separate parameter onto
  the call context: `MiddlewarePipeline::run(context, terminal)` and
  `ProviderMiddlewareInterface::handle(context, next)` no longer take a separate
  `LlmConfiguration`; it travels on `ProviderCallContext`, which gained a
  nullable `configuration` plus `provider`/`model`/`configurationIdentifier`
  strings and `for()`/`forConfiguration()`/`forService()` factories. This lets a
  caller without an `LlmConfiguration` entity (a specialized image/speech/
  translation service) drive the same pipeline. No behaviour change on the chat
  path. `ProviderOperation` gained the specialized cases. **Breaking** for a
  downstream custom middleware or a direct `run()` caller (ADR-096).

- A provider 5xx now triggers fallback to the next configuration and counts
  towards opening the provider's circuit breaker (ADR-095). Previously only a
  connection error and a 429 did, so a provider returning 500 repeatedly neither
  failed over nor tripped — the two "this provider is unhealthy" mechanisms both
  ignored the signal.
- **Breaking:** the specialized image/speech/translation services throw
  `ServiceQuotaExceededException` on HTTP 429 instead of the generic
  `ServiceUnavailableException`, so a rate limit is distinguishable from an
  outage. Both extend `SpecializedServiceException`; a catch on the base class is
  unaffected (ADR-095).

- **Breaking (tool contract):** the per-configuration tool gate — the skills'
  declared allow-list intersected with `allowed_tool_groups` — is applied inside
  `ToolLoopService` instead of in the tool playground. Every consumer of the
  published `ToolLoopServiceInterface` is now subject to it; previously only the
  playground was, so a downstream caller received the full globally-enabled set
  (ADR-093). The playground's own behaviour is unchanged.
- `get_tca` routes its table access through the shared `TableReadAccessService`
  like its sibling `get_full_tca`, and therefore no longer describes the
  extension's own or `nr_vault`'s tables — to anyone, administrators included.
  It previously checked `tables_select` directly, which every admin passes.
- The `rag` tool group declares a `configured_endpoint` egress scope and
  `SolrSearchBackend` validates its assembled URL against the configured host
  (http(s) only, no credentials in the URL, exact host:port). The policy
  previously claimed the group could not egress at all while the backend was
  issuing HTTP requests — an audit gate, not a new confidentiality boundary,
  since the host was always operator-supplied (ADR-093).
- A guardrail stop is recorded as a policy outcome (`policy_denied`, or
  `approval_denied` when an approval was required and never obtained) instead
  of as a provider failure, so a denial can no longer be mistaken for an outage
  in the run table (ADR-092).
- An agent run can no longer be settled twice: `finishRun()` transitions only
  non-terminal runs and reports whether it did. A late settle — for instance
  the streamed path's `finally` block after a client disconnect — previously
  overwrote a finished run's totals and error class.
- The playground now fails a run whose approval state could not be stored
  instead of answering "awaiting approval". An approval-gated tool is
  side-effecting; promising a resume that cannot happen is worse than an error.
- **Breaking:** `ConversationServiceInterface::startSession()` and `send()` take
  a leading `AiActorContext`. A session uuid is no longer sufficient to continue
  a conversation: the actor must own the session, be an administrator, or be a
  service account (ADR-091). Previously any caller holding a uuid could read and
  continue another backend user's conversation.
- A conversation turn now runs against the configuration the session was opened
  with, resolved fresh on every turn. Previously the stored identifier was never
  read and every turn silently used the installation default — a different
  model, budget and guardrail set than the session started with. A deactivated
  or newly restricted configuration now stops the session instead of falling
  back.
- Conversation turns are attributed to the acting backend user, so per-user
  budgets apply to conversations as they do to one-shot completions.
- **Schema:** `tx_nrllm_ai_session_message` gets a UNIQUE key on
  `(session, sequence)` and `tx_nrllm_ai_session` a UNIQUE key on `uuid`. An
  installation that already produced colliding rows through the sequence race
  must resolve those duplicates before the database analyzer can apply the
  index.

- Persisted agent-run steps follow the central privacy level. At the default
  metadata level the stored payload keeps timings, tokens, cost, tool names and
  sizes but no prompts, tool arguments, tool results or raw provider bodies;
  `redacted` masks them; `full` stores them verbatim. The live playground trace
  is unaffected — it renders from memory.
- `AgentRunRepositoryInterface::purgeOlderThan()` deletes only **terminal**
  runs. Previously a purge by age could delete a run suspended for a human
  approval, destroying work in flight together with its resumable state.
- `nrllm:session:purge` and `nrllm:telemetry:purge` take their default window
  from the central privacy policy instead of a hardcoded 30 days.
- Session and agent-run purges delete in chunks of 500 instead of building one
  unbounded `IN()` list on a long-neglected installation.

### Fixed

- `DeepLTranslator` now runs a budget pre-flight on `translate()` and
  `translateBatch()`. It was the last paid external call with no cap at all;
  `TranslationService` threads `plannedCost` and `configuration` through to it
  alongside the already-threaded `beUserUid` (ADR-078, amended).
- `FalImageService` passes the caller's configuration identifier into its budget
  check, so per-configuration caps apply to it and not only the per-user cap —
  it previously passed `null` and only the user cap could ever fire.
- Corrected the middleware ordering documented in four middleware docblocks
  (`BudgetMiddleware` claimed a "Guardrail outermost at 115" that does not
  exist; `Fallback`, `Usage` and `Cache` omitted Guardrail and Idempotency
  entirely) and removed the stale note in `GuardrailInterface` calling input
  guardrails an unimplemented follow-up — they shipped in ADR-087.
- Restored the missing `[0.23.0]` changelog link definition; `[Unreleased]` now
  compares against `v0.23.0` instead of `v0.22.0`.

## [0.23.0] - 2026-07-20

Adds a content-policy guardrail pipeline, human-in-the-loop tool approval,
persistent AI sessions and agent runs, schema-validated structured outputs, and
typed provider exceptions, alongside broad tool-egress hardening and a bilingual
documentation site.

### Added

- Content-policy guardrail pipeline screening outgoing prompts and responses,
  with end-of-stream auditing and live streaming redaction via a holdback buffer
  (ADR-085, ADR-087, ADR-088, ADR-089).
- Human-in-the-loop tool approval: the agent loop can suspend for a human
  decision and resume the run (ADR-084).
- Persistent AI sessions with memory (ADR-083) and durable agent-run persistence
  with a queryable event stream (ADR-081).
- Schema-validated structured outputs with automatic repair (ADR-082).
- Typed `ProviderAuthenticationException` (HTTP 401) and
  `ProviderRateLimitException` (HTTP 429) provider exceptions (ADR-080).
- `ToolLoopServiceInterface` so downstream extensions can inject and test-double
  the tool loop (`runLoop()` / `resume()`) without depending on the final
  `ToolLoopService`.
- Bilingual GitHub Pages documentation site with ADRs, search, developer
  feature deep-dives (streaming/tools, RAG, providers), and on-device AI answers
  rendered as Markdown.

### Changed

- On resume, the tool loop restores the suspended run's original allow-list and
  options and re-applies the tool gate (permission, global enablement, RBAC) to
  the pending calls, fail-closed (ADR-084).
- Keep the system prompt on every turn and advance the run sequence on a failed
  turn.

### Fixed

- Harden provider response parsing against malformed and hostile upstreams,
  including typed guards for the DALL-E and DeepL response shapes.
- Null-guard site-config key normalization; clamp `maxRetries` to its TCA upper
  bound.
- Purge agent-run events by run id.

### Security

- Tool egress hardening: enforce FAL file-mount boundaries and backend language
  access in read tools, exclude workspace-draft references, and broaden the
  credential egress denylist (digit-suffix and concatenated secret columns,
  `apitoken`, `sk-proj-` keys, and Composer `auth.json` in `read_source`).

## [0.22.0] - 2026-07-17

Pulls the retrieval/document capabilities forward that the 0.21.0 revisit issues
had deferred to a second consumer, and extends the named-configuration model to
completion and translation.

### Added

- **Per-configuration translation** (#428, #429, #430).
  `TranslationService::translateForConfiguration()` translates with a stored
  `LlmConfiguration`'s persona/tone — routing through `chatWithConfiguration()`
  so the configuration's `system_prompt`, model and provider apply while the
  translation task rides in the user message. The translation path now also
  forwards the configured `model` (previously dropped), and a `configuration`
  field on `TranslationOptions` makes the config-selected specialized translator
  reachable.
- **Named-configuration completion** (ADR-077, #423). `CompletionService` gains
  a `*ForConfiguration()` family (`completeForConfiguration()`,
  `completeJsonForConfiguration()`, …) so plain completions resolve a named
  `LlmConfiguration` (its provider/model/prompt) and run through the middleware
  pipeline, enforcing budgets and attributing cost per configuration — matching
  the existing chat/tools/embedding `*ForConfiguration` entry points.
- **Budget pre-flight for specialized image/speech services** (ADR-078, #425).
  The DALL-E/FAL/Whisper/TTS services dispatch HTTP directly and bypassed the
  provider middleware; they now enforce per-user and per-configuration spend
  ceilings before the provider request (previously usage was attributed but not
  enforced on this path).
- **First-party fakes for Completion, Vision and Budget services** (ADR-079,
  #427). Ready-made doubles under the runtime-autoloaded
  `Netresearch\NrLlm\Testing\` namespace, so consumers stop hand-rolling doubles
  that break when an interface grows.
- **Neutral cross-encoder reranker protocol** (ADR-075, #414). `Service\Rerank\`
  ships `RerankerInterface` (id/text in, id/score out — no consumer DTOs),
  `HttpReranker` speaking the sidecar contract (batch cap 128, configurable
  timeout, typed `RerankerException` on transport/status/protocol failures),
  `NullReranker`, and a factory selecting by the `rerankerEndpoint` extension
  setting. The sidecar (`Build/reranker/`: cross-encoder scoring service,
  Dockerfile, protocol README) moves in from nr_ai_search so client and server
  version together. Integer candidate ids (TYPO3 uids) are accepted and
  normalized. DTO mapping, ordering merge, degradation policy and threshold
  gates stay consumer-side.
- **Document understanding** (ADR-076, #416).
  `Specialized\Document\DocumentAnalysisService` analyzes a PDF via the
  provider's native document path (`DocumentCapableInterface`: whole-document
  reasoning in one call) and falls back to poppler rasterization plus per-page
  vision otherwise. `PdfRasterizerInterface` + `PopplerPdfRenderer` (hardened:
  concurrent pipe draining, stderr in failure exceptions, race-free temp-stub
  handling) come from nr_ai_search's proven pipeline; `poppler-utils` is an
  optional runtime dependency (composer suggest). Ingestion orchestration
  stays consumer-side.
- **Hosted rank fusion** (ADR-074, #415). `Service\Retrieval\ReciprocalRankFusion`
  ports nr_ai_search's fusion math with an identical `fuse()` signature, so
  hybrid consumers migrate by swapping the namespace import. Newable utility,
  not a DI service; ADR-049's first-available-wins cascade is unchanged.

## [0.21.0] - 2026-07-17

### Added

- **Public keyword-search facade** (ADR-071). New public contract
  `Service\Retrieval\KeywordSearchInterface` — `search(string $query, int $limit,
  ?int $languageId = null): list<KeywordHit>` plus `isAvailable()` — over the ADR-049
  site-search cascade, so downstream extensions no longer bind to private retrieval
  internals. Input is clamped (never throws), results are always public-only, and any
  backend failure degrades to an empty list. A second registration,
  `nr_llm.keyword_search.index_backed`, excludes the priority-0 database LIKE fallback
  for consumers that must treat "index unavailable" as empty (e.g. hybrid dense+sparse
  fusion). Documented in `Documentation/Api/KeywordSearch.rst`; audited public-service
  count 26 → 28.
- **Retrieval-quality evaluation** (ADR-072). Golden question sets (MATCH/GAP forms,
  hard-class taxonomy, multi-target relevance labels) scored by top-1/top-3
  document-level hit rate, mirroring the ADR-060 golden-prompt framework.
  `EvaluatableRetrieverInterface` makes the retriever pluggable, so the builtin lexical
  cascade and external retrievers are measured with the same protocol; results persist
  through the existing regression machinery. `RetrievalEvalRunCommand` runs a set from
  the CLI. No golden questions ship with the extension.
- **First-party test doubles** (ADR-073). `Testing\FakeToolCallingService` and
  `Testing\FakeEmbeddingService` in a runtime-autoloaded namespace, so consumers stop
  hand-rolling fakes that drift from the interface. Each implements the real interface;
  excluded from container autoconfiguration.
- **`ConfigurationResolver::getActiveByIdentifier()`** (ADR-070). Resolves a named
  configuration by identifier for user-less contexts (CLI, Messenger workers, anonymous
  frontend), applying the isActive and backend-group access guards a raw repository
  lookup skips. Throws typed `ConfigurationNotFoundException`,
  `ConfigurationInactiveException`, or `AccessDeniedException`.
- **Seeded embedding-model dimensions.** The ADR-055 `dimensions` column is populated for
  well-known embedding models on setup and back-filled on existing rows by an upgrade
  wizard (never overwriting a configured value), so consumers take the fast path instead
  of a paid calibration probe.
- **Consumer recipes** in the developer documentation: protecting anonymous
  LLM-cost-bearing endpoints (per-IP rate limiting, `Sec-Fetch-Site` checks) and
  rendering LLM markdown server-side safely.

### Fixed

- **Solr URL scoping for scheme-relative site bases.** A `base: //host/` site base has an
  empty scheme, so `SolrSearchBackend::siteScopedUrl()` emitted the degenerate
  `://host/path` in evidence URLs and citations; the scheme now defaults to `https`.
  Document URLs with an empty or unparseable host are dropped rather than emitted to a
  foreign origin.
- **Adjacent text nodes in tool excerpts.** `strip_tags()` glued adjacent text
  (`<td>Price</td><td>100</td>` → `Price100`) in the excerpts handed to the model; a
  space is now inserted before non-inline tags, keeping inline-joined words intact.

## [0.20.0] - 2026-07-16

Closes out the operator-config audit: every backend-visible provider/model/configuration
setting now either works or is gone. Three breaking changes — see below.

> Note: version 0.19.1 was prepared but never tagged; its two fixes first ship in this
> release.

### Added

- **Per-configuration daily limits are now enforced** (#389). The Configuration record's
  *Max requests / tokens / cost per day* fields were stored but never consulted;
  `BudgetService` now aggregates the dispatched configuration's current-day usage from
  `tx_nrllm_service_usage` and denies requests once a cap is exhausted, in addition to
  the existing per-user budget — most restrictive wins. Callers without a backend user
  (CLI, scheduler, frontend) are gated by configuration caps too, where they previously
  bypassed budgeting entirely.
- **Model limits act as call defaults** (#390). A model's *Max output tokens* becomes the
  effective `max_tokens` when neither the call options nor the configuration set one
  (precedence: per-call option > configuration > model > provider default), and an
  embedding model's *Dimensions* fills `EmbeddingOptions` when the caller left it unset.
- **Organization ID and custom headers are now sent** (#388). The provider's
  *Organization ID* is emitted as the `OpenAI-Organization` header (OpenAI-compatible
  adapter types included), and `options.customHeaders` is applied on every request path
  — streaming builders included — with header names/values sanitized (CR/LF-injection
  guarded). The `options.proxy` key is documented as not implemented; the global TYPO3
  HTTP proxy applies.
- **Upgrade wizard `nrLlm_providerApiTimeout120`** migrates provider rows persisted at
  the old `api_timeout` default of 30 to the new default 120 (part of #384).

### Changed

- **BREAKING: `api_timeout` is applied as a total-response timeout** (#384). The field
  was write-only, so every provider request ran unbounded (TYPO3's default HTTP timeout
  of 0) and a silent provider could pin a PHP-FPM worker indefinitely. Requests are now
  bounded by the per-request `timeout` option (from the configuration's effective
  timeout, ≥ 120s by default) with the provider's `api_timeout` as fallback; the default
  moves 30 → 120; timed-out requests are not retried; streaming aborts at the same total
  timeout. Calls that legitimately run longer need a raised configuration/model timeout.
- **BREAKING: `max_retries` counts retries after the initial attempt** (#387). `0` now
  sends exactly one request (previously: zero requests failing with *"after 0 attempts:
  Unknown error"*); the default of 3 now sends up to 4 requests on persistently failing
  providers. Negative values clamp to "no retries".
- **BREAKING: `BudgetServiceInterface::check()` gained an optional `?LlmConfiguration`
  parameter** (#389) — third-party implementations of the interface must update their
  signature.
- `LlmConfiguration::setMaxTokens()` accepts 0 as "no explicit limit" (clamp floor moved
  from 1); existing records are unaffected since the old floor made 0 unstorable (#390).

### Removed

- **BREAKING: the PromptTemplate stack** — entity, repository, service + interface,
  exception, DI alias and the `tx_nrllm_prompttemplate` table declaration (#399,
  ADR-069). It was never usable at runtime (the table had no TCA) and had no consumers;
  prompt snippets (ADR-031) and tasks superseded the concept. The orphaned table can be
  dropped via the database analyzer; the audited public-service count moves 27 → 26.

### Fixed

- Criteria-mode configurations no longer share embedding cache entries under an empty
  model id — the cache key resolves the concrete model when the configuration has no
  direct model relation (#390 follow-up).
- `max_retries = 0` no longer disables a provider with a misleading connection error
  (#387, see Changed).

## [0.19.1] - 2026-07-15

### Fixed

- **Criteria-mode configurations could not be used by `*ForConfiguration()`.** `getAdapterFromConfiguration()` read the concrete model relation directly, which is null for criteria-mode configurations (`model_selection_mode = 'criteria'`, `model_uid = 0`), so every `embedForConfiguration()` / `chatWithConfiguration()` / tool call on such a configuration threw `Configuration "…" has no model assigned`. The model is now resolved through `ModelSelectionService` (which returns the directly configured model unchanged for fixed-mode configs), covering the embed / chat / tools / complete / stream paths through the single choke point. The configuration entity is deliberately not mutated — doing so would mark a repository-managed Extbase record dirty and persist `model_uid`, silently converting a criteria-mode record into a fixed-mode one (#372).
- The embedding cache tag built from the configuration identifier (`nrllm_configuration_<identifier>`) is now sanitized via the new `CacheManagerInterface::sanitizeCacheTag()`. A dotted preset identifier (`nr_ai_search.embeddings`) otherwise made the cache frontend reject the tag on `set()` when `cache_ttl > 0` — the same class as the cache key/tag sanitization shipped in 0.19.0 (#372).

## [0.19.0] - 2026-07-14

### Added

- **Per-user usage attribution for the specialized speech and image services** (ADR-057, the ADR-052 follow-up): `TranscriptionOptions`, `SpeechSynthesisOptions` and `ImageGenerationOptions` implement `BudgetAwareOptionsInterface` via `BudgetFieldsTrait` (optional `beUserUid`/`plannedCost` constructor params and `fromArray` keys, negative-value validation), and `WhisperTranscriptionService`, `TextToSpeechService` and `DallEImageService` forward the resolved uid to `trackUsage()` — so transcription, synthesis and image generation are attributed to the calling backend user instead of the ambient `be_user = 0` bucket for FE/CLI/worker callers. `FalImageService` reads a documented `beUserUid` options-array key (no DTO exists), `DallEImageService::createVariations()`/`edit()` gain an optional trailing `?int $beUserUid`. The budget fields stay out of `toArray()`, so they never reach the provider APIs. Attribution only — these services bypass the middleware pipeline, so per-user budget ceilings are still not enforced there (out of scope in ADR-057) (#362).

### Fixed

- **LLM configuration identifiers with dots crashed every cached call.** `CacheManager::generateCacheKey()` and the provider-derived cache tags used the raw provider/configuration identifier, but TYPO3 cache frontends only accept `A-Za-z0-9_%-&` in entry identifiers and tags. The documented preset naming scheme uses dots (e.g. `nr_ai_search.embeddings`), so every completion/embedding call for such a configuration threw `"… is not a valid cache entry identifier"` — found live on the first 0.18 deployment. The provider segment is now sanitized in both the key and every tag (#365).
- `LlmTranslator`'s underlying chat calls (translation and language detection) built their `ChatOptions` without the `beUserUid` that `TranslationService` attaches per ADR-052, so the pipeline-recorded chat row — which carries all tokens and cost — landed in the ambient bucket and `BudgetMiddleware` skipped per-user enforcement. The uid is now threaded into both `ChatOptions` constructions; the public `detectLanguage()` signature stays ambient (#361).
- Backend `Test.js` is loaded as an ES module, fixing the model-test button in the backend module (#363).

## [0.18.0] - 2026-07-14

### Added

- **Configuration presets module UI** (the ADR-056 follow-up): the Configurations backend module surfaces pending presets — one row per preset with name, identifier, description, and the preflight result (the model the criteria currently match, or the missing requirement) — and imports one via the existing `nrllm_preset_import` endpoint with a single click; the panel renders only while presets are pending. Imported records whose declaration changed since import are flagged with a non-blocking "Preset changed" hint (checksum comparison via the new `ConfigurationPresetRegistry::drifted()`), and `nrllm_preset_list` returns these as a new `drifted` list. The checksum-driven update flow remains future work (ADR-056).

### Changed

- The specialized translators forward the caller-supplied `beUserUid` to usage tracking: `TranslationService` re-attaches the resolved uid to the options array handed to `TranslatorInterface` implementations (`beUserUid` key — budget fields are deliberately excluded from `TranslationOptions::toArray()`), and `DeepLTranslator` / `LlmTranslator` pass it on to `trackUsage()`, so translator usage rows are attributed like the middleware-pipeline paths. The speech/image services (Whisper, TTS, DALL-E, FAL) keep ambient attribution — their option shapes carry no budget fields (ADR-052).
- The remaining validation errors thrown with PHP's global `InvalidArgumentException` (record table reader, retrieval queries, vision content, embedding responses, provider response parsing, backend response DTOs, provider vault-key checks, usage analytics) now throw nr_llm's `Exception\InvalidArgumentException`, so they are catchable via `NrLlmExceptionInterface` too. Backwards compatible: the nr_llm class extends `\InvalidArgumentException`, existing catches keep matching (ADR-053).

## [0.17.0] - 2026-07-13

### Added

- **Configuration presets**: consuming extensions declare the `LlmConfiguration` records they need via the `nr_llm.configuration_preset` DI tag (`ConfigurationPresetProviderInterface` + `ConfigurationPreset` value objects). Presets express requirements as `ModelSelectionCriteria` — never providers, models, or API keys — and a backend admin imports a pending preset with one confirmation through the new admin-gated AJAX endpoints `nrllm_preset_list` (pending presets incl. a per-preset preflight against the configured models) and `nrllm_preset_import`. Imported records are active criteria-mode configurations resolved at runtime by `ModelSelectionService`; the new `preset_checksum` column makes a changed declaration detectable (ADR-056) (#347).
- `ToolCallingService` / `ToolCallingServiceInterface` — the feature-service pair for tool-calling chat (`chatWithTools()`, `chatWithToolsForConfiguration()`), completing the narrow-interface catalogue so consumers no longer need to depend on the 19-method `LlmServiceManagerInterface` for tool calling (ADR-051).
- `NrLlmExceptionInterface` — a marker interface implemented by every exception thrown on the public API surface, so consumers can write a single `catch (NrLlmExceptionInterface $e)` instead of enumerating concrete classes. `ChatMessage`/`ToolSpec`/`ToolCall::fromArray()` normalisation errors now throw nr_llm's `Exception\InvalidArgumentException` (a subclass of PHP's, so existing catches keep matching) and are covered by the marker too (ADR-053).
- `ChatMessage` models the two tool-loop turns as typed value objects: an assistant turn carrying `$toolCalls` (`list<ToolCall>`) and a tool turn carrying `$toolCallId`, built via the new `ChatMessage::assistantToolCalls()` / `ChatMessage::toolResult()` factories. `toArray()`/`jsonSerialize()` emit the OpenAI wire shape (`tool_calls` with JSON-string `function.arguments`, empty arguments as `{}`; `tool_call_id`), `fromArray()` accepts the keys back (including `content: null` alongside `tool_calls`), and invalid combinations — tool calls on a non-assistant role, a `tool_call_id` on a non-tool role, an empty id — are rejected with nr_llm's `InvalidArgumentException`. `ToolLoopService` builds its turns through the factories instead of raw wire arrays, and the tool-calling developer docs are rewritten on top of the value objects (ADR-054) (#345).
- Configuration-record path for embeddings: `LlmServiceManager::embedForConfiguration()` and `EmbeddingService::embedForConfiguration()` / `embedBatchForConfiguration()` resolve the adapter from a DB-backed `LlmConfiguration` (vault key + model + pricing) and run through the middleware pipeline, so embedding consumers get per-configuration budgets and cost attribution like every chat-shaped capability. Per-call `EmbeddingOptions` override the configuration's stored defaults (an options `model` wins over the configuration's model id). Note: `LlmServiceManagerInterface` and `EmbeddingServiceInterface` gained these methods — implementers outside this repo must add them. New `dimensions` field on model records (`tx_nrllm_model`, 0 = unknown) so consumers can validate a persisted vector index against the configured model without a live calibration probe (ADR-055) (#346).

- Reasoning toggle for hybrid-thinking models (Ollama `think`) (#341).

### Changed

- Usage attribution honours the caller-supplied `beUserUid`: `UsageMiddleware` forwards the uid the options carry (`withBeUserUid()`, the same metadata the budget gate enforces against) to `UsageTrackerService`, instead of always reading the ambient `backend.user` aspect. Frontend/CLI callers that pass a uid no longer need to impersonate a technical backend user just to get correct `be_user` rows; without a caller uid the ambient fallback behaves as before. `UsageTrackerServiceInterface::trackUsage()` gains an optional trailing `?int $beUserUid = null` parameter — implementers outside this repo must add it (ADR-052).

### Fixed

- Ollama's native `message.thinking` is surfaced on completion responses (`CompletionResponse::$thinking` / `hasThinking()`) (#342).

## [0.16.1] - 2026-07-10

### Fixed

- The setup wizard's AJAX persist path now enforces the column limits FormEngine would apply: names are clamped to 255 characters, identifiers to 100, and caller-provided identifiers get the TCA contract (`alphanum_x`, lowercase). Strict-mode MySQL/MariaDB previously rejected overlong values with a 500 where SQLite silently truncated. Generated identifier suffixes are now random — the `time()`-based suffix collided for same-named records created in one batch (#335, #339).
- Decimal-backed model values (`temperature`, `top_p`, penalties, cost ceilings) are rounded to their column scale in the setters, so every DBMS stores and returns the identical value (#336, #339).

### Changed

- The MariaDB CI leg runs the full functional configuration (functional + e2e-backend suites) now that the strict-mode incompatibilities are fixed (#339).

## [0.16.0] - 2026-07-10

### Added

- **RAG site-search tools** (41 tools / 8 groups): new `rag` tool group with `site_rag_query` — cited evidence about the website's own public content (`source_id`, title, URL, match excerpt), retrieved through a priority cascade over whichever search index is installed (EXT:solr via its HTTP select API, ke_search, indexed_search) with an always-available pages/tt_content database fallback — and `site_fetch_source` to read a source's full indexed text. Index-level filtering is strictly public-only (what the anonymous visitor could read); the answering backend is named in every evidence package (ADR-049). The database fallback matches natural-language questions word-wise and ranks pages by how many query words they cover (#332, #333).

### Changed

- Functional tests additionally run against MariaDB in CI (one matrix cell), keeping the MySQL-only retrieval branches exercised; two pre-existing MySQL incompatibilities in the e2e-backend suite surfaced by this are tracked in #335/#336 (#334, #337).

## [0.15.0] - 2026-07-09

### Added

- **Playground run inspector ("glass box").** The admin Tool Playground was rebuilt around a full run trace: every outbound request (messages sent, tools offered), every model response (structured view, raw JSON, extracted thinking), every tool execution with arguments/result/duration, and the final answer appear as an ordered step list with a summary strip (rounds, tool calls, prompt/completion token split, estimated cost, wall time). Includes a dry-run mode that assembles and shows the exact prompt without calling the model, optional raw provider-response capture, and per-run overrides for skills, snippets and the system prompt (ADR-040) (#314).
- **Live streaming.** The inspector streams from the moment Run is clicked — the outbound request appears before the model answers, then responses and tool executions arrive as they happen (NDJSON over a padded stream that defeats proxy buffering). The run form collapses on Run and the layout stacks form above inspector; max-tokens and temperature are exposed as run controls, and a truncation warning appears when the model hits the token limit (ADR-041) (#317, #320).
- **28 new built-in tools** (39 total), organised in groups, covering the diagnostic questions "who changed this?", "why is this page broken?", "check my TCA/TypoScript":
  - *content:* `search_records`, `get_page_content`, `read_records`, `get_record_history` (sys_history: who changed what, `old → new` per field) (#321, #325)
  - *structure:* `get_full_tca` (navigable TCA index), `get_table_schema` (relations surfaced), `get_flexform_schema`, `resolve_url` (URL → page, routing only), `validate_tca` (structural checks: dangling `foreign_table`, showitem/palette references, v14 `ds_pointerField`) (#324, #325)
  - *configuration:* `get_typoscript`, `get_tsconfig` (page-effective, drill-down, redacted), `fluid_resolve`, `check_typoscript` (core syntax scanner over constants+setup, incl. site-set TypoScript), `get_site_config` (credential-keys redacted) (#321, #325, #328)
  - *code:* `get_last_exception` (parsed stack trace + source context from the TYPO3 logs), `read_source` (path-guarded), `search_code` (value-redacting) (#323)
  - *files:* `list_fal_storages`, `browse_fal_folder`, `search_fal_files` (case-insensitive on every DBMS), `get_fal_references`, `find_missing_files` (#327)
  - *system:* `probe_url` (GET against this instance only, 5xx auto-correlates the matching log exception), `list_extensions`, `list_scheduler_tasks` (never unserializes task blobs), `get_system_status`, `list_deprecations`, `list_middlewares` (#323, #328)
- **Tool groups.** Every tool declares a group; whole groups can be toggled centrally in the Tools module, restricted per LLM configuration (`allowed_tool_groups`), and (de)selected per run in the playground. Enablement cascades fail-closed: a tool runs only when group, tool and configuration all permit it (ADR-043) (#322).
- **Backend overview facelift.** The LLM module landing page is now a real starting point: a usage-and-cost band on top (30-day KPIs + a 7-day provider breakdown), a single unified "Set up & manage" card grid, and a "For developers" teaser. Setup guidance is folded onto the module cards themselves — each card shows its state (ready / next / empty / locked) so the recommended next step is always visible, without a separate stepper. The Providers card carries live, **token-free** reachability dots (a cached model-list/health ping, never a completion). Cards are whole-card links. New `OverviewReadinessService` (state matrix) and `ProviderReachabilityService` (cached probe over configured provider records). (#313)
### Changed

- **BREAKING:** `ToolInterface` now requires a `getGroup(): string` method. Every implementer must declare its group — third-party extensions are recommended to use their extension key, so an admin can disable an extension's whole tool family with one toggle (ADR-043, #322).

### Fixed

- Non-UTF-8 bytes anywhere in a run — tool output, provider request/response bodies or the trace itself — no longer crash the playground with an opaque 500; invalid sequences are substituted on every boundary (#315, #316).
- Out-of-range temperature values are clamped instead of raising an uncaught exception, and the run round count is capped server-side (#314, #317).
- The playground system-prompt placeholder renders as text instead of raw Fluid markup (#319).
- `FlexFormTools` calls are version-gated: TYPO3 v14 requires the schema argument the 13.4 signatures do not have, and the two majors expect opposite `ds` shapes (#324).
- `validate_tca` no longer flags core-managed (auto-created) columns declared via `ctrl` — enablecolumns, language fields, `origUid` — and `check_typoscript` scans site-set/site-local TypoScript instead of reporting "no template" on sys_template-less v13+ sites (#326).
- `resolve_url` handles relative site bases (`base: /`) and schemeless path input (#325).
- Scheduler tasks report their last run time; the middleware listing survives resolver changes and reports exact counts (#328).

### Security

- `probe_url` matches allowed hosts on exact scheme-defaulted `host:port` (a bare-host match would have let `localhost:6379` through), rejects userinfo URLs and strips credentials before echoing anything back (#323).
- `get_record_history` requires PAGE_SHOW on the record's page for non-admins — history values cannot leak from unreadable pages (fail-closed for unresolvable records) (#325).
- `browse_fal_folder` enforces folder-level file-mount boundaries for non-admins on top of the storage allow-list; outside a backend request FAL access fails closed rather than mount-blind. Server paths never egress from any FAL tool (#327).
- `check_typoscript` reports source + line + error kind only — never the offending line's content (a broken constants line may carry an API key); `get_site_config` redacts credential-like keys including camelCase forms (#325, #328).

### Documentation

- ADR-040 through ADR-048; the Tools guide covers all 39 built-ins, the group taxonomy and the playground inspector; a tip advises narrow tool groups for small local models — verified live: the seeded `qwen3:4b` makes no tool call when offered the full set, and picks correctly when restricted to a group (#329).

## [0.14.1] - 2026-07-05

### Fixed

- Tool calling with parameterless tools now works. A tool that takes no arguments declared its JSON-Schema `properties` as an empty PHP array, which serialises to `[]` — but JSON Schema requires an object, and strict providers (Ollama) reject the whole request with HTTP 400 (`Value looks like object, but can't find closing '}' symbol`). The same applied to a parameterless tool call's empty `{}` arguments when the agent loop replayed them (`json_decode('{}', true) === []`). Both are now emitted as `{}`, so the bounded agent loop and the Tool Playground work when a parameterless tool — environment, PHP info or backend user/group introspection — is offered (#308).

### Documentation

- Refreshed the Skills, Tools and Playground admin guide: corrected the LLM module section count, replaced the outdated built-in tool list with the full catalogue, documented the two-tier (admin / non-admin) tool authorization, clarified that skill injection is eager and complete, and updated the screenshots (#307).

## [0.14.0] - 2026-07-04

### Added

- **Skills.** Ingest `SKILL.md` from GitHub sources with SHA-pinned sync, disabled-by-default and orphan/auto-disable lifecycle, and an admin backend module for sources and review; marketplace `marketplace.json` / `{source: git, url}` parsing; attach skills to tasks and configurations and inject them into text-generation prompts with a token budget and integrity verification (ADR-035, ADR-036) (#259, #261, #263, #273, #277, #295, #297).
- **Tools.** Function-calling tool runtime — a bounded agent loop, a DI-tagged tool registry, Ollama tool support and per-run allow-list gating; per-tool enable/disable with a built-in system tool set; an interactive admin tool playground; the Playground and Tools backend modules are separate (ADR-038) (#262, #264, #265, #274, #276, #296).
- Together AI, Fireworks AI and Perplexity are now first-class OpenAI-compatible adapter types with canonical endpoints (#300, #304).
- Backend module UX overhaul (help, glossary, readiness checks, enablement flow), accessibility text alternatives and screen-reader status text, and EN/DE translations across backend/AJAX/wizard strings (#275, #280, #288).

### Changed

- **BREAKING:** `ToolInterface` now requires a `requiresAdmin(): bool` method. Every implementer must declare it — return `true` for tools exposing system/host/cross-user data (logs, environment, phpinfo, backend-user/group listings), `false` for tools that self-enforce the acting user's TYPO3 permissions. Without it, the tool fatals at runtime on instantiation (ADR-038, #262).
- CI now runs the functional + e2e-backend suites on every event, so they gate merges (previously skipped entirely; closes #272) (#298).
- TYPO3 v15 forward-compatibility declared via composer package metadata, with a version-drift guard (#271).
- Dependencies upgraded (non-framework); `symfony/yaml` to v8; Node/Playwright/axe-core bumps (#251, #252, #253, #260, #301).
- ADRs audited against the code — drift corrected, ADR-039 added (#302).

### Fixed

- Provider endpoints are canonicalized on save on both write paths — the Setup Wizard (#98, #299) and the manual TCA record editor via a DataHandler hook (#300, #303) — so a bare host gains the adapter's API version path (e.g. OpenAI `/v1`) instead of breaking unversioned.
- The configuration system prompt is now applied on all completion paths, including streaming (#292).
- Per-configuration backend-group access control now works. The `beGroups` MM relation on `LlmConfiguration` had no Extbase mapping, so `LlmConfigurationRepository::findAccessibleForGroups()` raised `MissingColumnMapException` for any backend user in a group, and the in-PHP fallback check always saw an empty group list. Present since at least v0.13.0 (#289).
- Skill sync no longer wedges on a crashed run (stale-lock recovery), the skill-source list status/type badges render again, and `SkillSource.githubToken` is hydrated so sync can authenticate (#295, #297).
- Fluid 5 strict compound-condition mis-evaluation in backend status warnings (#294).
- Backend TCA `ORDER BY` SQL error, the task Run button target, and the wizard "Go to Configurations" link (#291, #293).
- Functional test debt cleared — backend controller test construction repaired after the #256 refactor, and the remaining assertion failures resolved (#267, #269).
- Model/completion cache tags sanitized and capped to TYPO3's 250-char limit; usage keyed on configuration and the response model recorded when the configuration carries none (#292).

### Security

- Gemini API key sent via the `x-goog-api-key` header on all calls instead of the URL query string, so it no longer leaks into logs, history or the Referer header (#286, #292).
- CSRF tokens required on backend AJAX endpoints; middleware order pinned; all backend AJAX endpoints require an admin backend user (#262, #278).
- SSRF hardening — schemeless-endpoint bypass closed, empty-username credential leak fixed, api-key-less provider endpoints gated against the host filter (#292).
- Acting-user RBAC enforced on tool execution; surfaced exception messages redacted; tool-result size bounded (#276, #292).
- Input clamps (temperature, maxTokens, token counts, retry backoff) across providers and the wizard; API key cleared from Setup Wizard memory after save (#285, #286, #292).

## [0.13.0] - 2026-06-26

### Changed

- **BREAKING:** `LlmServiceManager::getProvider(null)` now throws
  `ProviderException` (4867297358) instead of falling back to an
  extension-config default provider.

  **Migration:** select a provider in one of the two supported ways —
  pin it per call via the options object's `provider` field
  (`new ChatOptions(provider: 'openai')`, likewise `EmbeddingOptions`/
  `VisionOptions`/`ToolOptions`), or mark a Configuration *active* and
  *default* in the LLM backend module so the generic `chat()`/`complete()`/
  `embed()` entry points resolve it automatically. To read the configured
  default programmatically, use
  `LlmConfigurationService::getDefaultConfiguration()` (access-checked) or
  `LlmConfigurationRepository::findDefault()` (raw).

### Removed

- **BREAKING:** `setDefaultProvider()` and `getDefaultProvider()` removed from
  `LlmServiceManagerInterface` (and its implementation), and the
  `ExtensionConfiguration['nr_llm']['defaultProvider']` setting is no longer
  read. These were a vestige of the pre-database provider-centric design and
  had no effect in production (the key was never exposed in
  `ext_conf_template.txt`). See ADR-034.

### Fixed

- Removed the orphaned `plugin.tx_nrllm` TypoScript constants/setup that were
  registered but never read by any code, and which misleadingly implied that
  provider selection was TypoScript-driven. The "No provider specified and no
  default provider configured" exception now carries actionable guidance
  pointing to the backend module, and the configuration docs describe the
  database-backed setup. (#254, #255)

## [0.12.0] - 2026-06-11

### Added

- **Prompt-snippet library.** New `tx_nrllm_promptsnippet` entity with a
  backend module tab: editors manage small named prompt fragments (personas,
  tones of voice, audiences, image styles, layouts) with free-form tags and
  optional metadata JSON; consuming extensions query them by tag and compose
  them into their prompts (`findActiveByTag()`, `findByUids()`,
  `PromptSnippetComposer`). See ADR-031.
- **Specialized usage and cost tracking.** Image, TTS and transcription calls
  now record real units (images, characters, audio seconds), token usage from
  gpt-image responses, the model id, and an estimated cost via a documented
  OpenAI price catalog with a model-row-first cascade — the Analytics module,
  cost widgets and budget windows finally see the full spend. Usage rows link
  `model_uid` and `configuration_uid` for per-model / per-configuration
  breakdowns. See ADR-032.
- **Specialized models join the model registry.** New model capabilities
  `image`, `text_to_speech` and `transcription`; the specialized services
  resolve their default model from active registry records
  (`resolveDefaultModel()`), guarded by a per-service vocabulary check so an
  OpenAI default never reaches the FAL endpoint and vice versa. See ADR-033.
- **Configuration-based resolution for specialized services.**
  `resolveModelForConfiguration()` and `getConfigurationSystemPrompt()` make
  `tx_nrllm_configuration` records the stable indirection layer for image and
  speech calls too: administrators swap models and maintain prompt preambles
  centrally; the `configuration` option attributes usage per configuration.
- **Per-request timeouts on the secure-client path.** The services' timeout
  now reaches the wire via nr-vault's new `withTimeout()`; the image default
  rose to 300s — large gpt-image-2 generations no longer die at the global
  HTTP timeout.
- **Arbitrary gpt-image sizes.** `ImageGenerationOptions` accepts any
  `WIDTHxHEIGHT` for gpt-image-* models (divisible by 16, aspect 1:3-3:1,
  max 3840x2160) alongside the documented standard sizes.

### Fixed

- **Name-style nr-vault identifiers work as API keys.** `Provider` accepted
  only UUID-v7 vault identifiers; name-style identifiers were misread as
  legacy plaintext, breaking key decryption (silent model-discovery fallback
  to a stale catalog) and even re-saving the provider record.
- **Model discovery is honest about fallbacks.** tts/whisper/dall-e models are
  no longer filtered out, the static fallback catalog is current (gpt-5.5,
  specialized entries), discovery failures are logged, and the model-fetch
  response flags `source: fallback` with a visible notice in the form.
- **Vault audit log readability.** Audit reasons carry the actual model and
  purpose (e.g. "OpenAI Images API call (gpt-image-2, generate)"), and the
  per-request audit context is consumed so later requests cannot inherit it.
- The Snippets module no longer crashes on inactive snippets (Fluid getter
  pair for the is-active flag), and the snippet count honours the hidden
  enable-field contract.

### Changed

- **nr-vault requirement raised to `^0.10.0`.** The secure-client integration
  now relies on the current nr-vault API surface (per-request `withTimeout()`,
  header-placement options); older constraint branches were untested claims.
- Overview module cards gained "+ New record" quick actions plus Snippets and
  Analytics cards.
- `runTests.sh` defaults to PHP 8.5, the upper supported bound.

## [0.11.1] - 2026-06-10

### Security

- **Setup-wizard requests go through the nr-vault secure HTTP client.**
  `ModelDiscovery` and `ConfigurationGenerator` now dispatch via the
  SSRF-guarded vault client with an `isHostAllowed()` pre-gate, so a
  malicious endpoint URL can no longer point the wizard at private
  networks or cloud metadata.
- **Streaming errors are no longer swallowed.** All seven provider
  adapters validate the streaming response status: 4xx raises a typed,
  credential-sanitized provider exception, other non-2xx a connection
  exception (previously failures could surface as empty streams).
- Provider error messages consistently redact credential query
  parameters (`?key=…` → `key=***`) on error and retry-exhaustion paths;
  `testConnection()` returns a generic client-facing message and logs
  the sanitized detail server-side.

### Fixed

- TTS hard-splitting of over-limit text is multibyte-safe
  (`mb_str_split`), so UTF-8 sequences are no longer cut mid-character.
- FAL polling clamps `pollInterval` to ≥1ms (a `0` setting busy-looped /
  divided by zero) and reports validation errors with explicit context.
- Whisper configuration values are type-guarded; an empty base URL falls
  back to the OpenAI default instead of producing invalid requests.

## [0.11.0] - 2026-06-10

### Added

- **The backend module's default Configuration now drives generic completion.**
  When a caller invokes `chat()`, `complete()`, or `streamChat()` without
  pinning a provider, the manager first resolves the active *default*
  database-backed `LlmConfiguration` (Provider → Model → Configuration, as
  managed in the backend module) and routes the call through it — provider
  adapter, model, and vault-backed credentials all come from the module's
  records. Per-call `ChatOptions` (temperature, response format, system
  prompt, …) override the configuration's stored defaults, so JSON mode and
  per-call prompts keep working unchanged.
- `chatWithConfiguration()`, `completeWithConfiguration()` and
  `streamChatWithConfiguration()` accept an `$optionOverrides` array whose
  entries take precedence over the configuration's stored options.

### Changed

- The extension-configuration `defaultProvider` is now a *fallback*: it is
  used only when no usable default configuration exists in the database. A
  default configuration is skipped (falling back) when it has no model
  assigned or when it is access-restricted to specific backend groups —
  group-restricted configurations are never auto-applied to callers without
  a backend-user context (e.g. the CLI messenger worker).

## [0.10.0] - 2026-06-09

### Changed

- **Specialized services authenticate through nr-vault.** The DALL-E, FAL,
  Whisper, TTS, and DeepL specialized services no longer read a plaintext API
  key. Each stores an nr-vault secret *identifier* and authenticates through
  the audited secure HTTP client (`$vault->http()->withAuthentication(...)`),
  mirroring the database-backed providers (ADR-012). The secret is resolved,
  injected, audited, and memory-scrubbed inside the vault and never surfaces in
  this extension. FAL (`Authorization: Key …`) and DeepL
  (`Authorization: DeepL-Auth-Key …`) use the nr-vault 0.8.0 `prefix` option;
  DeepL's Free/Pro routing stays automatic by retrieving the key once, lazily,
  only to test the `:fx` suffix, then scrubbing it. See ADR-030.

### Removed

- **Plaintext API keys for the specialized services.** The extension-configuration
  keys are now nr-vault identifiers: `providers.openai.apiKeyIdentifier`
  (DALL-E/Whisper/TTS), `image.fal.apiKeyIdentifier`, and
  `translators.deepl.apiKeyIdentifier`. Host applications that wrote plaintext
  keys into these settings must store a vault secret and write its identifier
  instead. Requires `netresearch/nr-vault ^0.8.0` (composer floor raised from
  `^0.6.0 || ^0.7.0`).

## [0.9.0] - 2026-06-08

### Added

- **gpt-image-\* model family support for image generation.** OpenAI retired
  DALL·E-3; accounts now expose the `gpt-image-*` family (`gpt-image-1`,
  `gpt-image-1-mini`, `gpt-image-1.5`, `gpt-image-2`, …). `ImageGenerationOptions`
  accepts these models by prefix and validates their size set
  (`1024x1024` / `1536x1024` / `1024x1536` / `auto`); `DallEImageService` maps the
  whole family to a shared capability profile and sends a minimal
  `model`/`prompt`/`n`/`size` payload (no `response_format`/`style`/`quality`,
  which gpt-image rejects), reading the returned `b64_json`.

### Fixed

- **Chat JSON mode was never requested.** `CompletionService::completeJson()` asks
  for `response_format=json` and then strictly decodes the reply, but
  `OpenAiProvider` dropped the option, so the model could return prose/Markdown-
  fenced JSON and the decode failed. The provider now maps `response_format=json`
  to OpenAI's `{"type":"json_object"}` in `chatCompletion()` /
  `chatCompletionWithTools()`.
- **Empty `baseUrl` broke the specialized services.** An empty ext_conf
  `image.dalle.baseUrl` / `image.fal.baseUrl` / `speech.tts.baseUrl` (the documented
  "use the provider default" value) was used verbatim as the request URL, producing
  a scheme-less URL and a Guzzle failure on a stock install. Empty now falls back to
  the provider default via a shared `nonEmptyStringOrDefault()` helper.

## [0.8.0] - 2026-06-02

### Added

- **Usage Analytics dashboard** — new *Admin Tools → LLM → Analytics* submodule with cost/usage trends, breakdowns by provider/model/service, KPI tiles, and per-user usage with budget consumption.
- **Real cost tracking** — `UsageMiddleware` now computes `estimated_cost` from model pricing (prompt/completion split); the `tx_nrllm_service_usage` table gained `model_uid`, `model_id`, `prompt_tokens`, `completion_tokens`.
- **Per-list usage columns** — the Providers, Models, Configurations, and Tasks list views show *Cost / Requests / Tokens (last 30 days)* per row.
- **Per-task usage tracking** — task executions record their `task_uid` (threaded through the provider middleware pipeline), so usage rolls up per task.
- **`ddev seed-usage`** — dev-only generator for ~90 days of realistic historic demo usage (creates paid demo providers/models/configurations/tasks so every list column and the dashboard have content).

### Fixed

- Cost was never recorded for LLM calls (`estimated_cost` was always `0.00`); the *AI cost this month* dashboard widget now shows real figures.
- **Mutation testing tool error (audit 2026-04-30, deferred item):**
  `Build/Scripts/runTests.sh -s mutation` previously errored out
  partway through Infection's initial test suite phase with an
  opaque `[ERROR]` and never reached the mutation step. Root cause:
  three test classes carried `#[CoversClass(...)]` attributes
  pointing at classes that are excluded from the coverage source
  in `Build/phpunit.xml` — `MessageRole` (an enum; the whole
  `Classes/Domain/Enum/` directory is excluded), `ProviderResponseException.php`
  excluded as a specific file (alongside most other files in
  `Classes/Provider/Exception/`, but not all — e.g.
  `FallbackChainExhaustedException.php` is not on the exclude
  list), and the `MessageRole` reference on `ChatMessageTest`.
  PHPUnit 12 raises "Class … is not a valid target for code
  coverage" warnings for those, and `failOnWarning=true` turns
  them fatal under `--coverage` runs only — which is what
  Infection does. Replaced the offending `#[CoversClass]`
  attributes with `#[CoversNothing]` on `MessageRoleTest` and
  `ProviderResponseExceptionTest`, and dropped the
  `#[CoversClass(MessageRole::class)]` line from `ChatMessageTest`
  (the `#[CoversClass(ChatMessage::class)]` attribution stays).
  The warnings drop from 39 to 0 under the `unitCoverage` suite;
  Infection can now run end-to-end. Mirrors the guidance already
  in `Tests/AGENTS.md`: "use `#[CoversNothing]` for
  enums/exceptions".
- **Audit-review-followup (audit 2026-04-30):** Four review
  comments left by Copilot and gemini-code-assist on the
  audit-merged PRs (#202 and #203) were missed in the merge
  rush — the merge queue does not gate on review-thread
  resolution and the threads were not cleared before merge.
  Addressed in this slice:
  (a) `TaskInputResolver::resolveTable()` — the comment on the
  `catch (InvalidArgumentException)` arm previously said the
  exception text was "safe to surface", which conflicted with
  the REC #11b contract that error-arm output never surfaces
  `$e->getMessage()` regardless of type. Comment rewritten to
  match what the code actually does.
  (b) `Tests/Unit/Provider/Exception/ProviderResponseExceptionTest.php`
  docblock — clarified that the coverage exclusion is per-file
  in `Build/phpunit.xml`, not directory-wide
  (`FallbackChainExhaustedException.php` is in the same directory
  but is not excluded).
  (c) `Tests/Unit/Domain/ValueObject/ChatMessageTest.php` — the
  inline note about why `MessageRole` is no longer attributed
  via `#[CoversClass]` is now a DocBlock (matches the rest of
  the test suite), with bare class names rather than path-like
  syntax.
  (d) The CHANGELOG entry for the mutation-tool fix above is
  rewritten not to overstate the directory-wide exclusion.
  Pure follow-up — no code behaviour change.

### Changed

- **REC #11b (audit 2026-04-30, follow-up to REC #11):**
  `TaskInputResolver` no longer interpolates `$e->getMessage()` into
  the LLM input string when sys_log or record-table reads fail.
  Three behaviour changes, all in
  `Classes/Service/Task/TaskInputResolver.php`:
  1. The constructor takes a new `LoggerInterface` parameter (autowired
     by Symfony DI — no `Services.yaml` change needed).
  2. The two `catch (Throwable $e)` arms now `$this->logger->warning(...)`
     the full exception together with task-uid / table / limit context,
     and return a generic localised "see system log" message instead
     of the raw exception text. The previous behaviour leaked DBAL
     error fragments (table names, column hints, sometimes SQL) into
     the LLM prompt and onward to user-visible task output.
  3. The table branch grew a dedicated `catch (InvalidArgumentException)`
     arm in front of the broad Throwable arm — picker-policy
     rejections (table on the exclusion list) are not runtime errors
     and now route through `$this->logger->info(...)` instead of
     `warning`. The user-facing string is the same generic
     "see system log" message.
  XLIFF: `task.syslog.readError` and `task.table.readError` lost
  their `: %s` placeholder — the new source / target strings are
  "Error reading X. See system log for details." (EN) and
  "Fehler beim Lesen ... Details siehe Systemlog." (DE). Plus a
  new private `translate()` helper wraps `LocalizationUtility::translate()`
  in a defensive try/catch so the resolver stays unit-testable
  without bootstrapping `LanguageServiceFactory` (which
  `LocalizationUtility::translate()` instantiates eagerly and
  which throws when called outside the TYPO3 framework
  bootstrap). New
  `Tests/Unit/Service/Task/TaskInputResolverTest.php` provides 8
  unit tests / 64 assertions covering the happy paths, both
  read-failure regression contracts (no message leak + warning
  emitted), and the picker-policy info-log routing.
- **REC #11 (audit 2026-04-30, partial):** Bare `catch (Throwable)`
  cleanup outside REC #8b's admin-controller scope. Two surgical
  changes:
  - `OllamaProvider::getAvailableModels()` — the catch arm that
    falls back to a hardcoded model list when the Ollama server is
    unreachable now logs a `warning` (with `exception` and
    `baseUrl` context) before returning the defaults. Operators
    can see when their endpoint is silently down instead of
    discovering it later via "the model picker only shows five
    options". `$this->logger` is already injected by
    `AbstractProvider`.
  - `Provider::getDecryptedApiKey()` — the silent
    `catch (Throwable) { return ''; }` is **kept** but the comment
    is sharpened to document why: the empty-string return is
    load-bearing for `isFullyConfigured()`, `toFullArray()`, and
    the two `ModelController` adapter-construction sites; adding a
    logger here trips `failOnWarning=true` in unit-test paths that
    construct providers without a vault service. The operational
    signal belongs at the controller call sites — deferred to a
    follow-up. Two other sites flagged by the audit
    (`TaskInputResolver:59,133`, `TextToSpeechService:429`) are
    not touched in this slice — see audit doc for the rationale
    (TaskInputResolver needs a test-first refactor; TextToSpeechService
    is already a typed-final-arm pattern that matches REC #8b's
    intent).
- **REC #8b (slice 23b):** Replaced catch-all `catch (Throwable $e)`
  blocks with typed exception handlers across the four admin
  controllers (`ProviderController`, `ModelController`,
  `ConfigurationController`, `LlmModuleController`). 13 catch sites
  updated. Provider errors (`ProviderResponseException`, base
  `ProviderException`) and Doctrine DBAL errors now route to specific
  arms with appropriate HTTP statuses (502 for upstream provider
  failures); the final `Throwable` arm logs full exception detail and
  surfaces a generic message instead of leaking `$e->getMessage()`
  (which can carry SQL error text or provider response bodies). All
  four controllers gained a `LoggerInterface` constructor parameter
  (autowired by Symfony DI). The `ConfigurationController::testConfigurationAction`
  intentionally still surfaces `ProviderResponseException::getMessage()`
  with the upstream HTTP status — the message is already sanitised
  by `AbstractProvider::sanitizeErrorMessage()` and the frontend toast
  needs the model-specific text to be useful for diagnostics. Unit
  test assertions updated to assert "See system log" instead of the
  raw exception text — verifying the new generic-message contract.

### Removed

- `ProviderAdapterRegistryInterface::registerAdapter()` and the
  matching `ProviderAdapterRegistry::registerAdapter()` public
  mutator have been removed (audit 2026-04-23 REC #3, slice 22).
  The registry now exposes a read-only contract: the adapter map
  is fixed at construction time as the union of the built-in
  `ADAPTER_CLASS_MAP` and an optional `array $adapterOverrides`
  constructor argument (defaults to `[]`; production wiring uses
  the empty default). Custom-adapter / built-in-override registration
  is therefore a constructor concern rather than a runtime
  service-locator call. There were no production callers of the
  removed method (the search yielded only test usages). The
  `customAdapters` private property is gone; the per-call
  "Registered custom adapter" debug log is gone with it (registration
  is now construction-time and side-effect-free for valid input).
  Validation of override classes (must extend `AbstractProvider`)
  still throws `ProviderConfigurationException` — the same exception
  type that was thrown by `registerAdapter()`, raised from the
  constructor instead. The `ProviderAdapterRegistry` class stays
  `final`, public-in-DI (so the backend module can resolve it for
  diagnostics — REC #9c is a separate slice).

### Added

- **REC #15 (audit 2026-04-30):** ADR-026 gains a new
  "Diagnostic / connectivity calls intentionally bypass the pipeline"
  section. Documents the three actual call paths used by the
  test-action controllers — `ProviderController::testConnectionAction`
  goes through `ProviderAdapterRegistry::testProviderConnection()` →
  `ProviderInterface::testConnection()` (with an inline
  `preg_replace` sanitiser that mirrors
  `AbstractProvider::sanitizeErrorMessage()`'s shape but is
  implemented locally so the registry stays independent of the
  provider base class), while
  `ConfigurationController::testConfigurationAction` and
  `ModelController::testModelAction` go through
  `ProviderAdapterRegistry::createAdapterFromModel()` →
  `ProviderInterface::complete()` (sanitisation happens inside the
  adapter via `AbstractProvider::sanitizeErrorMessage()` before the
  `ProviderResponseException` reaches the controller). All three
  bypass `MiddlewarePipeline::run()` deliberately — Budget would
  mis-charge, Usage would distort dashboards, Fallback would mask
  the very condition the probe was designed to detect, and Cache
  would defeat the purpose of probing. Together with streaming
  (already documented in ADR-026 step 5), these three diagnostic
  actions are the documented exemptions from the "productive
  provider calls go through the pipeline" rule. New non-streaming
  productive entry points still go through the pipeline. Pure
  documentation slice — no code change.
- **REC #13 (audit 2026-04-30):** New
  `Tests/Architecture/ServiceLayerTest.php` (phpat) codifies the
  Service-layer rules previously enforced by convention only:
  (1) `Service\*` must not depend on `Controller\*` (reverse-dependency
  guard); (2) `Service\*` must not depend on concrete provider adapter
  classes (`OpenAiProvider`, `ClaudeProvider`, …) — provider invocation
  goes through `Provider\Contract\ProviderInterface` /
  `Provider\Middleware\MiddlewarePipeline` /
  `ProviderAdapterRegistry`, never via direct adapter imports
  (ADR-026). Cross-feature `Service\Feature\*` coupling is still
  convention-guarded — a precise rule is left to a follow-up because
  the obvious form would also forbid each service depending on its
  own `*ServiceInterface` in the same namespace. No code changes were
  required: both new rules pass against the current tree.
- **REC #9c (slice 25):** ADR-028 documents the
  `Configuration/Services.yaml` `public: true` policy. The 37
  current overrides are categorised (public LLM API surface,
  specialized services, test-resolvable repositories, SetupWizard
  collaborators) and each carries a load-bearing reason for being
  public. New `Tests/Unit/Configuration/PublicServicesPolicyTest`
  asserts the count and the ADR's presence — so a future
  `public: true` addition either matches the documented set or the
  PR fails with a prompt to update both the ADR and the test
  expectation. Audit recommendation: "reduce to only those genuinely
  needed". Resolution: the count is the deliberate set, locked in
  the ADR + test rather than mass-reduced (which would break
  ~22 functional tests that resolve repositories/wizard services
  via `$this->get()` — see ADR-028 "Alternative considered").
- `Classes/Domain/DTO/ProviderOptions` — typed value object for
  `Provider::$options` (REC #6 slice 20, closes the typed-DTO follow-up
  to slice 16f). `final readonly class` with three fields: `proxy`
  (`?string`), `customHeaders` (`array<string, string>`), and `extra`
  (`array<string, mixed>`) for everything else. The well-known fields
  cover the transport-level options that real adapters consume today
  (the TCA placeholder is `{"custom_header": "value"}` and existing
  test fixtures use `proxy`); the rest of the open-ended JSON column
  flows through `$extra` so a hand-edited DB row never silently loses
  data. Permissive parsing — `fromArray()` / `fromJson()` drop
  type-mismatched well-known fields rather than throwing; sibling
  helpers (`get()`, `has()`, `withProxy()`, `withCustomHeaders()`,
  `withExtra()`) mirror the read patterns existing
  `getOptionsArray()` callers already use so migration is a straight
  substitution. The DTO is the typed application-level surface; the
  entity still persists JSON to keep Extbase property mapping working
  unchanged.
- `Provider::getOptionsObject(): ProviderOptions` and
  `Provider::setOptionsObject(ProviderOptions): void` — typed accessors
  on the entity (REC #6 slice 20). The DTO is built fresh from the
  persisted JSON on each `get` call (cheap — single `json_decode` plus
  a few key extractions) and never throws on malformed input.
  `setOptionsObject()` collapses an empty DTO to the empty-string
  sentinel `''` rather than persisting `'[]'`, matching how
  `setOptions('')` historically cleared the field. The legacy string
  / array accessors do NOT route through the typed accessor — they
  preserve their pre-REC-#6 behaviour byte-for-byte.
- Five typed Response DTOs for the Setup Wizard backend AJAX
  endpoints (slice 21, REC #5b — closes the audit gap left over
  from the slice-13 `TaskController` split):
  `Response/ProviderDetectionResponse` (wraps a `DetectedProvider`
  for `detectAction`), `Response/WizardTestConnectionResponse`
  (slim `{success, message}` shape for `testAction` — distinct from
  the existing `TestConnectionResponse` that also carries a model
  list), `Response/DiscoveredModelsResponse` (wraps the
  `DiscoveredModel` list from `discoverAction`),
  `Response/GeneratedConfigurationsResponse` (wraps the
  `SuggestedConfiguration` list from `generateAction`), and
  `Response/WizardSaveResponse` (success payload for `saveAction`
  with the persisted-provider summary). Each DTO follows the
  established `final readonly` + `implements JsonSerializable` +
  typed `jsonSerialize()` return shape pattern. The wire shape
  consumed by `Backend/SetupWizard.js` is preserved byte-for-byte
  — every new DTO's `jsonSerialize()` returns exactly the array
  shape the previous inline literal produced.
- `Classes/Specialized/AbstractSpecializedService` — base class for
  every single-task AI service that talks to a provider over HTTP
  (DALL-E, FAL, Whisper, TTS, DeepL — slice 18, REC #7). Concentrates
  the HTTP scaffolding that each service was reimplementing
  separately: extension-config loading (with fail-soft logging),
  availability check, JSON POST, status-code → typed-exception
  mapping (`ServiceConfigurationException` for 401/403,
  `ServiceUnavailableException` for 429 / 5xx / network errors),
  endpoint URL construction. Subclasses declare their identity
  (`getServiceDomain()` / `getServiceProvider()` for exception
  payloads, `getProviderLabel()` for log messages) and the auth
  scheme (`buildAuthHeaders()` — three are in active use today:
  `Bearer ` (OpenAI), `Key ` (FAL), `DeepL-Auth-Key ` (DeepL)).
- `Classes/Specialized/MultipartBodyBuilderTrait` — multipart/form-data
  body construction for services that upload files
  (`WhisperTranscriptionService`, `DallEImageService`). Kept out of
  the base class so JSON-only services don't carry the trait's
  footprint. Pure body builder (`encodeMultipartBody()`) plus a
  full request dispatcher (`sendMultipartRequest()`) that ties into
  the base's `executeRequest()`.

### Changed

- **REC #8b (slice 23a):** Replaced catch-all `catch (Throwable $e)`
  blocks with typed exception handlers across the three Task pathway
  controllers (`TaskExecutionController`, `TaskRecordsController`,
  `TaskWizardController`). Seven catch sites updated. Provider errors
  (`ProviderResponseException`, base `ProviderException`), Doctrine
  DBAL errors, and domain `InvalidArgumentException` now route to
  specific arms with appropriate HTTP statuses; the final `Throwable`
  arm logs full exception detail and surfaces a generic message
  instead of leaking `$e->getMessage()` (which can carry SQL error
  text or provider response bodies). All three controllers gained a
  `LoggerInterface` constructor parameter (autowired by Symfony DI;
  TYPO3 v13's container handles `Psr\Log\LoggerInterface` natively).
  No HTTP-status changes for AJAX paths — `TaskExecutionController`
  keeps its intentional 200-with-`success:false` envelope so the
  frontend `AjaxRequest` can read the JSON.
- **REC #2 (slice 24):** Feature services (`CompletionService`,
  `TranslationService`) now build typed `ChatMessage` VOs at the
  point of construction instead of inline associative arrays.
  `LlmServiceManager` would normalise either shape via
  `ChatMessage::fromArray()`, but typed-from-the-source means
  PHPStan catches role/content drift earlier and the call site is
  self-documenting. The provider/manager interfaces keep accepting
  the `list<ChatMessage|array<string, mixed>>` union for back-compat
  with third-party callers — that's the intentional end-state
  documented on the interface itself. Tests updated to assert
  `instanceof ChatMessage` + `->role` / `->content` field access
  instead of `$messages[0]['role']` array shape.
- `SetupWizardController` (`detectAction`, `testAction`,
  `discoverAction`, `generateAction`, `saveAction`) now returns
  every JSON body through a typed `Response/*` DTO instead of an
  ad-hoc `new JsonResponse([...])` literal. Ten call sites
  migrated total (five success replies + five error/exception
  replies; `ErrorResponse` was reused for every error branch).
  Closes the REC #5 follow-up audit item; brings the wizard
  controller in line with the
  `ConfigurationController` / `ProviderController` /
  post-split task controllers precedent. No behaviour change —
  the AJAX wire format consumed by `Backend/SetupWizard.js` is
  byte-identical to the pre-DTO output. Slice 21.
- `DallEImageService`, `FalImageService`, `WhisperTranscriptionService`,
  `TextToSpeechService`, and `DeepLTranslator` now extend
  `AbstractSpecializedService` instead of carrying their own copies
  of `loadConfiguration()`, `ensureAvailable()`, `executeRequest()`,
  and the auth-header / JSON-POST boilerplate. **Public API is
  unchanged** — every public method on every service keeps its
  signature, and the constructor signature is identical (Symfony DI
  autowires the same dep set as before). Whisper and TTS retain
  their own request-execution path (Whisper because text/srt/vtt
  formats return raw strings, not JSON; TTS because the response
  is binary audio bytes); the rest of the scaffolding still comes
  from the base. Per-service variation points (DALL-E's 400
  validation branch, FAL's 422 branch, DeepL's 456 quota branch,
  FAL's `detail`/`message` error shape, DeepL's top-level `message`
  error shape) override `mapErrorStatus()` / `decodeErrorMessage()`
  hooks on the base. Closes REC #7. Net per-service LOC reduction
  averages ~12% (2828 → 2491 across the five services), but the
  real win is centralisation: a future bug in HTTP error handling
  or auth-header threading lives in one place to fix instead of
  five.
- `ModelSelectionService::modelMatchesCriteria()` now routes capability
  membership through the typed `Model::getCapabilitySet()->has()` instead
  of the legacy string-CSV `Model::hasCapability()`. The legacy strict
  `in_array(... , true)` over `explode(',')` already returned `false`
  for unknown criteria tokens, so the observable outcome is unchanged
  for every previously-valid input. The behavioural delta is in two
  edge cases: capability tokens from external input are now trimmed and
  enum-validated consistently (so `' chat'` resolves the same as
  `'chat'`), and unknown tokens that may exist in the persisted CSV
  (schema drift, removed-but-still-stored capabilities) are dropped at
  parse time rather than matched against an equally-unknown criteria
  string. Coverage:
  `modelMatchesCriteriaTrimsCapabilityTokensFromExternalInput` (the
  trim case) and `modelMatchesCriteriaRejectsUnknownCapabilityToken`
  (documents the no-change-for-unknowns contract). REC #6 slice 16b.

### Deprecated

- `Provider::getOptionsArray(): array<string, mixed>` and
  `setOptionsArray(array<string, mixed>)` are now also deprecated
  since 0.8.0 in favour of the typed
  `getOptionsObject(): ProviderOptions` /
  `setOptionsObject(ProviderOptions)` accessors (REC #6 slice 20 —
  follow-up to slice 16f). Slice 16f had stopped at the array-typed
  surface on the rationale that the `options` column was too
  open-ended for a typed DTO; that argument was reconsidered against
  the parallel `Capabilities`/`FallbackChain`/`ModelSelectionCriteria`
  DTOs and the small but well-defined transport keys actually used
  in production (TCA placeholder `{"custom_header": "value"}`, test
  fixtures `proxy`, `custom_param`). The new `ProviderOptions` DTO
  types those well-known keys (`proxy`, `customHeaders`) and routes
  everything else through an `extra: array<string, mixed>` bag so no
  existing data is lost. The array accessor is retained for
  back-compat with the `ProviderAdapterRegistry::buildAdapterConfig()`
  call site that merges it into the adapter-init config; that call
  site will migrate in a follow-up slice. The string and array
  accessors will not be removed before a major version bump.
- `Provider::getOptions(): string` and `setOptions(string)` are
  deprecated since 0.8.0 in favour of the typed
  `getOptionsObject(): ProviderOptions` /
  `setOptionsObject(ProviderOptions)` accessors (REC #6 slice 20,
  updated rationale from slice 16f). The legacy raw-JSON methods
  remain for Extbase property mapping (the framework hydrates the
  entity through this getter / setter pair) and will not be removed
  before a major version bump. REC #6 slice 16f — superseded by
  slice 20.
- `LlmConfiguration::getOptions(): string` and `setOptions(string)` are
  deprecated since 0.8.0 in favour of the typed
  `getOptionsArray(): array<string, mixed>` /
  `setOptionsArray(array<string, mixed>)` accessors. The `options`
  field carries provider-specific extras beyond the typed entity
  columns (`temperature`, `maxTokens`, `topP`, `frequencyPenalty`,
  `presencePenalty`, `systemPrompt`, …) — its shape is open-ended by
  design and varies per provider, so REC #6 stops at the array-typed
  surface rather than introducing a typed DTO that would impose
  false structure. The legacy raw-JSON methods remain for Extbase
  property mapping (the framework hydrates the entity through this
  getter / setter pair) and will not be removed before a major
  version bump. REC #6 slice 16e.
- `LlmConfiguration::getModelSelectionCriteria(): string` and
  `setModelSelectionCriteria(string)` are deprecated since 0.8.0 in
  favour of the typed `getModelSelectionCriteriaDTO(): ModelSelectionCriteria` /
  `setModelSelectionCriteriaDTO(ModelSelectionCriteria)` accessors
  (the typed `ModelSelectionCriteria` DTO has lived in
  `Classes/Domain/DTO/` for a while and is the documented
  application-level surface). The legacy methods remain for Extbase
  property mapping (the framework hydrates the entity through this
  getter / setter pair) and will not be removed before a major
  version bump. Production callers that consume the array shape
  (`ModelSelectionService::resolveModel()` via
  `getModelSelectionCriteriaArray()`) are NOT migrated in this slice
  — `findMatchingModel(array $criteria)` keeps its array signature
  for now; a future slice can adopt the typed DTO end-to-end. REC #6
  slice 16d.
- `LlmConfiguration::getFallbackChain(): string` and
  `setFallbackChain(string)` are deprecated since 0.8.0 in favour of
  the typed `getFallbackChainDTO(): FallbackChain` /
  `setFallbackChainDTO(FallbackChain)` accessors (the typed `FallbackChain`
  DTO has lived in `Classes/Domain/DTO/` since the middleware-pipeline
  rework — see ADR-026 — and every production caller already routes
  through it; the slice's only delta is to nudge new application code
  off the raw JSON string surface). The legacy methods remain for
  Extbase property mapping (the framework hydrates the entity through
  this getter / setter pair) and will not be removed before a major
  version bump. REC #6 slice 16c.
- `Model::getCapabilities()`, `getCapabilitiesArray()`,
  `getCapabilitiesAsEnums()`, `setCapabilities()`,
  `setCapabilitiesArray()`, `hasCapability()`, `addCapability()`,
  `removeCapability()` are deprecated since 0.8.0 in favour of
  `getCapabilitySet()` / `setCapabilitySet()` (typed
  `Domain\DTO\CapabilitySet`). The legacy accessors remain functional
  and are not removed before a major version bump — TCA-driven
  persistence still hands the entity raw CSV strings, and the
  duplicate-preserving semantics of the legacy accessors
  (relevant when callers iterate the CSV directly) are kept
  byte-for-byte. REC #6 slice 16b.

### Added

- `Domain/DTO/CapabilitySet` — typed value object wrapping a deduplicated,
  order-preserving `list<ModelCapability>` for the model's capability set.
  `Model` gains `getCapabilitySet(): CapabilitySet` and
  `setCapabilitySet(CapabilitySet)` accessors; the legacy
  `getCapabilities()` / `getCapabilitiesArray()` / `getCapabilitiesAsEnums()`
  / `setCapabilities()` / `setCapabilitiesArray()` accessors remain
  byte-for-byte unchanged (they do NOT route through the new DTO so
  duplicate-preserving semantics survive intact). CSV serialisation of
  the entity field is unchanged (`Model::$capabilities` stays `string`);
  the DTO is the typed runtime representation. Slice 16a of REC #6;
  slice 16b will migrate callers to the typed accessors. The DTO's
  factories `fromCsv()` and `fromArray()` defensively drop unknown
  tokens (schema drift, stray whitespace via trim) so an old DB row
  carrying a capability that has since been removed from the enum
  cannot crash readers. Token matching is case-sensitive — the
  persisted CSV is always lowercase (TCA `eval=trim,lower`).
- `CHANGELOG.md`, `CODEOWNERS`, GitHub issue templates (bug report, feature request).
- External JavaScript files for the Test and WizardChainPreview backend templates
  (replaces inline `<script>` tags to satisfy Content Security Policy).
- Canonical sections in `AGENTS.md` (Commands, Testing, Development Workflow,
  Architecture, File Map, Critical Constraints, Heuristics, Shared Utilities,
  Golden Samples).
- `Service/Budget/BackendUserContextResolverInterface` (with default
  implementation `BackendUserContextResolver`) — single seam for resolving
  the active TYPO3 backend user uid out of `$GLOBALS['BE_USER']`. The
  resolver returns `null` (rather than `0`) when no BE user is in scope
  so `BudgetMiddleware`'s "skip the check" branch fires for CLI /
  scheduler / FE callers without faking an unauthenticated principal.
  `CompletionService`, `EmbeddingService`, `VisionService` and
  `TranslationService` inject the resolver and auto-populate
  `beUserUid` on their respective options when the caller did not set
  one — slices 15a (`CompletionService`) and 15b (`EmbeddingService` /
  `VisionService` / `TranslationService`) of REC #4 (automatic budget
  pre-flight wiring across all feature services).
  `ChatOptions` (and by extension `ToolOptions`) gained typed
  `beUserUid` / `plannedCost` fields with `withBeUserUid()` /
  `withPlannedCost()` setters; slice 15b extends the same fields to
  `EmbeddingOptions`, `VisionOptions` and `TranslationOptions`.
  `LlmServiceManager::chat()` / `complete()` / `chatWithTools()` /
  `embed()` / `vision()` translate the values into
  `BudgetMiddleware::METADATA_BE_USER_UID` /
  `METADATA_PLANNED_COST` on the `ProviderCallContext` so the existing
  middleware reads them without changes; the helper
  `buildBudgetMetadata()` takes raw nullable values rather than a
  typed option object so every option type can reuse it without a
  marker interface. Fields are deliberately kept off every option
  type's `toArray()` — they are pipeline metadata, not provider-side
  options, and must never reach the provider wire payload.
  `TranslationService` is the only service that builds `ChatOptions`
  internally (translate / detectLanguage / scoreTranslationQuality);
  each construction site forwards `beUserUid` (resolver-resolved or
  explicit) and `plannedCost` so the BudgetMiddleware sees them.
  Specialized translators (DeepL et al.) bypass `LlmServiceManager`
  entirely and are not subject to BudgetMiddleware in this slice.

### Changed

- `Build/captainhook.json` is now the documented default location for git
  hooks (configured via `composer.json` `extra.captainhook.config`).
- `Makefile` test/quality targets delegate to `Build/Scripts/runTests.sh -s
  <suite>` instead of invoking PHPUnit / PHPStan / Rector directly.
- `Build/FunctionalTests.xml` testsuite names normalised to `functional` and
  `e2e-backend` (lowercase, conventional).
- E2E test fixtures use vault-UUID-style placeholders or runtime-built
  prefix concatenations rather than literal API-key strings.
- `TaskController` no longer carries inline SQL or filesystem reads.
  The eight `ConnectionPool` / `QueryBuilder` call sites and the
  `var/log/typo3_deprecations.log` read move to three new
  reader services under `Classes/Service/Task/`:

  - `RecordTableReader` (with `RecordTableReaderInterface`) — owns
    the schema-introspection + query work for the record-picker
    (list allowed tables, format table label, detect label field,
    fetch a record sample, load records by uid, fetch all rows for
    the table-input branch).
  - `SystemLogReader` (with `SystemLogReaderInterface`) — wraps
    the `sys_log` query used by the syslog input branch.
  - `DeprecationLogReader` (with `DeprecationLogReaderInterface`) —
    wraps the deprecation-log filesystem read.

  `TaskController`'s constructor loses the `ConnectionPool` and
  `TcaSchemaFactory` dependencies; it now injects the three reader
  interfaces. Behaviour is unchanged. This is slice 13a of the
  `TaskController` split (ADR-027).
- `TaskController` no longer carries the input-source dispatch logic.
  The `getInputData()` / `getSyslogData()` / `getTableData()` private
  helpers move into a new `Service/Task/TaskInputResolver` (with
  `TaskInputResolverInterface`). The resolver owns the `Task::INPUT_*`
  match plus the per-source formatting (timestamp + type-label
  localisation for syslog rows, "no table configured" / "read failed"
  placeholders for the table source) and delegates the actual data
  fetching to the slice-13a reader services. The controller's
  `getInputData()` becomes a single delegation; the `SystemLogReader`
  and `DeprecationLogReader` are no longer injected directly into the
  controller (the resolver owns them). Behaviour is unchanged. Slice
  13b of the `TaskController` split (ADR-027).
- `TaskController::executeAction()` no longer carries the LLM
  orchestration logic. Prompt building, configuration lookup, and
  dispatch to `LlmServiceManager` move into a new
  `Service/Task/TaskExecutionService` (with
  `TaskExecutionServiceInterface`). The service returns a typed
  `TaskExecutionResult` (`content`, `model`, `outputFormat`, `usage`)
  rather than a `CompletionResponse` so future Task-specific fields
  can attach without leaking into the LLM abstraction. The controller
  loses its direct `LlmServiceManagerInterface` injection (the service
  owns it now); the new service is the natural seam for the future
  REC #4 budget pre-flight, with the hook point documented in the
  service's class docblock. Behaviour is unchanged. Slice 13c of the
  `TaskController` split (ADR-027).
- `ProviderResponseException` carries typed `httpStatus`,
  `responseBody`, and `endpoint` properties so callers can branch on
  the actual HTTP semantics rather than re-parsing the message string.
  The previous positional constructor signature
  `(string $message, int $httpStatus = 0, ?Throwable $previous = null)`
  is preserved verbatim — the new `responseBody` and `endpoint`
  fields are appended after `$previous`, so existing callers writing
  `new ProviderResponseException($msg, $status, $previous)` keep
  working without silent type confusion. New callers populate the
  typed fields by name. Production call sites
  (`AbstractProvider::sendRequest()`, `OpenRouterProvider::handleOpenRouterError()`)
  populate the new fields; OpenRouter's handler now also receives the
  actual endpoint so non-`chat/completions` calls (e.g. `embeddings`)
  carry correct metadata. The `endpoint` field is sanitised before
  storage — any query string is stripped so providers like Gemini
  (which embed the API key as `?key=<secret>`) cannot leak
  credentials through exception logging or telemetry. Demonstrated
  the new typed-catch pattern in
  `ConfigurationController::testConfigurationAction()`, which now
  catches `ProviderResponseException` ahead of the generic
  `Throwable` and surfaces the upstream HTTP status as the AJAX
  response status (was always 500). REC #8 from the audit.
- `TaskController` is split into four per-pathway controllers,
  closing REC #5 and the entire ADR-027 work:

  - `TaskListController` (135 LOC, 4 deps) — `list`.
  - `TaskWizardController` (270 LOC, 9 deps) — `wizardForm`,
    `wizardGenerate`, `wizardGenerateChain`, `wizardCreate`.
  - `TaskExecutionController` (210 LOC, 8 deps) — `executeForm`,
    `executeAction`, `refreshInputAction`.
  - `TaskRecordsController` (135 LOC, 1 dep) — `listTablesAction`,
    `fetchRecordsAction`, `loadRecordDataAction`.

  AJAX route identifiers and paths are unchanged; only the route
  `target:` field repoints to the new controllers, so the JS
  frontend (resolved via `PageRenderer::addInlineSettingArray`)
  needs no update. Backend module identifier `nrllm_tasks` is
  unchanged; `controllerActions` now distributes the action names
  across the three render-controllers. The original
  `Controller/Backend/TaskController.php` is removed. Slice 13e
  of the `TaskController` split (ADR-027), and the closure of the
  audit's REC #5.
- Every Task AJAX action now returns a typed `Response/*` DTO instead
  of a raw `JsonResponse([...])` literal — five new responses join the
  existing `ConfigurationController` / `ProviderController` precedent:
  `TableListResponse` (picker dropdown), `RecordListResponse` (picker
  fetch), `RecordDataResponse` (picker load by uid),
  `TaskExecutionResponse` (execute success; static `fromResult()`
  factory adapts the service-layer `TaskExecutionResult`),
  `TaskInputResponse` (refresh-input). All error branches now use the
  existing `ErrorResponse`. The wire shape consumed by
  `Backend/TaskExecute.js` and friends is preserved byte-for-byte.
  Slice 13d of the controller split (ADR-027); after slice 13e these
  actions live on `TaskExecutionController` and `TaskRecordsController`.
- Specialized translators register via the new `#[AsTranslator]` marker
  attribute, mirroring the `#[AsLlmProvider]` pattern used for LLM
  providers. The attribute carries no fields — translator identifier
  comes from `TranslatorInterface::getIdentifier()` (existing) and
  registration order from the new `TranslatorInterface::getPriority()`
  method (used by Symfony's `#[TaggedIterator(defaultPriorityMethod:
  'getPriority')]` in `TranslatorRegistry`). `TranslatorCompilerPass`
  auto-tags matching services so the existing `Services.yaml` `tags:`
  entries on `LlmTranslator` / `DeepLTranslator` are no longer needed
  (and were removed). Third-party translators outside the
  `Netresearch\NrLlm\Specialized\Translation\` namespace can keep using
  the legacy yaml-tag path; both mechanisms remain supported.

### Fixed

- `Resources/Public/Icons/Extension.svg` brand colour corrected to the official
  Netresearch teal `#2F99A4` (was `#2999a4` typo).

### BREAKING

- The eight `Model::CAPABILITY_*` legacy public class constants
  (`CAPABILITY_CHAT`, `CAPABILITY_COMPLETION`, `CAPABILITY_EMBEDDINGS`,
  `CAPABILITY_VISION`, `CAPABILITY_STREAMING`, `CAPABILITY_TOOLS`,
  `CAPABILITY_JSON_MODE`, `CAPABILITY_AUDIO`) have been REMOVED. They
  have been marked `@deprecated` since the introduction of the
  `Domain\Enum\ModelCapability` backed enum, and the architecture
  audit (REC #10) flagged the parallel-truths state as a structural
  debt to clear. Downstream consumers must migrate references to the
  enum value: `Model::CAPABILITY_CHAT` → `ModelCapability::CHAT->value`
  (or pass the enum directly anywhere that accepts
  `string|ModelCapability` — e.g. `CapabilitySet::has()`,
  `with()`, `without()`). The `Model::getAllCapabilities()` static
  helper (used by `ModelController` to populate the BE list view's
  capability label dropdown) is unchanged — it is keyed on the enum
  values, not on the removed constants. REC #10.
- The following classes are now `final` (and `final readonly` where applicable)
  and can no longer be subclassed by downstream extensions: the four leaf
  provider exceptions (`ProviderConfigurationException`,
  `ProviderConnectionException`, `ProviderResponseException`,
  `UnsupportedFeatureException`); the four feature services
  (`Service/Feature/CompletionService`, `EmbeddingService`,
  `TranslationService`, `VisionService`); the two supporting services
  (`Service/ModelSelectionService`, `Service/PromptTemplateService`).
  Downstream consumers that extended any of these classes should switch to
  composition or open an issue if a documented extension point is needed.
  The base `ProviderException` is the only deliberately non-final class
  remaining (it parents the leaf exceptions); `LlmConfigurationService` and
  `BudgetService` are still non-final pending the same interface-extract
  pattern applied to the registry below.
- `ProviderAdapterRegistry` is now `final` and implements the new
  `ProviderAdapterRegistryInterface`. Downstream consumers that
  constructor-injected the concrete class should typehint the interface
  instead. The Symfony alias
  `ProviderAdapterRegistryInterface → ProviderAdapterRegistry` is wired
  in `Configuration/Services.yaml` so existing autowiring keeps working.
- `BudgetService` is now `final readonly` and implements the new
  `BudgetServiceInterface`. The DB-aggregation step previously embedded
  in the service's `aggregateWindowUsage()` method moved to a separate
  collaborator: `Service/Budget/UserBudgetUsageWindows` implementing
  `BudgetUsageWindowsInterface`. `BudgetService::__construct()` now
  takes `(UserBudgetRepository, BudgetUsageWindowsInterface)` rather
  than `(UserBudgetRepository, ConnectionPool)`. Symfony aliases
  `BudgetServiceInterface → BudgetService` and
  `BudgetUsageWindowsInterface → UserBudgetUsageWindows` keep autowiring
  transparent for callers that injected via `BudgetService`. Direct
  instantiation (rare) needs the new constructor signature.
- `LlmConfigurationService` is now `final readonly` and implements the
  new `LlmConfigurationServiceInterface`. Same migration story as the
  registry above: typehint the interface in constructor injection; the
  `LlmConfigurationServiceInterface → LlmConfigurationService` Symfony
  alias keeps autowiring transparent.

## [0.7.0] - 2026-04-22

Initial public release. See git history for prior commits.

[Unreleased]: https://github.com/netresearch/t3x-nr-llm/compare/v0.28.0...HEAD
[0.28.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.27.0...v0.28.0
[0.27.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.26.0...v0.27.0
[0.26.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.25.1...v0.26.0
[0.25.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.25.0...v0.25.1
[0.25.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.24.0...v0.25.0
[0.24.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.23.1...v0.24.0
[0.23.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.23.0...v0.23.1
[0.23.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.16.1...v0.17.0
[0.16.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.16.0...v0.16.1
[0.16.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.14.1...v0.15.0
[0.14.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.11.1...v0.12.0
[0.11.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.11.0...v0.11.1
[0.11.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.7.0
