.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

======================================
Tool network policy and external pages
======================================

.. _administration-tools-egress:

Network egress policy per group
===============================

Cooperating HTTP paths check network egress **per tool group** and refuse
requests outside its declared scope (:ref:`ADR-061 <adr-061>`). A group
without a declaration has scope ``none``. This is an application-level gate:
trusted PHP extensions must call it. It does not isolate arbitrary PHP code
from the network. Use infrastructure controls for that boundary.

=========================  ====================================================
Scope                      Meaning
=========================  ====================================================
``none``                   No outbound network request (the default for every
                           group).
``own_site``               Only the instance's own configured site hosts,
                           resolved through ``SiteFinder`` — the exact
                           allow-listing ``probe_url`` applies, now lifted to
                           the group boundary.
``configured_endpoint``    Only the service endpoint the site configuration
                           declares, such as the search backend of the ``rag``
                           tools (:ref:`ADR-093 <adr-093>`).
``external_filtered``      Public internet hosts, each one passed through the
                           address guard of :ref:`ADR-202 <adr-202>`.
                           See its limits on DNS checks, nr-vault pins and
                           global host allowlists.
=========================  ====================================================

The ``system`` group (which carries ``probe_url``) is granted ``own_site``,
``rag`` is granted ``configured_endpoint`` and ``web`` (which carries
``fetch_external_url``) is granted ``external_filtered``; every other group is
``none``. There is no unfiltered "any host" scope in the gate. A custom tool
must use the gate before making a request; its group declaration alone
cannot constrain a separate HTTP client. The
diagnostics tools that share the ``system`` group (``get_env``,
``fetch_logs`` …) never make a network request, so the grant does not loosen
them.

.. _administration-tools-external-pages:

Reading external web pages
==========================

``fetch_external_url`` reads **one** public web page and returns its title,
headings, main text and links as plain text (:ref:`ADR-202 <adr-202>`). Pages
of this installation stay with ``probe_url`` and ``site_rag_query``.

It ships **disabled**. To offer it, enable the ``web`` group and the tool in
the Tools module; a configuration that restricts :guilabel:`Allowed tool
groups` must also list ``web``. It is not admin-only: public web content is
none of the system or cross-user data the admin tier guards.

**Every call waits for a human approval** by default. The URL the model
chooses leaves the installation, and the model is steerable by the pages and
skills it reads, so a run could be led to put data it has seen into a query
string. The approval card shows the host and the full query string. The
approval can be switched off only together with an allowlist (below).

What it refuses, for the first URL and again for every redirect target:

*  schemes other than ``http`` and ``https``, and URLs with credentials;
*  hosts of this installation — every site base and base variant;
*  ports other than 80/443, unless the allowlist names ``host:port``;
*  numeric host forms such as ``2130706433`` or ``0177.0.0.1``, and host names
   with non-ASCII characters (pass the ``xn--`` form instead);
*  any host whose DNS answer contains a private, loopback, link-local, CGNAT,
   multicast, reserved, NAT64 or cloud-metadata address (``169.254.169.254``,
   ``fd00:ec2::254``), IPv4-mapped and other IPv6 forms of those included;
*  a host that does not resolve in DNS — names that exist only in
   ``/etc/hosts`` are unreachable on purpose;
*  any request an HTTP proxy would carry (see below);
*  every request when PHP's curl extension is missing, because the
   connection could not be pinned.

The tool requests a connection pinned to the addresses its guard checked.
The nr-vault HTTP middleware performs another DNS lookup and can replace
that pin. If a literal global TYPO3 HTTP ``allowed_hosts`` entry permits a
private address, the final connection can reach it even though the first
lookup returned public addresses. This is not an unconditional private-IP or
DNS-rebinding guarantee; see :ref:`adr-202`. Avoid such global exceptions for
model-selected hosts and use network-level restrictions for that boundary.

The tool follows at most three redirects, stops the download at 2 MiB,
parses at most 256 KiB of HTML (deeply nested markup is reduced to its text
instead), returns at most
20,000 characters (fewer for scripts with multi-byte characters, so the whole
result stays under 48,000 bytes) and gives up after 20 seconds in total. One
DNS lookup is bounded by the system resolver; set
``options timeout:2 attempts:1`` in :file:`/etc/resolv.conf` to shorten it.
Only HTML and plain text are read.

The text comes back between ``BEGIN``/``END UNTRUSTED EXTERNAL WEB CONTENT``
markers that carry a random value per call, with a note that it is reference
material, not instructions — the framing used for untrusted skill reference
blocks (:ref:`ADR-061 <adr-061>`). Error messages repeat no text the remote server
chose, apart from a redirect target, cut and labelled as untrusted.

The request comes from the installation's own address. A service that trusts
that address — a partner API that allow-lists your server, an intranet reached
through a public address — is not a private range and is not refused; put it
on the denylist.

Four extension settings govern it
(:guilabel:`Admin Tools > Settings > Extension Configuration > nr_llm`):

``tools.fetchExternalUrl.allowedHosts``
   Comma-separated host names (``example.org``, ``*.example.org`` for
   subdomains but not the apex) or IP addresses (``192.0.2.10``,
   ``2001:db8::1``, ``[2001:db8::1]``). Append ``:8443`` (after the brackets
   for IPv6) to allow that port only; list the plain entry as well to keep
   the default port. When set, only these hosts are fetched. Empty (default)
   allows every public host.

``tools.fetchExternalUrl.deniedHosts``
   Hosts that are never fetched, in the same forms, checked before the
   allowlist. A name entry matches the host name in a URL; an address entry
   matches an IP address in a URL and every address a host name resolves to.

``tools.fetchExternalUrl.skipApprovalWithAllowlist``
   Lets calls run without approval — only while the allowlist is non-empty.

``tools.fetchExternalUrl.allowViaProxy``
   By default a fetch is refused when an HTTP proxy would carry it — the TYPO3
   proxy setting (``$GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']``), or
   ``HTTPS_PROXY`` (``HTTP_PROXY`` on the command line) unless ``NO_PROXY``
   excludes the host — because the proxy resolves the host itself and the
   address checks cannot reach past it. This setting permits it, only while
   the allowlist is non-empty.

The tool's address check refuses private addresses even for hosts on its
allowlist. The final connection also depends on nr-vault's HTTP middleware
and the global TYPO3 host allowlist; see :ref:`adr-202` for that limitation.
