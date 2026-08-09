.. _adr-137:

===============================================================
ADR-137: One candidate resolution for the primary's chain
===============================================================

:Status: Accepted
:Date: 2026-08-09

Context
=======

The walk over a primary configuration's fallback chain existed twice:
:php:`Provider\Middleware\FallbackMiddleware` for pipelined calls and
:php:`Service\Streaming\StreamingDispatcher` for streamed ones. Both applied
the same four rules from ADR-021 — shallow, no self-retry, missing entry
skipped, inactive entry skipped — from two separate pieces of code, so a fix
to one was not a fix to the other.

The duplication is not free. :php:`Service\Tool\TrustZoneResolver` walks the
same chain to derive the data-class ceiling for tools (ADR-094). That ceiling
is only sound while the set a call may actually reach stays inside the set the
ceiling considered. Two independent loops are two chances for that to stop
being true.

Not every difference between the two was duplication, though. The
health-aware reorder (ADR-063) applies to the pipelined path only, and the
streaming path opens the primary itself while the middleware receives it
already attempted.

Decision
========

The candidate loop moves into one class,
:php:`Provider\Fallback\FallbackCandidateResolver`, marked ``@internal``. It
owns the four ADR-021 rules and nothing else:

1. **It resolves, it does not order.** The caller hands in the chain it wants
   walked. The health reorder stays in :php:`FallbackMiddleware`, where its
   single caller is; streaming keeps the configured order. No
   ``RoutingPolicyInterface`` is introduced —
   :php:`ProviderHealthServiceInterface::reorder()` has exactly one caller
   repo-wide, and an interface with one implementation and one reader is a
   declaration nobody reads.
2. **It does not own the primary.** :php:`chainFor()` removes the primary's
   own identifier from its chain; whether the primary is itself a candidate is
   the caller's business. The middleware's primary has already run in the
   pipeline; the dispatcher prepends it because it still has to open it.
3. **It does not log.** A skipped entry is reported to the caller through a
   callback carrying the identifier and a :php:`FallbackSkipReason`. The
   middleware words the two reasons separately, the dispatcher collapses them
   into one line; merging the rules must not rewrite either log surface.
4. **It resolves lazily.** Each entry is looked up when the caller asks for
   it, so an entry behind the one that served is never queried.

:php:`TrustZoneResolver` is **not** touched. Its optional repository argument
is the fail-closed path: without a repository every chain entry resolves to
:php:`null` and the zone falls to ``EXTERNAL_GLOBAL``, the most restrictive
ceiling. Making it mandatory would trade that safety for symmetry and break
six test construction sites.

The invariant that ties the two together — the set either path attempts is
always a subset of the raw chain :php:`TrustZoneResolver::zoneFor()` walks —
is pinned as a test
(:file:`Tests/Unit/Provider/Fallback/CandidateResolutionTest.php`), not as a
shared class. A shared class would have to own both the ceiling and the
routing, coupling a security decision to a retry policy.

Consequences
============

- Both call sites take the resolver instead of
  :php:`LlmConfigurationRepository`; neither queries configurations itself
  anymore.
- The two deliberate differences are now asserted per path, including a
  reflection assertion that the dispatcher has no health-service dependency —
  wiring one in is a routing change and fails that test.
- Streaming no longer resolves the whole chain up front. When an early
  candidate serves, later entries are no longer looked up and a broken entry
  behind it no longer produces a skip warning. The pipelined path already
  behaved this way; the eager resolution was an artefact of building an array,
  not a decision. The trade-off is that a typo'd chain entry is now reported
  only once the primary fails, so it is pinned as a dispatcher-level test
  (:php:`theStreamingPathLooksUpNoChainEntryWhileThePrimaryServes`) — going
  back to an eagerly built array fails it.
- ADR-021's rules now have exactly one implementation. A change to them is a
  change to one class, and both paths inherit it.
