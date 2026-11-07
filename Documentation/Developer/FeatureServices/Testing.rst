.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=================================
Testing feature-service consumers
=================================

.. _feature-services-testing:

Testing
========

.. _feature-services-testing-unit:

Unit tests
----------

.. code-block:: bash
   :caption: Run feature service tests

   # Run all unit tests
   Build/Scripts/runTests.sh -s unit

   # Alternative: Via Composer script
   composer ci:test:php:unit

.. _feature-services-testing-mocking:

Mocking services
----------------

.. code-block:: php
   :caption: Example: Mocking feature services in tests

   use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
   use PHPUnit\Framework\TestCase;

   class YourServiceTest extends TestCase
   {
       public function testImageEnhancement(): void
       {
           $visionMock = $this->createMock(VisionServiceInterface::class);
           $visionMock->method('generateAltText')
               ->willReturn('Test alt text');

           $service = new YourService($visionMock);
           $result = $service->enhanceImage('https://example.com/test.jpg');

           $this->assertEquals('Test alt text', $result['alt']);
       }
   }
