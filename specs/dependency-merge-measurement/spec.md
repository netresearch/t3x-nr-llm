# Single-use dependency merge observation

## Objective

Measure the existing event-driven controller on one genuine Renovate update
without changing the current dependency merger or any release qualification.
This installs observation capability; it does not establish native adoption,
readiness, spare API capacity or a productive replacement of the legacy merger.

## Episode and trusted entry

The selected PR is #1197, branch `renovate/lock-file-maintenance`, in this
repository targeting `main`. The intent caller accepts only a later genuine
`pull_request_target/synchronize` event from Renovate on that PR and branch.
Its event, head and base repositories must match this repository. The bot
author must be `renovate[bot]`; the originating actor must have Renovate's
immutable account ID `29139614` (`github.actor_id`, a string). Actor usernames
do not authorize entry.

The intent trigger also requires either `Build/decision/uv.lock` or
`Build/reranker/uv.lock`, the selected PR's complete observed file inventory.
This restricts which synchronizations can consume the first workflow number.
Another PR changing the same files can still consume it; a future candidate
diff that misses both paths is inconclusive. No future event is guaranteed.

The separate evaluator accepts only a successful, first-attempt `CI`
completion with producer event `pull_request` on the same exact branch.
The shared controller resolves the unique current PR, requires the genuine
successful intent producer and validates immutable artifact origin, PR/head,
observation mode, exact caller paths, digest and lifetime. The guarded producer
can create a valid episode intent only for #1197. Branch association alone never
authorizes readiness or a queue operation.

Both new caller identities require canonical `github.run_number == '1'` and
`github.run_attempt == '1'`. They remain permanently single-use. A nonmatching
first event may consume the number; the evaluator may arrive before a usable
intent. Such outcomes are inconclusive. No dispatch, bot command, rebase,
reopen, rerun, renamed workflow or relaxed guard may manufacture success.

## Preserved duties

Both thin callers use the existing organization reusable at `@main`, with
`observe-only: true`, its full declared permissions and no App secrets or PR
checkout. The runtime prohibits every PR/queue mutation. The existing
`auto-merge-deps.yml` remains byte-identical.

Consumer metadata requests exact-pin drift with `strict-pins: true`. Before
publishing this caller, the independently reviewed strict-mode source must be
qualified on actual main, with its required combined-tree fixture successful
before source merge. Validate the complete consumer against that source and
the current canonical template, including a temporary same-action full-SHA
negative. Existing metadata template and intentional-drift declarations remain
unchanged; pin-only notices do not satisfy this strict contract.

The identical inline version 2 policies retain all 17 unconditional and 6
conditional producer identities, real Actions integration IDs, path selectors
and exact caller blob identities. Minimum approvals remains 1; all 14 native
required contexts, reviews, signatures, threads, pending/failed additional
checks and source/head/intent restrictions remain mandatory. Trusted completion
hints do not replace any duty. Conditional absence requires the source's full
immutable comparison and matching base/head blobs.

No review, metadata, legacy-completion, intent-completion or self-completion
subscription is installed for this measurement. The eventual full observer
and production cutover require separate evidence and reviewed changes.

## Finite measurement exposure

The policy includes `observer_api_attempt_limit: 64`. The accepted source
must validate it before the first API request, allow it only for version 2
observation, share the existing counter across context, intent and snapshots,
and deny the next attempt before opening request 65. Exhaustion records a
blocked receipt, never partial readiness or successful prepare intent.

At most two invocations permit at most 128 joint controller GitHub API attempts.
This counts REST and GraphQL requests together, not GraphQL points, successful
responses, available quota or all network traffic. The current source permits
at most two initial signed ZIP follow-ups; these are outside that counter.
Renovate skips the Dependabot metadata step. At most three pinned artifact
uploader invocations may run; their service requests/retries and setup or
hardening traffic are separate. No token fallback, new query, polling or paid
capacity is introduced.

Initial remaining budget and unrelated CI demand are unknown. This finite
diagnostic exposure is admitted without asserting spare capacity. Preserve
the actual safe resource/limit/remaining/used/reset samples and attempt counts;
concurrent used deltas do not attribute GraphQL query cost to this controller.
An API-limit, ceiling or missing-header outcome remains explicit. It does not
justify an automatic retry, larger ceiling or broader subscription.

## Acceptance and stopping

Before publication, qualify the actual compact-CI main, readiness/identity/
header source main and observer-ceiling source main. Refresh full protection,
producer identities, conditional blobs and selected-candidate eligibility.
Independently review the exact signed diff; run normal workflow/changelog/
repository checks and the single mandatory local gate. Offline scheduling
tests must evaluate the actual expressions, including first-run/attempt,
selected PR/branch/repository/actor, wrong producer/event/attempt/conclusion,
policy/digest/mode/path boundaries and unchanged legacy bytes.

Qualify the consumer's real PR, protected queue, exact merge tree and main,
including late analyses, annotations and complete run/job/attempt costs.
Installation evidence is separate from a later natural episode. A successful
job with a blocked decision is not readiness. Readiness needs the actual
trusted handoff, complete current checks/reviews and both fresh full predicates,
with zero PR/queue writes; strict `observed-ready-existing` is sufficient only
with its complete evidence and existing-queue fact.

Stop active waiting when the episode ends without readiness, a single-use
guard is consumed, the selected head changes again, the PR closes or intent
expires. Without a genuine event, report installation-qualified and native
adoption pending. Retire the closed caller identities through a reviewed
change after the episode. Any later episode, full observer or productive
cutover requires its own reviewed capacity decision and genuine matching
intent; observer artifacts never authorize production enqueueing.
