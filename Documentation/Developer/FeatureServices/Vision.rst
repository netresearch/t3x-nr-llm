.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===================
Image-input recipes
===================

.. _feature-services-vision:

VisionService
=============

**Purpose**: Image analysis and metadata generation.

.. _feature-services-vision-use-cases:

Use cases
---------

- Alt text generation (rte-ckeditor-image).
- SEO title generation.
- Detailed descriptions.
- Custom image analysis.

.. _feature-services-vision-features:

Key features
------------

- Prompts for accessible alt text, which needs review in its page context.
- Prompts for concise image titles.
- Sequential processing of image arrays.
- Base64 and URL support.

.. _feature-services-vision-example:

Example
-------

.. code-block:: php
   :caption: Example: Using VisionService

   use Netresearch\NrLlm\Service\Feature\VisionService;

   // Single image
   $altText = $visionService->generateAltText(
       'https://example.com/image.jpg'
   );

   // Batch processing
   $altTexts = $visionService->generateAltText([
       'https://example.com/img1.jpg',
       'https://example.com/img2.jpg',
   ]);

.. _feature-services-vision-methods:

Methods
-------

.. code-block:: php
   :caption: VisionService methods

   // Generate an alt-text suggestion for review
   $altText = $visionService->generateAltText('https://example.com/image.jpg');

   // Request a concise title
   $title = $visionService->generateTitle('https://example.com/image.png');

   // Generate detailed description
   $description = $visionService->generateDescription($imageUrl);

   // Custom analysis
   $analysis = $visionService->analyzeImage(
       $imageUrl,
       'What colors are prominent in this image?'
   );
