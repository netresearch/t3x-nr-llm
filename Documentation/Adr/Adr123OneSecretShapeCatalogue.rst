.. include:: /Includes.rst.txt

.. _adr-123:

============================================================================
ADR-123: One catalogue of secret shapes for every masking path
============================================================================

:Status: Accepted
:Date: 2026-07-29
:Authors: Netresearch DTT GmbH

.. _adr-123-context:

Context
=======

Three places in this extension masked secrets, and each knew a different subset
of what a secret looks like:

- :php:`RedactsSecretsTrait`, behind the response and prompt guardrails
  (:ref:`ADR-085 <adr-085>` / :ref:`ADR-087 <adr-087>`), knew modern OpenAI
  project keys, classic and fine-grained GitHub PATs, AWS and Google keys, Slack
  tokens and bare JWTs.
- :php:`ContentRedactor`, which decides what gets **written to the database** at
  privacy level REDACTED (:ref:`ADR-064 <adr-064>`), knew only credential-bearing
  URLs, ``Bearer`` headers, a narrower ``sk-`` pattern and e-mail addresses.
- :php:`GetEnvTool` matched on variable **names** only.

Measured against twelve secret shapes, the guardrail masked eleven and the
privacy redactor seven fewer. The consequence was not theoretical: a secret
correctly stripped from a prompt on its way to a provider was still persisted in
cleartext, because the two paths disagreed about what a secret is.

``GetEnvTool``'s name-only rule had a second version of the same problem. The
tool is admin-only but **enabled by default**, and its output egresses to the
configured LLM provider. A variable whose name gives nothing away leaked its
value verbatim:

.. code-block:: text

   GITHUB_PAT=ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
   STRIPE_LIVE=sk_live_<24 alphanumerics>

Neither name contains ``PASS``, ``KEY``, ``SECRET`` or ``TOKEN``, so neither was
redacted.

.. _adr-123-decision:

Decision
========

Move the shapes into one place, :php:`Netresearch\\NrLlm\\Utility\\SecretShapeRedactorTrait`,
next to the :php:`ErrorMessageSanitizerTrait` it builds on, and have all three
consumers read from it.

Two entry points, opposite failure modes
----------------------------------------

``preg_replace()`` returns ``null`` when the regex engine gives up, and a bare
``(string)`` cast turns that into ``''``. On a redaction path, wiping the entire
content looks exactly like a successful, very thorough redaction. The two kinds
of caller need opposite handling, so the trait offers both explicitly:

- :php:`redactSecretShapes()` — **fails open**, keeping the text. For the
  guardrails: losing a model's whole response, or an outgoing prompt, because one
  pattern hit a backtrack limit is worse than missing that pattern.
- :php:`redactSecretShapesStrict()` — **fails closed**, returning null. For
  :php:`GetEnvTool`: a value the redactor could not fully inspect is withheld
  rather than forwarded to a third party.

``GetEnvTool`` checks both name and value
-----------------------------------------

The name rule is kept and the value rule is added, because each catches what the
other misses: a name rule catches an empty or unrecognised-format secret
(``DB_PASSWORD=hunter2``) that no shape pattern would match, and a value rule
catches a recognised secret under a neutral name. A test asserts that the three
neutral fixture names are **not** matched by the name pattern, so the value path
cannot silently stop being exercised if someone widens the name rule later.

The tool keeps its own, stricter URL-userinfo pattern, which masks the whole
``user:password@`` rather than just the password. In a provider error message a
username is useful context; in a listing that egresses to a third party it is
half a credential. Tightening the shared trait instead would have silently
changed what every provider error message discloses.

E-mail masking stays with the privacy redactor
----------------------------------------------

An address is personal data, not a secret, and the guardrails must not begin
stripping addresses out of prompts and responses — removing one changes what the
text says. :php:`ContentRedactor` masks them because it writes to storage; the
guardrails do not.

.. _adr-123-consequences:

Consequences
============

- The privacy redactor now masks every shape the guardrails do, so the
  persist path can no longer be weaker than the egress path.
- ``GetEnvTool`` no longer leaks secret-shaped values under harmless names. A
  connection-string variable still shows its host and path — the context the
  tool exists to provide.
- New shapes (Stripe secret and publishable keys, SendGrid) were added while
  consolidating, so all three consumers gained them at once.
- Adding the next shape is a one-line change in one file instead of three
  edits by someone who has to know all three places exist.
- This remains best-effort. It recognises these shapes and nothing else, and does
  not weaken the rule that secrets belong in nr-vault, never in a prompt, a
  column or an environment variable.

.. _adr-123-upstream:

Relationship to nr-vault
========================

nr-vault carries the same knowledge for its plaintext scanner, and it had drifted
the other way — it knew Stripe, SendGrid, Twilio, Mailchimp and PayPal but not
OpenAI project keys or fine-grained PATs. The merged superset now lives upstream
in :php:`Netresearch\\NrVault\\Secret\\SecretPatternLibrary` (nr-vault ADR-031),
which is the right long-term home: one catalogue for every Netresearch extension
rather than one per extension.

This trait is deliberately shaped so that adopting the upstream library is a
change to :php:`applySecretShapePatterns()` alone, with the two failure-mode
entry points and every call site unchanged. That swap waits on a released
nr-vault version that contains the library.
