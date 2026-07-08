.. include:: /Includes.rst.txt

.. _adr-044:

=====================================================
ADR-044: Error-analysis tools with fail-closed guards
=====================================================

:Status: Accepted
:Date: 2026-07-09
:Authors: Netresearch DTT GmbH

.. _adr-044-context:

Context
=======

The agent can read content and configuration (:ref:`ADR-042 <adr-042>`),
but the debugging use cases admins actually bring to the playground —
"why does this URL answer 500", "how do I fix this PHP error" — need
capabilities the tool set lacked: reading the TYPO3 file logs *with* the
failing source code, reading arbitrary project files, searching the code
base, and probing a frontend URL. All four egress host-level data to an
external LLM provider and take model-chosen (attacker-influenceable)
arguments, so each needs a hard, fail-closed containment story.

.. _adr-044-decision:

Decision
========

Four admin-only tools, two shared guards.

Tools
-----

``get_last_exception`` (group ``code``)
   Newest error-level entries from the TYPO3 file logs, with the parsed
   stack trace and ±6 lines of source context inlined for up to three
   project-local frames (vendor/core frames are listed, not expanded).
   ``index`` steps back through older errors, ``search`` filters.

``read_source`` (group ``code``)
   Line-ranged, line-numbered read of one project file (default 60, max
   200 lines).

``search_code`` (group ``code``)
   Literal-substring (opt-in regex) search over project source files —
   a pure-PHP walk, no shell-out — returning ``path:line`` hits under a
   hard budget (20 000 files / 5 s), reported when exhausted.

``probe_url`` (group ``system``)
   One GET against the instance's *own* frontend: status, key headers,
   timing, a 2 KB tag-stripped body excerpt — and on a 5xx the newest
   error-log entries from the probe's ±30 s window are appended through
   the same log parser, so probe and cause arrive in one result.

Shared guards
-------------

``SourcePathGuard`` (used by 1–3)
   Every file access resolves through ``realpath`` and must stay inside
   the project root — one containment check defeats ``../`` traversal and
   symlink escapes alike. Denied outright: dot segments (``.env``,
   ``.git``, ``.ddev``), ``var/*`` except ``var/log``, ``config/system/*``
   and any ``settings.php``/``additional.php``, key-material extensions
   (``key``/``pem``/``crt``/``p12``/``pfx``) and paths mentioning
   ``credential``. Credential-looking assignment lines are value-redacted
   on read; the code walk skips ``vendor/``, ``node_modules/``, ``var/``
   and dot directories and only considers source extensions.

``LogExceptionReader`` (used by 1 and 4)
   Parses TYPO3 ``FileWriter`` records (error levels only, newest first,
   bounded to each file's 2 MB tail) into timestamp, level, component,
   message, exception class and stack frames. One parser, two consumers —
   ``probe_url``'s 5xx↔log correlation reuses it instead of duplicating
   the format knowledge.

``probe_url`` SSRF containment
   Only ``http(s)``, and the target ``host[:port]`` must match a site base
   or base variant of *this* instance (``SiteFinder``); relative paths
   resolve against the first site. Redirects are reported, never followed
   — a 3xx cannot bounce the probe off-host. Transport errors surface
   sanitized (URL credential parameters masked).

.. _adr-044-consequences:

Consequences
============

- The "analyse the error" loop closes: ``probe_url`` → correlated log
  entry → ``get_last_exception`` (full trace + code) → ``read_source`` /
  ``search_code`` for the fix site — without leaving the playground.
- All four tools are ``requiresAdmin() = true`` and live behind the
  :ref:`ADR-043 <adr-043>` group cascade (new group ``code``; ``probe_url``
  joins ``system``), so one central toggle silences the whole family.
- ``settings.php`` and other credential carriers are structurally
  unreadable even for admins — the guard has no bypass parameter by
  design.
- ``probe_url`` performs real frontend requests (cache warm-up, log
  entries, load); it is deliberately GET-only, single-request,
  15 s-capped.
