.. include:: /Includes.rst.txt

.. _adr-202:

============================================================================
ADR-202: Reading a public web page through an address guard
============================================================================

:Status: Accepted
:Date: 2026-09-23
:Amends: :ref:`ADR-061 <adr-061>` (item 6: "there is no free-form any-host scope" now reads "no unfiltered one")
:Authors: Netresearch DTT GmbH

.. _adr-202-context:

Context
=======

In the demo chats (NEXT-165) users asked the backend assistant five times to
read or analyse a web page on another site. No tool could. ``probe_url``
reaches only the instance's own site hosts (:ref:`ADR-044 <adr-044>`), and
``site_fetch_source`` returns only hits of the site's own search
(:ref:`ADR-049 <adr-049>`).

Network egress is declared per tool **group** and fails closed
(:ref:`ADR-061 <adr-061>` item 6). Two positive scopes existed: ``own_site``
for ``system`` and ``configured_endpoint`` for ``rag``
(:ref:`ADR-093 <adr-093>`). ADR-061 states that no free-form "any host" scope
exists, so a new or mis-declared group cannot egress to an arbitrary target.

A tool that fetches a URL the model chooses is the textbook server-side
request forgery surface: the model is steerable by the pages and skills it
reads, and the request originates inside the operator's network, next to
databases, caches and the cloud metadata service.

nr-vault already guards its HTTP client against this
(:php:`SecureHttpClientFactory`: private ranges, IPv4-mapped and transition
IPv6 forms, legacy numeric host forms, a ``CURLOPT_RESOLVE`` pin per request).
Two properties of it do not fit a model-chosen URL, measured against the
resolved dependency, nr-vault 0.16.0:

- Its public check, ``isHostAllowed()``, treats a literal entry in
  ``$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']`` as an opt-in past
  the private-range block. That list exists for credentialed service calls;
  an operator who put an internal vault host on it did not decide that a
  model may fetch it.
- A host name its ``dns_get_record()`` lookup does not answer is passed
  through without a pin, and curl then resolves it with ``getaddrinfo()``,
  which also reads ``/etc/hosts``. ``db`` or ``host.docker.internal`` exist
  only there, and point inside the network.

.. _adr-202-decision:

Decision
========

``fetch_external_url`` reads ONE http(s) page and returns its readable text.

1. **Its own group, its own scope.** The tool is the only member of a new
   group ``web``, declared ``external_filtered`` — a new
   :php:`ToolEgressScope` case. ``system`` keeps ``own_site``; putting the
   tool there would have widened ``probe_url``'s egress, since the scope is
   per group. The own-site and configured-endpoint resolvers of
   :php:`EgressPolicyService` refuse the new scope explicitly.

2. **An address guard in front of every request.**
   :php:`ExternalUrlGuard` checks the first URL and every redirect target, in
   this order: the group's scope; http(s), a host, no userinfo; an ASCII
   host name or IP literal; the operator's denylist; the operator's
   allowlist; the default port unless the allowlist names ``host:port``; no
   legacy numeric host form; and every address the host resolves to through
   DNS must be public (:php:`IpAddressClassifier`). A host that does not
   resolve through DNS is refused rather than handed to curl.

   The classifier restates nr-vault's ranges (plus the documentation ranges
   and site-local IPv6) instead of calling ``isHostAllowed()``, for the
   reason in the context. The lists narrow and never widen: an allow-listed
   host that resolves to a private address is refused.

3. **The connection is pinned to the checked addresses.** The request
   carries a ``CURLOPT_RESOLVE`` entry with exactly the addresses the guard
   resolved and checked, so curl never resolves the host itself and a DNS
   answer that changes between check and connect (DNS rebinding) has
   nothing to act on. The client is nr-vault's, so its own per-request check
   runs underneath as a second layer, with the instance's proxy and TLS
   settings. When nr-vault's lookup answers, its middleware appends a pin of
   its own, which curl prefers because it comes last; those addresses passed
   nr-vault's range check. Either way curl connects only to an address one
   of the two checks accepted, and never resolves the name itself.

4. **Redirects are followed by the tool, one hop at a time**, at most
   three; ``allow_redirects`` is off on every request. Each hop passes the
   whole guard and gets its own pin.

5. **Bounded in bytes, characters and time.** A response sink accepts at
   most 2 MiB and then reports a short write, which makes curl abort the
   transfer — the limit bounds what is downloaded, not only what is kept.
   The text returned is cut at 20,000 characters, and further in bytes so
   the whole result stays under 48,000 bytes: the loop's
   :php:`ToolResultBounder` cuts results over 50,000 bytes at the tail, and
   the tail is the END marker. The connect timeout is
   5 s and the whole fetch, redirects included, 20 s. A successful answer
   that is not HTML or plain text is refused at the headers, before its
   body is read.

