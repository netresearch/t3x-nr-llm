.. include:: /Includes.rst.txt

.. _adr-020:

==========================================
ADR-020: Backend Output Format Rendering
==========================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-020-context:

Context
=======

LLM responses can contain markdown, HTML, JSON, or plain text depending on the
task's output format. Users need to view output in an appropriate rendering
mode without re-executing the (potentially expensive) LLM call.

.. _adr-020-decision:

Decision
========

Store raw LLM output and handle format rendering entirely client-side. The
toggle between formats is ephemeral (not persisted) and operates on the
cached raw content.

Four rendering modes in :file:`Resources/Public/JavaScript/Backend/TaskExecute.js`:

.. code-block:: javascript
   :caption: Format rendering dispatch

   renderOutput() {
       const content = this._rawContent;
       const escaped = this.escapeHtml(content);
       switch (this._activeFormat) {
           case 'html':     this.renderHtmlOutput(content);    break;
           case 'markdown': this.renderMarkdownOutput(escaped); break;
           case 'json':     this.renderJsonOutput(content);     break;
           default:         this.renderPlainOutput();            break;
       }
   }

.. _adr-020-modes:

Rendering modes
---------------

.. csv-table::
   :header: "Mode", "Technique", "Security"
   :widths: 15, 45, 40

   "Plain", "``<pre>`` with ``textContent`` assignment", "Fully escaped (DOM API)"
   "Markdown", "Regex transforms on HTML-escaped content", "Pre-escaped before transform"
   "JSON", "``JSON.stringify`` pretty-print in ``<pre>``", "``textContent`` assignment"
   "HTML", "Sandboxed iframe (``sandbox=\"\"``)", "No script execution, no parent DOM access"

.. _adr-020-security:

Security approach
-----------------

LLM responses are untrusted external content. Each mode uses a different
security strategy:

- **Plain/JSON:** Content set via ``textContent`` (automatic HTML escaping by the DOM).
- **Markdown:** Content is first HTML-escaped via ``escapeHtml()`` (``textContent``
  assignment to a temporary element, then read back via ``innerHTML``). Markdown
  regex transforms operate on already-escaped content, making injection safe.
- **HTML:** Rendered inside a fully sandboxed ``<iframe sandbox="">`` which blocks
  all scripting, form submission, and parent page access. A fixed height of 400px
  is used since ``contentDocument`` is inaccessible in sandbox mode.

.. code-block:: javascript
   :caption: XSS-safe HTML escaping

   escapeHtml(text) {
       this._escapeEl.textContent = text;
       return this._escapeEl.innerHTML;
   }

.. _adr-020-format-toggle:

Format toggle
-------------

The active format is initialized from the task's ``output_format`` setting
(returned by the server in the AJAX response) and can be switched by clicking
format toggle buttons. The toggle updates ``_activeFormat``, re-renders from
``_rawContent``, and highlights the active button. Clipboard copy always uses
the raw content regardless of active rendering mode.

.. _adr-020-consequences:

Consequences
============
**Positive:**

- ●● No server round-trip needed to switch display formats.
- ● XSS prevention for all four rendering modes via distinct security strategies.
- ● Raw content preserved for clipboard copy regardless of rendering.
- ◐ Format toggle state is ephemeral, avoiding unnecessary persistence.
- ◐ Markdown renderer is lightweight (regex-based, no external library).

**Negative:**

- ◑ Markdown regex renderer is simplified (no tables, no nested lists, no links).
- ◑ HTML iframe height is fixed at 400px (cannot auto-resize in sandboxed mode).
- ◑ No syntax highlighting for JSON or code blocks.

**Net Score:** +4.5 (Positive)

.. _adr-020-files-changed:

Files changed
=============

**Added:**

- :file:`Resources/Public/JavaScript/Backend/TaskExecute.js`

**Modified:**

- :file:`Resources/Private/Templates/Backend/Task/Execute.html` -- Format toggle UI and output container.
- :file:`Classes/Controller/Backend/TaskController.php` -- Returns ``outputFormat`` in AJAX response.
- :file:`Classes/Domain/Enum/TaskOutputFormat.php` -- Defines valid output formats with content types.
