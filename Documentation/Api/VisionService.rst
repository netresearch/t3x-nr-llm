.. include:: /Includes.rst.txt

.. _api-vision-service:

=============
VisionService
=============

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: VisionService

   Image analysis with specialized prompts.

   .. php:method:: generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array

      Generate WCAG-compliant alt text.

      Optimized for screen readers and WCAG 2.1 Level AA
      compliance. Output is concise (under 125 characters)
      and focuses on essential information.

      :param string|array $imageUrl: URL, local path,
         or array of URLs for batch processing
      :param VisionOptions|null $options: Vision options
         (defaults: maxTokens=100, temperature=0.5)
      :returns: string|array Alt text or array of alt
         texts for batch input

   .. php:method:: generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array

      Generate SEO-optimized image title.

      Creates compelling, keyword-rich titles under 60
      characters for improved search rankings.

      :param string|array $imageUrl: URL, local path,
         or array of URLs for batch processing
      :param VisionOptions|null $options: Vision options
         (defaults: maxTokens=50, temperature=0.7)
      :returns: string|array Title or array of titles
         for batch input

   .. php:method:: generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array

      Generate detailed image description.

      Provides comprehensive analysis including subjects,
      setting, colors, mood, composition, and notable
      details.

      :param string|array $imageUrl: URL, local path,
         or array of URLs for batch processing
      :param VisionOptions|null $options: Vision options
         (defaults: maxTokens=500, temperature=0.7)
      :returns: string|array Description or array of
         descriptions for batch input

   .. php:method:: analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array

      Custom image analysis with specific prompt.

      :param string|array $imageUrl: URL, local path,
         or array of URLs for batch processing
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
