## AI feature obligations — plan stage

The base preset already told you to read the repository's own instructions and its decision records. These are the additional things an AI plan has to settle, and each is a place where a plausible design turns out to be wrong.

### Capability and fallback

- **Which providers actually have the capability**, checked against their adapters rather than their marketing. State it per provider, because "most support it" is what produces the bug report.
- **Where the fallback decision is enforced.** If the project has a middleware or chain for this, the plan goes through it; a feature that calls a provider directly to "keep it simple" bypasses retries, ordering and error mapping and will be found later by someone else.
- **What the caller receives when every candidate lacked the capability.** An exception, a null, a degraded answer — and whether that is distinguishable from a provider error.

### Boundaries

- **Where untrusted output crosses into something that interprets it.** Name every such point, not the first one: a sanitiser at the request boundary does nothing for the response boundary, and fixing one of two is how the same defect ships twice.
- **What is logged.** Prompts and completions carry whatever the user pasted. Say what is written to the log and what is deliberately not, and check the project's existing sanitiser rather than writing a second one.

### Measurement

- **Where a recorded value can be absent, say which column carries the absence.** A `NOT NULL` column with a default cannot express "not measured", so either the value goes somewhere that can, or the plan states the accepted loss in writing. Check the surrounding table first: if it already keeps unmeasured values as `NULL`, follow that convention rather than inventing one.
- **What the value is derived from**, and what happens when one input of the derivation is missing. Deriving from a substitute produces a number that looks measured and is not.

### Testing a non-deterministic feature

- **Name the assertion shape** the tests will use, and where the boundary between a unit test and a test that needs a real provider lies. If a suite in this project mocks providers, say which behaviour is therefore untested by it.
- **Say what a recorded fixture proves and what it does not.** A captured response pins your parsing; it says nothing about the provider still behaving that way.
