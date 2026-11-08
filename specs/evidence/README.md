# Operational evidence index

This retained bundle repairs evidence discoverability. It separates historical
installation qualification from a natural production observation. Local Git
independently confirms the recorded installation commit, tree and parents;
that does not newly attest every claim in an older qualification receipt.
No production observation was rerun during this repair.

| Item | Immutable owner/source and evidence | Meaning |
|---|---|---|
| Consumer installation | [netresearch/t3x-nr-llm PR1232](https://github.com/netresearch/t3x-nr-llm/pull/1232), commit [a45214f292579e5b3bc156c989982f9c493eb659](https://github.com/netresearch/t3x-nr-llm/commit/a45214f292579e5b3bc156c989982f9c493eb659), tree `20946cee3ce8bdcdff3b95889080933649466512`; [receipt](installation-receipt.json), [independent historical review](installation-review.md) | Installation/registration qualified; natural observation not proved |
| Shared compact CI | [netresearch/typo3-ci-workflows source 2a7582b3b2ec93834e912e99b822ad7a93d5b3ab](https://github.com/netresearch/typo3-ci-workflows/tree/2a7582b3b2ec93834e912e99b822ad7a93d5b3ab), `Build/Scripts/check-compact-ci.sh` SHA256 `010e4e5914cea73341943bceb1a4cb37803f6e75573e17724324ef7fdd0d0d86`; [actual MQ proof](compact-mq-proof.json) | Historical four compact cells and coverage-report acceptance; no new hosted run |
| Organization controller | [netresearch/.github source 115b705334754122bcd03e55163a70ba8831a24b](https://github.com/netresearch/.github/tree/115b705334754122bcd03e55163a70ba8831a24b) | Owning implementation and source suites named in the feature spec |
| External expression oracle | [script snapshot](oracle-123.mjs.snapshot), SHA256 `7c959e54a35bd29f290a75d00ed235623ad1bf29f50627ec58f735e89313694a`; [123-case result](oracle-123-result.json); [signed consumer binding](oracle-binding.json) | Offline extracted-expression checks using `@actions/expressions@0.3.61`; zero API calls/native events |
| Exact oracle inputs | Consumer [bd403d9a0e147491ec70e17be9ae55424a27e505](https://github.com/netresearch/t3x-nr-llm/commit/bd403d9a0e147491ec70e17be9ae55424a27e505), [intent](oracle-caller-intent.yml), [evaluate](oracle-caller-evaluate.yml) | Exact blobs rather than moving caller text |
| Genuine selected-bot observation | Not established by this bundle | Inconclusive unless the separately required natural-readiness and terminal receipts exist; never infer it from installation or offline tests |

The historical script is retained as inert text (`oracle-123.mjs.snapshot`), not as
an executable extension component, agent tool or CI entrypoint. Its bytes and
SHA-256 are unchanged. Only an operator deliberately reproducing the offline
fixture should extract it to `oracle-123.mjs` in a disposable workspace, place
the retained policy beside it, install the pinned `@actions/expressions@0.3.61`
runtime under `expression-runtime/`, and invoke:

`node oracle-123.mjs <consumer-bd403-checkout> <org-115-checkout>/.github/actions/dependency-merge-controller`.

Both checkout paths and the local executable environment are trusted operator
inputs; this artifact is not a safe interface for untrusted or model-generated
arguments. Extracting it does not authorize any hosted observation.

The script reads the retained [policy-one-shot.json](policy-one-shot.json) from its
directory; it contains the exact policy JSON in the retained intent caller. This is an offline fixture,
not authorization to rerun the single-use hosted observation. Shared qualification
invokes `Build/Scripts/check-compact-ci.sh` in the named shared-source revision.

[sha256.json](sha256.json) binds the listed qualification artifacts and retained oracle inputs. Historical review
references to larger private packets are provenance descriptions, not local
links or a claim that all raw artifacts were copied here. Public native run/job
IDs can be independently inspected subject to service retention. Organization
workflow callers remain at `@main`; evidence references are independently pinned.
