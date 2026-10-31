# Compact CI without reduced qualification

This delivery changes CI orchestration and required producer identities. It
changes no application API, compatibility range, version, product PHP or test
threshold. Organization reusables continue to track `@main`; adoption requires
their coverage-selector support to be merged and main-qualified first.

## Executable requirements and acceptance

- Retain PHP8.2–8.5×TYPO3^13.4/^14.3 on full events and PHP8.2/8.4×both on the
  merge queue. Existing VersionConsistency unit tests and native cell inventory
  must prove the matrices. Run the unit suite with `Build/Scripts/runTests.sh -s
  unit` and the owning shared `Build/Scripts/check-compact-ci.sh` matrix cases.
  No test, suite or exclusion is removed.
- Run complete unit and functional suites in every compact cell. Preserve the
  exact existing parallel functional command, class/shard/assertion guards and
  positive covered-project-statements check. Extracted actual-command negative
  probes must reject well-formed zero-coverage XML; declared coverage-off cells
  must still run the complete functional suite. The proving suites are the
  shared compact command/verdict cases and the actual `ci / Tests` unit and
  functional steps, including the retained parallel runner's failure guards.
- Unit coverage stays enabled for every cell. Functional coverage stays on
  PHP8.4 for PR/MQ/full main fallback and on all configured versions for
  schedule/manual. Explicit selector configuration, never file absence, chooses
  off. The shared executable planner/report/real-collector regressions and
  actual native per-cell reports/flags prove this contract. The executable
  shared suite is `Build/Scripts/check-compact-ci.sh`, including its real
  pinned Codecov collector cases; coverage-off never exempts functional tests.
- Preserve Rector PHP8.2, all separate PHPStan checks, repo checks, timeouts,
  permissions/secrets, fuzz, schedule/manual mutation and its current targets.
  The separate MariaDB11.8 PHP8.4/^14.3 full parallel suite stays unchanged
  without coverage. `make gate`, `composer ci:test:workflows` and the native
  principal/MariaDB/fuzz jobs prove the retained suite execution. Whole-workflow
  semantic comparison also requires equality after only the two new inputs.

## Operational rollout checklist

These mandatory publication, administrative and hosted-state checks remain
separate from the executable test-suite contract above.

- Preserve every current security/review/signature/thread/queue obligation.
  Eight unchanged native contexts remain; six real compact/server producer
  contexts replace the eight obsolete leaf/placeholder names. The new 14-context
  set keeps concrete PHP 8.4 combined cells, compact Quality, principal aggregate,
  actual MariaDB test and its aggregate mandatory. No aliases or success bridge.
  Mapping review and negative source gate probes prove both suites/families
  cannot be skipped accidentally. Actual native names/App15368 must match before
  any required-context update. Review minimum remains one.
- Publish only after mandatory local `make gate`, workflow validation, exact
  diff review and upstream source main qualification. The gate covers one local
  environment; current-head native PR/MQ/main qualify the actual event matrices.
  Any local runtime/dependency limitation is recorded separately from success.
- Immediately before a rule update, compare the full frozen effective/classic
  baseline and inspect the queue/open-head compatibility. Drain unrelated legacy
  groups, preserve every other rule field, read back exact changes and guard
  against a raced-in incompatible group with restoration of only this task's
  original context-list delta. Never cancel/bypass unrelated queue entries.
- Native protected merge and actual merge-SHA main qualification must prove
  source/tree/parents/signatures, complete workflows and report inventories.
  Count every attempt and real allocation; smaller graphs do not guarantee an
  elapsed PR-to-release percentage. Existing main reuse is not a new saving.

No tags, publishing runs, paid capacity, fabricated bot/schedule events or
unrelated dependency updates form part of the acceptance test. The separate
dependency-controller migration requires its own observation and real trusted
bot-origin proof; this caller change does not claim that adoption.
