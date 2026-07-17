.. include:: /Includes.rst.txt

.. _api-document-analysis-service:

=======================
DocumentAnalysisService
=======================

.. php:namespace:: Netresearch\NrLlm\Specialized\Document

.. php:class:: DocumentAnalysisService

   Stateless "understand this document" primitive (:ref:`ADR-076
   <adr-076>`).

   When the resolved provider implements
   :php:`DocumentCapableInterface` (Gemini, Claude), the PDF is
   ingested natively as a Base64 document block in a single chat
   call — whole-document reasoning. Otherwise the document is
   rasterized page-by-page with poppler and each page is read by
   the vision model; the per-page answers are concatenated with
   ``[Page N]`` markers.

   .. php:method:: analyzeDocument(string $pdf, string $prompt, ?ChatOptions $options = null): DocumentAnalysisResult

      Analyze a PDF with a custom prompt.

      :param string $pdf: Raw PDF bytes (must start with the
         ``%PDF-`` header)
      :param string $prompt: Analysis prompt, applied to the whole
         document on the native path and to each page on the
         fallback
      :param ChatOptions|null $options: Chat options; ``provider``,
         ``model``, ``maxTokens``, ``temperature`` and the budget
         attribution fields (``beUserUid``, ``plannedCost``) are
         passed through to whichever path runs
      :returns: :php:`DocumentAnalysisResult` with the answer text,
         the model/provider that produced it, whether the native
         document path was used, and the rasterized page count
      :throws: :php:`UnsupportedFormatException` when the bytes are
         not a PDF
      :throws: :php:`ServiceUnavailableException` when the provider
         lacks native PDF support and poppler is not installed
      :throws: :php:`PdfRasterizationException` when rasterization
         itself fails

.. _api-document-analysis-service-usage:

Usage
=====

.. code-block:: php
   :caption: Analyzing a PDF

   use Netresearch\NrLlm\Service\Option\ChatOptions;
   use Netresearch\NrLlm\Specialized\Document\DocumentAnalysisService;

   public function __construct(
       private readonly DocumentAnalysisService $documentAnalysis,
   ) {}

   $result = $this->documentAnalysis->analyzeDocument(
       $pdfBytes,
       'List the key obligations defined in this contract.',
       new ChatOptions(maxTokens: 1024),
   );

   $result->text;                    // the answer
   $result->usedNativeDocumentPath;  // true: one whole-document call
   $result->rasterizedPageCount;     // pages read on the fallback path

.. _api-document-analysis-service-poppler:

Optional system dependency: poppler
===================================

The rasterization fallback shells out to the poppler binaries
``pdftoppm`` and ``pdfimages`` (Debian/Ubuntu package
``poppler-utils``; declared in ``composer.json`` ``suggest``). They
are only needed when the resolved provider has no native PDF
support. Without them, the fallback fails with the typed
:php:`ServiceUnavailableException` (code ``1784211009``) naming
both remedies: install ``poppler-utils`` or configure a
document-capable provider. The native path never touches poppler.

Degradation policy is the caller's: on the fallback path a failed
page fails the call — catch and retry (or degrade) in the consumer.

.. _api-document-analysis-service-rasterizer:

PdfRasterizerInterface
======================

.. php:class:: PdfRasterizerInterface

   Rasterizes PDF pages to PNG blobs. The default implementation is
   the poppler-backed :php:`PopplerPdfRenderer`; substitute it by
   aliasing the interface to another class in your
   :file:`Services.yaml`.

   .. php:method:: imagePages(string $absolutePath): array

      1-based page numbers carrying at least one embedded raster
      image.

   .. php:method:: renderPage(string $absolutePath, int $page): string

      PNG bytes of one rasterized page.

   .. php:method:: renderDocument(string $absolutePath): array

      PNG bytes per 1-based page number for the whole document.

   .. php:method:: isAvailable(): bool

      Whether the system binaries are present.
