.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-vision-service:

=============
VisionService
=============

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: VisionService

   Image analysis with specialized prompts.

   .. php:method:: generateAltText($imageUrl, ?VisionOptions $options = null)

      Request concise alt text intended for screen readers.

      The prompt asks for essential information in under 125 characters.
      Provider output is returned verbatim: length and accessibility
      suitability require review by the caller.

      :param string|array $imageUrl: URL, base64 image data URI,
         or an array of these for sequential per-image requests
      :param VisionOptions|null $options: Vision options
         (defaults: maxTokens=100, temperature=0.5)
      :returns: string|array Alt text or array of alt
         texts for batch input

   .. php:method:: generateTitle($imageUrl, ?VisionOptions $options = null)

      Request an image title.

      The prompt asks for a title under 60 characters. This is a prompt
      constraint; the service does not validate or truncate provider output.

      :param string|array $imageUrl: URL, base64 image data URI,
         or an array of these for sequential per-image requests
      :param VisionOptions|null $options: Vision options
         (defaults: maxTokens=50, temperature=0.7)
      :returns: string|array Title or array of titles
         for batch input

   .. php:method:: generateDescription($imageUrl, ?VisionOptions $options = null)

      Generate detailed image description.

      Provides comprehensive analysis including subjects,
      setting, colors, mood, composition, and notable
      details.

      :param string|array $imageUrl: URL, base64 image data URI,
         or an array of these for sequential per-image requests
      :param VisionOptions|null $options: Vision options
         (defaults: maxTokens=500, temperature=0.7)
      :returns: string|array Description or array of
         descriptions for batch input

   .. php:method:: analyzeImage($imageUrl, string $customPrompt, ?VisionOptions $options = null)

      Custom image analysis with specific prompt.

      :param string|array $imageUrl: URL, base64 image data URI,
         or an array of these for sequential per-image requests
      :param string $customPrompt: Custom analysis prompt
      :param VisionOptions|null $options: Vision options
      :returns: string|array Analysis result or array of
         results for batch input

   .. php:method:: analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions $options = null): VisionResponse

      Full image analysis returning complete response with usage statistics.

      Returns a :php:class:`VisionResponse` with metadata and usage data,
      unlike the other methods which return plain text.

      :param string $imageUrl: Image URL or base64 data URI
      :param string $prompt: Analysis prompt
      :param VisionOptions|null $options: Vision options
      :returns: VisionResponse Complete response with usage data
      :throws: InvalidArgumentException If image URL is invalid
