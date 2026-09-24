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
   host name or IP literal; not a host of this installation (every site base
   and base variant, through ``SiteFinder``); the operator's denylist; the
   operator's allowlist; the default port unless the allowlist names
   ``host:port``; no HTTP proxy (item 10); no legacy numeric host form; and
   every address the host resolves to through DNS must be public
   (:php:`IpAddressClassifier`) and not on the denylist. A host that does not
   resolve through DNS is refused rather than handed to curl.

   The classifier restates nr-vault's ranges (plus the documentation ranges,
   site-local IPv6 and the local-use NAT64 prefix ``64:ff9b:1::/48``, which
   nr-vault 1.0.0 does not refuse, fixed in `nr-vault#388
   <https://github.com/netresearch/t3x-nr-vault/pull/388>`__) instead of
   calling ``isHostAllowed()``, for
   the reason in the context. Both NAT64 prefixes are refused whole rather
   than by their embedded IPv4, because the embedding position of the
   local-use prefix depends on a prefix length the address does not carry.
   The lists narrow and never widen: an allow-listed host that resolves to a
   private address is refused.

   List entries are host names or addresses. A name entry matches the host
   name of a URL; an address entry (IPv4, bare or bracketed IPv6, optionally
   with a port) matches an IP literal in a URL and, compared as a packed
   address, every address a host name resolves to — so the denylist cannot be
   stepped around by naming the same server differently.

   The request comes from the installation's own address. Services that trust
   that address (a partner API allow-listing the server, an intranet reached
   through a public address) are not private ranges and the guard cannot see
   them; they belong on the denylist, or the tool on an allowlist.

3. **The connection is pinned to checked addresses.** The request carries a
   ``CURLOPT_RESOLVE`` entry with the addresses the guard resolved and
   checked, so curl never resolves the host itself and a DNS answer that
   changes between check and connect (DNS rebinding) has nothing to act on.
   The client is nr-vault's, so its own per-request check runs underneath as
   a second layer, with the instance's TLS settings. When nr-vault's lookup
   answers, its middleware appends a pin of its own, and curl uses the LAST
   entry for a host and port — so the effective pin is usually nr-vault's.
   Its addresses passed nr-vault's range check; the guard's addresses are what
   curl uses when nr-vault's lookup does not answer. Either way curl connects
   only to an address one of the two checks accepted and never resolves the
   name itself. Without ext-curl there is no pin (Guzzle's stream handler
   ignores ``CURLOPT_RESOLVE``), and the tool refuses to fetch.

4. **Redirects are followed by the tool, one hop at a time**, at most
   three; ``allow_redirects`` is off on every request. Each hop passes the
   whole guard and gets its own pin.

5. **Bounded in bytes, characters and time.** A response sink accepts at
   most 2 MiB and then reports a short write, which makes curl abort the
   transfer — the limit bounds what is downloaded, not only what is kept.
   The HTML parser (masterminds/html5, quadratic in the nesting depth: 40,000
   nested ``<div>`` take about 40 s) gets at most 256 KiB, and markup a linear
   pre-scan finds nested deeper than 200 levels is not parsed at all but
   reduced to its text; the renderer's recursion stops at the same depth.
   The text returned is cut at 20,000 characters, and further in bytes so the
   whole result stays under 48,000 bytes: the loop's :php:`ToolResultBounder`
   cuts results over 50,000 bytes at the tail, and the tail is the END
   marker. The connect timeout is 5 s and the whole fetch, redirects
   included, 20 s; the budget is checked before every guard call (its DNS
   lookup takes time), after it, and after the transfer before parsing.
   ``dns_get_record()`` takes no timeout of its own and ignores
   ``RES_OPTIONS``; it honours the ``options timeout:N attempts:N`` line of
   ``/etc/resolv.conf``, which is where an operator bounds one lookup. A
   successful answer that is not HTML or plain text is refused at the
   headers, before its body is read.

6. **Marked as untrusted input.** The text sits between
   ``<<<BEGIN/END UNTRUSTED EXTERNAL WEB CONTENT …>>>`` markers that carry a
   random nonce per call, under a preamble saying it is reference material,
   not instructions — the shape ADR-061 item 4 gave skill bodies. A page
   cannot know the nonce of the marker that closes its fence, and anything
   in it that looks like a marker (any case, any spacing, with or without a
   nonce) is defused. The data class is ``publicContent`` (the ``web`` group
   default), for the reason ``rag`` has it: the page is world-readable. The
   data class measures sensitivity, not trust; trust is what the markers
   carry.

   Outside the fence — in error results and the status line — no
   server-chosen text is echoed: an HTTP error reports the status code only,
   a media type only when it is a well-formed ``type/subtype`` of at most 64
   characters, and a redirect target cut to 500 characters and labelled as
   an untrusted URL the server chose.

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

