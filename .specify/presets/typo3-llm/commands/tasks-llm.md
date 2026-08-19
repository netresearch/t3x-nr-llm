## AI feature obligations — task stage

Each is its own task. They are the ones that get folded into "implement the feature" and then never happen.

- **A test per fallback branch**, including the one where no candidate had the capability. The happy path is the branch that gets tested; the degraded path is the one that ships broken.
- **A test asserting the caller can tell which path answered.** If the specification promised that distinction, it needs an assertion, or the promise is prose.
- **A test that an absent measurement stays absent.** Assert against the stored row: `NULL` where the provider reported nothing, and not the zero that a coalescing default turns it into. Write it before the implementation — it is the one that fails for the right reason today.
- **A test at every point untrusted output crosses a boundary** the plan named. One per point, because a single test at the first boundary is what makes the second one look covered.
- **An assertion for the invariant rather than the wording.** Schema, required fields, range, refusal. If a test needs a fixed completion to pass, note what it therefore does not prove.
- **A task for what a real provider run would tell you that mocks cannot**, even if the answer is "run it once by hand and record the result in the PR". A feature verified only against a mocked provider is verified against your own assumptions about the provider.

### Before the gate

- **Check the recorded rows, not only the tests.** Run the feature, then query what landed: are the token counts there, is the cost `NULL` where it should be, is the correlation intact. A green suite proves the path; the population is a separate question.
- **Read one real answer end to end.** A pipeline can be green while producing something nobody would ship — an empty completion, a refusal, a correctly-shaped and useless result. Look at one.
