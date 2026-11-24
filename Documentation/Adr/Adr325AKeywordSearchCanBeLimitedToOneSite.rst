.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-325:

====================================================
ADR-325: A keyword search can be limited to one site
====================================================

:Status: Accepted
:Date: 2026-11-24
:Amends: :ref:`ADR-071 <adr-071>`
:Authors: Netresearch DTT GmbH

.. _adr-325-context:

Context
=======

The keyword-search facade of :ref:`ADR-071 <adr-071>` takes no site. A
consumer that answers on one site of an instance with several has to drop
the other sites' hits itself, after the search, and with Solr that comes too
late:

- :php:`SolrSearchBackend` asks each site's core in turn and stops at the
  limit. On a core that several sites share, the first site's query can fill
  the limit, so the requested site's query never runs and every hit carries
  the first site's host.
- EXT:solr stores a relative URL by default, and the backend puts the queried
  site's host in front of it. On a shared core a page of another site
  therefore gets an address on the queried site. When both sites have a page
  at that path, :php:`RetrievalService` keeps one of the two by URL, which
  may be the other site's, and the requested site's page is lost before any
  consumer sees it.

The cascade itself already knows a site: :php:`RetrievalQuery` carries a
site identifier, the ``site_rag_query`` tool sets it, and the database,
indexed_search and ke_search backends drop a page that belongs to another
site. Only the facade never sets it, and only the Solr backend does not check
the page.

.. _adr-325-decision:

Decision
========

1. **The facade takes an optional site.** ``search()`` of
   :php:`KeywordSearchInterface` gains a fourth parameter,
   ``?string $siteIdentifier = null``, which goes into
   :php:`RetrievalQuery`. Null or a blank string searches every site, as
   before. A site that does not exist finds nothing. The interface is
   ``@api`` to call, not an extension point, so the optional parameter is an
   addition for callers (:ref:`ADR-127 <adr-127>`); a class outside this
   extension that implements the interface has to add it.
2. **A Solr search limited to a site drops the pages of other sites.** The
   page of a ``pages`` document is placed with :php:`SiteFinder`, as the
   other backends do, before hits are deduplicated by URL. A page that has no
   site, or whose rootline cannot be read, is dropped as well. A search
   limited to a site asks Solr for 50 rows, so that the pages it drops do not
   use up the limit of at most 20 hits.
3. **A search without a site is unchanged**, including its request to Solr.

Item 2 also applies to ``site_rag_query`` when it names a site.

.. _adr-325-consequences:

Consequences
============

- A Solr document that is not a page is still placed by its URL host alone.
  Its Solr ``pid`` is the storage folder, which may lie in another site than
  the one it was indexed for, and the Solr ``site`` field holds the site's
  identifier or its domain, depending on EXT:solr's site-hash strategy. On a
  shared core a record of another site with a relative URL can therefore
  still appear.
- A page of another site shown through a mount point belongs to the site of
  its page. EXT:solr files it the same way.
- The rows read before the site check stay bounded in every backend (50, in the
  database backend per table). A site whose pages rank below that many others
  still gets fewer hits than it has.
- A backend that another extension registers receives the site in
  :php:`RetrievalQuery` and has to respect it itself.

.. _adr-325-alternatives:

Alternatives considered
=======================

- **Filter by EXT:solr's siteHash or site field in the Solr query.** Rejected:
  both depend on EXT:solr's configuration and events, and a value that does
  not match would empty the result without a sign.
- **A second interface for a search limited to a site.** Rejected: callers
  would have to check for it, and the parameter already exists one level down.
