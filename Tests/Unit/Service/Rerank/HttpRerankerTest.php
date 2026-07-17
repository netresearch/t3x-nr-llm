<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Rerank;

use Netresearch\NrLlm\Service\Rerank\Exception\RerankerException;
use Netresearch\NrLlm\Service\Rerank\HttpReranker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

#[CoversClass(HttpReranker::class)]
final class HttpRerankerTest extends TestCase
{
    /** @var list<array{url: string, method: string, options: array<string, mixed>}> */
    private array $requests = [];

    #[Test]
    public function sendsTheSidecarProtocolRequestAndMapsScores(): void
    {
        $subject = $this->buildSubject('{"scores": [{"id": "a", "score": 0.87}, {"id": "b", "score": -1}]}', endpoint: 'https://reranker:8081/');

        $result = $subject->rerank('what is bim?', [
            ['id' => 'a', 'text' => 'BIM is building information modeling.'],
            ['id' => 'b', 'text' => 'Newsletter subscription form.'],
        ]);

        self::assertSame([
            ['id' => 'a', 'score' => 0.87],
            ['id' => 'b', 'score' => -1.0],
        ], $result);

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('https://reranker:8081/rerank', $request['url'], 'trailing endpoint slash must not double');
        self::assertSame('POST', $request['method']);
        self::assertSame(
            [
                'query' => 'what is bim?',
                'documents' => [
                    ['id' => 'a', 'text' => 'BIM is building information modeling.'],
                    ['id' => 'b', 'text' => 'Newsletter subscription form.'],
                ],
            ],
            $request['options']['json'],
        );
        self::assertSame(['Accept' => 'application/json'], $request['options']['headers']);
        self::assertSame(12.5, $request['options']['timeout']);
        self::assertFalse($request['options']['http_errors'], 'non-200 must return a response, not throw');
    }

    #[Test]
    public function emptyCandidatesReturnEmptyListWithoutARequest(): void
    {
        $subject = $this->buildSubject('{"scores": []}');

        self::assertSame([], $subject->rerank('query', []));
        self::assertCount(0, $this->requests);
    }

    #[Test]
    public function poolsAboveTheBatchCapAreSplitIntoSequentialRequests(): void
    {
        $candidates = [];
        for ($i = 0; $i < HttpReranker::MAX_DOCUMENTS_PER_REQUEST + 1; ++$i) {
            $candidates[] = ['id' => 'c' . $i, 'text' => 'passage ' . $i];
        }

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url, string $method, array $options): ResponseInterface {
                /** @var array<string, mixed> $captured */
                $captured = $options;
                $this->requests[] = ['url' => $url, 'method' => $method, 'options' => $captured];
                /** @var array{documents: list<array{id: string, text: string}>} $payload */
                $payload = $options['json'];
                $scores  = array_map(
                    static fn(array $document): array => ['id' => $document['id'], 'score' => 0.5],
                    $payload['documents'],
                );

                return $this->jsonResponse(json_encode(['scores' => $scores], JSON_THROW_ON_ERROR));
            },
        );

        $result = (new HttpReranker($requestFactory, 'https://reranker:8081', 30.0))->rerank('query', $candidates);

        self::assertCount(2, $this->requests);
        self::assertCount(HttpReranker::MAX_DOCUMENTS_PER_REQUEST, $this->sentDocuments(0));
        self::assertCount(1, $this->sentDocuments(1));
        self::assertCount(HttpReranker::MAX_DOCUMENTS_PER_REQUEST + 1, $result);
        self::assertSame(['id' => 'c0', 'score' => 0.5], $result[0]);
        self::assertSame(['id' => 'c' . HttpReranker::MAX_DOCUMENTS_PER_REQUEST, 'score' => 0.5], $result[HttpReranker::MAX_DOCUMENTS_PER_REQUEST]);
    }

    #[Test]
    public function transportFailureThrowsTypedException(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(
            new class ('connection refused') extends RuntimeException implements ClientExceptionInterface {},
        );
        $subject = new HttpReranker($requestFactory, 'https://reranker:8081', 30.0);

        $this->expectException(RerankerException::class);
        $this->expectExceptionCode(1784750001);

        $subject->rerank('query', [['id' => 'a', 'text' => 'passage']]);
    }

    #[Test]
    public function nonOkStatusThrowsTypedException(): void
    {
        $subject = $this->buildSubject('{"error": "too many documents (max 128)"}', status: 413);

        try {
            $subject->rerank('query', [['id' => 'a', 'text' => 'passage']]);
            self::fail('Expected RerankerException for HTTP 413');
        } catch (RerankerException $e) {
            self::assertSame(1784750002, $e->getCode(), 'status code, not transport code 1784750001');
        }

        self::assertFalse($this->requests[0]['options']['http_errors'], 'status branch must see the 413 response');
    }

    #[Test]
    public function invalidJsonBodyThrowsTypedException(): void
    {
        $subject = $this->buildSubject('not json');

        $this->expectException(RerankerException::class);
        $this->expectExceptionCode(1784750003);

        $subject->rerank('query', [['id' => 'a', 'text' => 'passage']]);
    }

    #[Test]
    public function missingScoresArrayThrowsTypedException(): void
    {
        $subject = $this->buildSubject('{"status": "ok"}');

        $this->expectException(RerankerException::class);
        $this->expectExceptionCode(1784750004);

        $subject->rerank('query', [['id' => 'a', 'text' => 'passage']]);
    }

    #[Test]
    public function malformedScoreEntriesAreSkipped(): void
    {
        $subject = $this->buildSubject(
            '{"scores": [{"id": "a", "score": 0.9}, {"id": 7, "score": 0.5}, {"id": "c"}, "junk", {"id": "d", "score": "0.4"}]}',
        );

        $result = $subject->rerank('query', [['id' => 'a', 'text' => 'passage']]);

        self::assertSame([['id' => 'a', 'score' => 0.9]], $result);
    }

    /**
     * @return array<mixed>
     */
    private function sentDocuments(int $index): array
    {
        $payload = $this->requests[$index]['options']['json'] ?? null;
        self::assertIsArray($payload);
        $documents = $payload['documents'] ?? null;
        self::assertIsArray($documents);

        return $documents;
    }

    private function buildSubject(string $responseBody, int $status = 200, string $endpoint = 'https://reranker:8081', float $timeout = 12.5): HttpReranker
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url, string $method, array $options) use ($responseBody, $status): ResponseInterface {
                /** @var array<string, mixed> $captured */
                $captured = $options;
                $this->requests[] = ['url' => $url, 'method' => $method, 'options' => $captured];

                return $this->jsonResponse($responseBody, $status);
            },
        );

        return new HttpReranker($requestFactory, $endpoint, $timeout);
    }

    private function jsonResponse(string $body, int $status = 200): ResponseInterface
    {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
