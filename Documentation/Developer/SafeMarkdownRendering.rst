.. include:: /Includes.rst.txt

.. _developer-safe-markdown-rendering:

=========================================
Rendering LLM Markdown server-side safely
=========================================

LLM responses are untrusted output (see
:ref:`best practices <developer-best-practices>`): the model can echo raw
HTML, ``javascript:`` links, or attacker-supplied fragments straight from
its prompt context back into its answer. If your extension converts that
Markdown to HTML on the server and injects it into a page, the converter
configuration is a security boundary.

This page shows the hardened server-side recipe, and when to prefer the
alternative that nr-llm itself uses — client-side escaping plus sandboxed
iframes.

.. contents::
   :local:
   :depth: 2

.. _developer-safe-markdown-hardened-commonmark:

Hardened league/commonmark configuration
========================================

``league/commonmark`` is *not* a dependency of nr-llm; add it to your
extension:

.. code-block:: bash
   :caption: Add the dependency

   composer require "league/commonmark:^2.4"

The library's own defaults are unsafe for LLM output: ``html_input``
defaults to ``allow`` (raw HTML in the Markdown passes through verbatim)
and ``allow_unsafe_links`` defaults to ``true`` (``javascript:`` and
``data:`` link destinations are kept). Both must be overridden explicitly:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   services:
       _defaults:
           autowire: true
           autoconfigure: true
           public: false

       League\CommonMark\CommonMarkConverter:
           arguments:
               $config:
                   html_input: 'strip'
                   allow_unsafe_links: false

       League\CommonMark\ConverterInterface:
           alias: League\CommonMark\CommonMarkConverter

``html_input: strip`` removes any raw HTML the model emitted;
``allow_unsafe_links: false`` suppresses link and image destinations with
unsafe schemes such as ``javascript:``.

.. _developer-safe-markdown-fallback:

Escaping fallback on converter failure
======================================

The converter can throw — on malformed input or on a configuration error.
Neither case may end in an uncaught exception (denial of service via
crafted output) or in emitting the raw text unescaped. Fall back to
:php:`htmlspecialchars()`, which yields correctly escaped, if unformatted,
output:

.. code-block:: php
   :caption: Classes/Presentation/SafeMarkdownRenderer.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Presentation;

   use League\CommonMark\ConverterInterface;
   use League\CommonMark\Exception\CommonMarkException;
   use League\Config\Exception\ConfigurationExceptionInterface;

   final readonly class SafeMarkdownRenderer
   {
       public function __construct(
           private ConverterInterface $markdownConverter,
       ) {}

       public function render(string $llmMarkdown): string
       {
           try {
               return $this->markdownConverter
                   ->convert($llmMarkdown)
                   ->getContent();
           } catch (CommonMarkException | ConfigurationExceptionInterface) {
               return htmlspecialchars(
                   $llmMarkdown,
                   \ENT_QUOTES | \ENT_HTML5,
               );
           }
       }
   }

In a Fluid template, output the result raw — it is already HTML — and
never pass it through another escaping layer that would double-encode it:

.. code-block:: html
   :caption: Resources/Private/Templates/Show.html

   <div class="llm-answer">{answerHtml -> f:format.raw()}</div>

.. warning::

   The hardened converter only guards content that goes *through* it.
   Hyperlink targets your template builds outside the converter — for
   example source URLs taken from index metadata and placed into ``href``
   attributes — are a separate trust boundary: Fluid's default
   htmlspecialchars-based escaping does not neutralize a ``javascript:``
   URI inside an ``href``. Validate such URIs server-side (allow absolute
   ``http(s)://`` URLs and single-slash site-relative paths; reject
   everything else, including protocol-relative ``//host`` forms).

.. _developer-safe-markdown-client-side:

Alternative: client-side escaping and sandboxed iframes
=======================================================

nr-llm's own backend module deliberately does *not* render LLM output
server-side. Its task-execution view
(:file:`Resources/Public/JavaScript/Backend/TaskExecute.js`) escapes
plain, Markdown, and JSON output client-side before insertion, and shows
LLM-*generated HTML* only inside an iframe with ``sandbox=""`` — the
fully restrictive sandbox, which blocks script execution, form
submission, and same-origin access entirely.

Which strategy fits depends on where and how the output is displayed:

Server-side hardened rendering (this page)
    Choose it for anonymous frontend pages: the output is complete
    HTML that works without JavaScript, can be cached with the page,
    and is identical for every client. The
    :ref:`endpoint-protection recipes <developer-endpoint-protection>`
    pair with it on the same controller.

Client-side escaping + sandboxed iframes (nr-llm's approach)
    Choose it for interactive views — backend modules,
    single-page-style widgets — where output arrives incrementally
    (streaming) and is inserted into a live DOM, or where the feature is
    previewing LLM-generated *HTML itself*, which no Markdown converter
    setting can make safe to inline. The sandbox attribute confines the
    document instead of sanitizing it.

The two are not exclusive: an extension with a frontend plugin and a
backend preview module uses both, each at its own surface.

.. _developer-safe-markdown-reference:

Reference implementation
========================

The ``nr_ai_search`` extension (Netresearch) implements the server-side
recipe for its anonymous search and chat plugins: the hardened converter
configuration lives in its :file:`Configuration/Services.yaml`, and its
:php:`MarkdownAnswerPresenter` (:file:`Classes/Provider/`) combines the
converter with the :php:`htmlspecialchars()` fallback and server-side
``href`` validation for source links.
