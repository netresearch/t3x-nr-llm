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
author must be `renovate[bot]`. The original event's provider sender must have
type `Bot` and numeric immutable account ID `29139614`, verified with
`toJSON(github.event.sender.id) == '29139614'`. Missing, string-valued, ghost or
differently typed sender identities fail closed, with no actor fallback.
Mutable sender and actor usernames do not authorize entry. This contract does
not assume universal actor/sender equality or establish a native event.

The intent trigger also requires either `Build/decision/uv.lock` or
`Build/reranker/uv.lock`, the selected PR's complete observed file inventory.
This restricts which synchronizations can consume the first workflow number.
Another PR changing the same files can still consume it; a future candidate
diff that misses both paths is inconclusive. No future event is guaranteed.

The separate evaluator accepts only a successful, first-attempt `CI`
completion with producer event `pull_request` on the same exact branch. Its
producer head repository must match this repository; a foreign, missing or null
head repository fails closed before invoking the shared controller.
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

The owning shared-source suite
`.github/actions/dependency-merge-controller/test_observer_budget.py` includes
these existing counter and receipt oracles:

- `test_exact64_mixed_requests_deny65_before_request_or_opener_construction`
- `test_late_closing_exhaustion_blocks_despite_an_earlier_ready_snapshot`
- `test_exhausted_prepare_emits_no_successful_intent_eligibility`

They run through the required source fixture discovery. Consumer scheduling
fixtures do not replace these runtime budget and receipt checks.

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
In a received-response sample, each absent or invalid `resource`, `limit`,
`remaining`, `used` or `reset` field is JSON `null`; a measured numeric `0`
remains `0`. No sample is recorded when no API response was received. The
existing shared-source `test_rate_limits.py` verifies these field and
no-response boundaries.
An API-limit, ceiling or missing-header outcome remains explicit. It does not
justify an automatic retry, larger ceiling or broader subscription.

## Verification suites

The owning `netresearch/.github` source runs these existing commands in its
required `Dependency controller policy fixtures` check:

```bash
python3 -m unittest discover \
  -s .github/actions/dependency-merge-controller -p 'test_*.py' -v
bash .github/actions/dependency-merge-controller/test-permissions.sh
python3 -m unittest discover -s scripts -p 'test_drift_compare*.py' -v
```

Controller suites use `.github/actions/dependency-merge-controller/` in that
source repository; drift suites use `scripts/`.

| Required behavior | Existing proving suite |
| --- | --- |
| Trusted current bot origin | `test_bot_identity.py` |
| Bound producer handoff | `test_handoff.py` |
| Fresh intent, reviews and head races | `test_policy.py` |
| Complete immutable conditional applicability | `test_applicability.py` |
| Fresh observer predicates and zero mutations | `test_observer_readiness.py` |
| Shared attempt ceiling and blocked receipts | `test_observer_budget.py` |
| Missing header fields versus measured zero | `test_rate_limits.py` |
| Strict metadata and pin drift | `scripts/test_drift_compare*.py` |

Consumer checks run from this repository:

```bash
composer ci:test:repo
actionlint
make gate
```

`composer ci:test:repo` checks agent documentation, landing ADRs, changelog
fragments and workflow ownership; `actionlint` checks the actual callers.
`make gate` is the mandatory six-suite PHP implementation gate plus changelog
check. Its PHP suites do not execute GitHub job predicates or establish a
native observation episode.

The retained rollout artifact `check-one-shot-caller.mjs` is an external
123-case oracle using official `@actions/expressions` 0.3.61, outside this
consumer repository. It evaluates the actual caller expressions for first
run/attempt, selected PR/branch/repository/provider sender, producer
event/attempt/conclusion/head repository. It also checks policy/digest/mode/path
boundaries and unchanged legacy bytes. Preserve its script hash, caller blobs
and result with the exact-diff review; it is not a versioned consumer suite.

## Operational rollout checks

These checks require SHA-bound deployment receipts in addition to the suites
above. Before publication:

- Qualify actual compact-CI, readiness/identity/header and observer-ceiling
  source main commits. For the strict-mode source, record its required
  fixture's successful combined-tree SHA and completion before actual merge,
  as required above. Record the strict comparator's canonical-consumer pass
  and temporary same-action
  full-SHA negative, bound to the qualified source and template.
- Refresh the complete protection objects, producer identities, conditional
  blobs and selected-candidate eligibility. Independently review the exact
  signed diff, commit signatures and prospective merge tree with their SHAs.

Before installation acceptance, retain the consumer's actual PR, protected
queue, merge commit and main receipts: complete check and review pages,
resolved threads, actual source SHAs, verified signatures and tree/parents,
late analyses and every annotation. Preserve complete all-event workflow,
job and attempt inventories with actual positive runner IDs and start/end
times for cost calculations. The protected queue must validate its actual
current combined tree. Local suite success cannot establish those native
states.

## Natural episode and stopping

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
