<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Security;

use Eris\Generator;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Property-based coverage of the writing tool's argument gate (ADR-135).
 *
 * The arguments are model-chosen and therefore attacker-influenceable, and the
 * refusal they produce egresses to the provider AND the backend DOM. Two
 * properties are asserted over arbitrary input: a field name outside the fixed
 * allow-list is ALWAYS refused, and whatever of that name is echoed back carries
 * nothing but identifier characters.
 *
 * Every generated case stops before the database, which is what makes a stub
 * connection pool sound here.
 */
#[CoversNothing]
final class UpdatePageMetadataArgumentsFuzzyTest extends AbstractFuzzyTestCase
{
    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    private UpdatePageMetadataTool $tool;

    private ToolExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->globalsBackup = [
            'TCA'     => $GLOBALS['TCA'] ?? null,
            'LANG'    => $GLOBALS['LANG'] ?? null,
            'BE_USER' => $GLOBALS['BE_USER'] ?? null,
        ];

        $GLOBALS['TCA'] = ['pages' => ['columns' => [
            // `required` as in the core TCA — an empty value for it is dropped by
            // the DataHandler without a trace, so the gate has to catch it.
            'title'       => ['config' => ['type' => 'input', 'max' => 255, 'required' => true]],
            'description' => ['config' => ['type' => 'text']],
        ]]];

        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $user;

        $this->tool    = new UpdatePageMetadataTool(self::createStub(ConnectionPool::class));
        $this->context = ToolExecutionContext::fromBackendUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);

                continue;
            }

            $GLOBALS[$key] = $value;
        }

        parent::tearDown();
    }

    #[Test]
    public function anyFieldOutsideTheAllowListIsRefusedAndEchoedBackSanitised(): void
    {
        $this
            ->forAll(
                // A prefix keeps the generated name a STRING key that can never
                // collide with `uid` or an allow-listed field; everything after
                // it stays arbitrary, which is the part under test.
                // @phpstan-ignore function.notFound (Eris Generator loaded at runtime)
                Generator\map(
                    static fn(string $suffix): string => 'x_' . $suffix,
                    // @phpstan-ignore function.notFound
                    Generator\string(),
                ),
                // @phpstan-ignore function.notFound
                Generator\string(),
            )
            ->then(function (string $field, string $value): void {
                $result = $this->tool->execute(['uid' => 1, $field => $value], $this->context);

                self::assertTrue($result->isError, sprintf('field "%s" must be refused', $field));
                self::assertStringContainsString('not an editable page metadata field', $result->content);

                // Whatever of the model-chosen name survives into the message
                // carries no markup, no quotes, no control characters — the
                // message reaches both the provider wire and the rendered DOM.
                self::assertMatchesRegularExpression(
                    '/^Refused: "[A-Za-z0-9_]*" is not an editable page metadata field\. Allowed: [a-z_, ]+\.$/',
                    $result->content,
                );
            });
    }

    /**
     * The twin of the property above for the ADR-136 preview: it is a SECOND
     * public entry taking the same model-chosen arguments, and its output
     * reaches the approval card. The refusal it produces must be sanitised
     * exactly like the executed one, or the same input would be safe on one path
     * and not on the other.
     */
    #[Test]
    public function anyFieldOutsideTheAllowListIsRefusedByThePreviewToo(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound (Eris Generator loaded at runtime)
                Generator\map(
                    static fn(string $suffix): string => 'x_' . $suffix,
                    // @phpstan-ignore function.notFound
                    Generator\string(),
                ),
                // @phpstan-ignore function.notFound
                Generator\string(),
            )
            ->then(function (string $field, string $value): void {
                $lines = $this->tool->previewCall(['uid' => 1, $field => $value], $this->context);

                self::assertCount(1, $lines);
                self::assertMatchesRegularExpression(
                    '/^Refused: "[A-Za-z0-9_]*" is not an editable page metadata field\. Allowed: [a-z_, ]+\.$/',
                    $lines[0],
                );
            });
    }

    #[Test]
    public function anyValueBeyondTheDeclaredBoundIsRefusedWithoutWriting(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\choose(256, 4000),
            )
            ->then(function (int $length): void {
                $result = $this->tool->execute(
                    ['uid' => 1, 'title' => str_repeat('x', $length)],
                    $this->context,
                );

                self::assertTrue($result->isError);
                self::assertStringContainsString('exceeds 255 characters', $result->content);
            });
    }

    /**
     * Whatever run of whitespace a model sends, a required field is refused with
     * its real reason rather than written and then misreported: the DataHandler
     * trims and drops such a value without an `errorLog` entry.
     */
    #[Test]
    public function anyWhitespaceOnlyValueForARequiredFieldIsRefused(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound (Eris Generator loaded at runtime)
                Generator\seq(
                    // @phpstan-ignore function.notFound
                    Generator\elements([' ', "\t", "\n", "\r", "\v", "\0"]),
                ),
            )
            ->then(
                function (array $characters): void {
                    $whitespace = '';
                    foreach ($characters as $character) {
                        $whitespace .= is_string($character) ? $character : '';
                    }

                    $result = $this->tool->execute(
                        ['uid' => 1, 'title' => $whitespace],
                        $this->context,
                    );

                    self::assertTrue($result->isError);
                    self::assertStringContainsString('is required and cannot be emptied', $result->content);
                },
            );
    }

    #[Test]
    public function aNonPositiveUidIsAlwaysRefused(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\choose(-10000, 0),
            )
            ->then(function (int $uid): void {
                $result = $this->tool->execute(['uid' => $uid, 'title' => 'x'], $this->context);

                self::assertTrue($result->isError);
                self::assertStringContainsString('exactly one page', $result->content);
            });
    }
}