6. **Marked as untrusted input.** The text sits between fixed
   ``<<<BEGIN/END UNTRUSTED EXTERNAL WEB CONTENT>>>`` markers under a
   preamble saying it is reference material, not instructions — the shape
   ADR-061 item 4 gave skill bodies. A verbatim marker inside the page is
   defused. The data class is ``publicContent`` (the ``web`` group default),
   for the reason ``rag`` has it: the page is world-readable. The data class
   measures sensitivity, not trust; trust is what the markers carry.

7. **Disabled by default, not admin-only.** ``isEnabledByDefault()`` is
   false: this is the first built-in that reaches hosts nobody declared, and
   the model-chosen URL itself leaves the installation. That follows how the
   repository ships a new kind of capability — the writers
   (:ref:`ADR-135 <adr-135>`) and repository skills arrive disabled.
   ``requiresAdmin()`` is false: the admin tier guards system, host and
   cross-user data, and a public page is none of these; the users who asked
   for the tool are editors.

8. **Host lists** are extension settings, as the generic creator's denied
   tables are (:ref:`ADR-197 <adr-197>`):
   ``tools.fetchExternalUrl.allowedHosts`` and ``.deniedHosts``. Settings
   that cannot be read refuse every fetch.

.. _adr-202-scope:

What this does, what it does not, what proves it
================================================

Does: fetch one public page per call, text only, with the limits above.

Does not: render JavaScript, submit forms, send cookies or credentials, read
PDFs or other binary formats, or fetch hosts of this installation (those stay
with ``probe_url``). Internationalised host names are refused rather than
converted, because the pin is keyed by the host string curl sees; the
``xn--`` form works.

Proven by: ``Tests/Unit/Service/Tool/Web/IpAddressClassifierTest`` (IPv4,
IPv6, IPv4-mapped, 6to4, Teredo, NAT64, decimal/octal/hex host forms),
``ExternalUrlGuardTest`` (schemes, credentials, ports, lists, a list that
cannot readmit a private address, unresolvable and privately resolving
hosts), ``Tests/Unit/Service/Tool/Builtin/FetchExternalUrlToolTest`` against
a scripted transport (the pin, redirect to a private address and to a
privately resolving host, the redirect limit, the byte and character caps,
the fence), and ``Tests/Functional/Service/Tool/FetchExternalUrlToolTest``
for the container wiring.

.. _adr-202-consequences:

Consequences
============

- ● Editors can have a page on another site read and analysed, which the
  demo chats asked for five times.
- ● No private, loopback, link-local, CGNAT or metadata address is reachable
  through the tool, by literal, by DNS answer, by redirect or by a DNS answer
  that changes before the connect.
- ● ``probe_url`` and every other group keep the egress they had.
- ◐ The ranges live in two places, nr-vault and :php:`IpAddressClassifier`.
  A test pins one direction: every address of its sample that nr-vault
  refuses is refused here too, so a range nr-vault adds and this side lacks
  turns it red if the sample carries an address of it.
- ◐ Behind the TYPO3 HTTP proxy the proxy resolves the host, so the pin does
  not reach past it; the address check before the request still runs.
- ◐ Hosts that exist only in ``/etc/hosts`` or other non-DNS sources are
  unreachable, on purpose.
- ○ The URL leaves the installation. A run steered by injected text can put
  data it has seen into a URL. The operator's answer is the allowlist; the
  tool cannot tell an exfiltrating query string from a search.

.. _adr-202-alternatives:

Alternatives considered
=======================

- **The tool in ``system``, beside ``probe_url``.** Rejected: egress is per
  group, so ``system`` would have needed the new scope and ``probe_url``
  would have gained it.
- **nr-vault's ``isHostAllowed()`` as the only check.** Rejected for the two
  properties in the context: the ``allowed_hosts`` opt-in and the unpinned
  path for names DNS does not answer.
- **Enabled by default.** Rejected: an operator should decide once that the
  installation may fetch from the internet on a model's behalf, and whether
  to restrict it to a list.
- **A readability library** (Readability.php and its ports). Rejected: a
  sizeable dependency for a TYPO3 extension. The HTML5 parser TYPO3 already
  ships (``masterminds/html5``, now declared directly) plus dropping the page
  chrome gives the model the main text.