9. **Every call waits for a human approval by default.** The URL itself can
   carry data out — a run steered by injected text can put what it has seen
   into a query string, and no address check can tell that from a search. So
   the tool implements the new :php:`ConfigurableApprovalInterface` and asks
   for approval unless the operator sets
   ``tools.fetchExternalUrl.skipApprovalWithAllowlist`` AND a non-empty
   allowlist; the allowlist is what bounds where the data could go. The
   approval card's preview shows the host and the full query string. The
   interface is not :php:`RequiresApprovalInterface`, which cannot be
   conditional, and not :php:`RemoteApprovalInterface`, which is reserved for
   remote tools (ADR-134). :php:`ToolApprovalRule` asks the declared write
   effect first, so the interface cannot lift the approval of a write, and
   ADR-134's "a write-without-approval builtin is not expressible" holds.

10. **No proxy, unless the operator permits it with an allowlist.** When the
    TYPO3 HTTP proxy setting, or ``HTTPS_PROXY`` (and ``HTTP_PROXY`` on the
    CLI) with ``NO_PROXY`` as the exclusion list, applies to a request, the
    proxy resolves the host and the pin does not reach the connection. The
    fetch is refused then, unless ``tools.fetchExternalUrl.allowViaProxy`` is
    set together with a non-empty allowlist. The address check before the
    request still runs in that case, but it no longer decides where the proxy
    connects.

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
IPv6, IPv4-mapped, 6to4, Teredo, both NAT64 prefixes, decimal/octal/hex host
forms, and one-directional agreement with nr-vault), ``ExternalUrlGuardTest``
(schemes, credentials, ports, own site hosts, proxies, name and address list
entries including IPv6, a list that cannot readmit a private address,
unresolvable and privately resolving hosts),
``Tests/Unit/Service/Tool/Builtin/FetchExternalUrlToolTest`` against a
scripted transport (approval, preview, the pin, no pin without curl, redirect
to a private address and to a privately resolving host, the redirect limit,
the time budget before lookups and before parsing, the byte and character
caps, the nonce fence, unechoed server text), ``HtmlTextExtractorTest``
(parse cap, deep nesting), ``ToolApprovalRuleTest`` (a configurable approval
cannot lift a write), and
``Tests/Functional/Service/Tool/FetchExternalUrlToolTest`` for the container
wiring.

.. _adr-202-consequences:

Consequences
============

- ● Editors can have a page on another site read and analysed, which the
  demo chats asked for five times.
- ● Without a proxy, no private, loopback, link-local, CGNAT or metadata
  address is reachable through the tool — by literal, by DNS answer, by
  redirect or by a DNS answer that changes before the connect.
- ● Nothing leaves without a human seeing the URL, unless the operator chose
  an allowlist and switched the approval off.
- ● ``probe_url`` and every other group keep the egress they had.
- ◐ The ranges live in two places, nr-vault and :php:`IpAddressClassifier`.
  A test pins one direction: every address of its sample that nr-vault
  refuses is refused here too, so a range nr-vault adds and this side lacks
  turns it red if the sample carries an address of it.
- ◐ Through a permitted proxy the address guarantees above do not hold: the
  proxy resolves and connects, and only the allowlist bounds where it goes.
- ◐ Hosts that exist only in ``/etc/hosts`` or other non-DNS sources are
  unreachable, on purpose.
- ◐ One DNS lookup is bounded only by the system resolver's settings.
- ○ An approver has to read the query string. With approval switched off the
  allowlist is the only bound on where data can go; the tool cannot tell an
  exfiltrating query string from a search.

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
- **``RequiresApprovalInterface`` with no way out.** Rejected: an operator
  who restricted the tool to a handful of documentation hosts has bounded
  where data can go, and an approval per page read would make the tool
  unusable for the editors who asked for it.
- **``Dom\HTMLDocument`` (lexbor) on PHP 8.4+.** Not used: it is a different
  DOM API from the one PHP 8.2 and 8.3 offer, so it would mean a second
  renderer for half the support matrix. The size cap and the depth pre-scan
  bound masterminds/html5 instead (about 0.7 s for 256 KiB of the worst
  shape it still parses).
