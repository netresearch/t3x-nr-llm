<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Observability;

use ArrayObject;
use Netresearch\NrLlm\Observability\GenAiTelemetry;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The SDK of the host installation, standing in for it in a unit test: spans and
 * metrics go to in-memory exporters, and the API's {@see Configurator} makes
 * them what `Globals` returns until the scope is detached (ADR-291).
 *
 * @phpstan-require-extends TestCase
 */
trait OpenTelemetryTestHarness
{
    private SpanExporter $spanExporter;

    private MetricExporter $metricExporter;

    private ExportingReader $metricReader;

    private ?ScopeInterface $otelScope = null;

    /** @var list<Metric> every metric collected so far; a collect returns only what changed since the last one */
    private array $collectedMetrics = [];

    /**
     * Registers an SDK the way a host installation would. A test that does not
     * call this runs against the API's no-op providers.
     */
    private function installOpenTelemetrySdk(): void
    {
        $this->collectedMetrics = [];
        $this->spanExporter     = new SpanExporter();
        $this->metricExporter   = new MetricExporter(new ArrayObject());
        $this->metricReader     = new ExportingReader($this->metricExporter);

        $this->otelScope = Configurator::create()
            ->withTracerProvider(new TracerProvider(new SimpleSpanProcessor($this->spanExporter)))
            ->withMeterProvider(MeterProvider::builder()->addReader($this->metricReader)->build())
            ->activate();
    }

    private function uninstallOpenTelemetrySdk(): void
    {
        $this->otelScope?->detach();
        $this->otelScope = null;
    }

    /**
     * The finished spans, in the order they ended.
     *
     * @return list<SpanDataInterface>
     */
    private function finishedSpans(): array
    {
        /** @var list<SpanDataInterface> $spans */
        $spans = array_values($this->spanExporter->getSpans());

        return $spans;
    }

    /**
     * @return list<SpanDataInterface>
     */
    private function spansNamed(string $name): array
    {
        return array_values(array_filter(
            $this->finishedSpans(),
            static fn (SpanDataInterface $span): bool => $span->getName() === $name,
        ));
    }

    /**
     * The one span of that name; fails, with the names that did finish, when
     * there is none or more than one.
     */
    private function onlySpan(string $name): SpanDataInterface
    {
        $spans = $this->spansNamed($name);
        self::assertCount(
            1,
            $spans,
            sprintf('Expected exactly one span named "%s"; finished: [%s]', $name, implode(', ', array_map(static fn (SpanDataInterface $span): string => $span->getName(), $this->finishedSpans()))),
        );

        return $spans[0];
    }

    /**
     * The data points of a histogram, or [] when the instrument recorded nothing.
     *
     * @return list<HistogramDataPoint>
     */
    private function histogramPoints(string $name): array
    {
        $this->metricReader->collect();
        foreach ($this->metricExporter->collect(true) as $metric) {
            $this->collectedMetrics[] = $metric;
        }

        $points = [];
        foreach ($this->collectedMetrics as $metric) {
            if ($metric->name === $name && $metric->data instanceof Histogram) {
                foreach ($metric->data->dataPoints as $point) {
                    $points[] = $point;
                }
            }
        }

        return $points;
    }

    /**
     * Everything a span carries as text, for a test that asserts what is NOT in it.
     */
    private function everythingRecorded(): string
    {
        $recorded = [];
        foreach ($this->finishedSpans() as $span) {
            $recorded[] = $span->getName();
            $recorded[] = $span->getAttributes()->toArray();
            foreach ($span->getEvents() as $event) {
                $recorded[] = $event->getName();
                $recorded[] = $event->getAttributes()->toArray();
            }

            $recorded[] = $span->getStatus()->getDescription();
        }

        return json_encode($recorded, JSON_THROW_ON_ERROR);
    }

    private function telemetry(bool $enabled = true): GenAiTelemetry
    {
        /** @var ExtensionConfiguration&Stub $configuration */
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['telemetry' => ['openTelemetry' => $enabled ? '1' : '0']]);

        return new GenAiTelemetry($configuration);
    }
}
