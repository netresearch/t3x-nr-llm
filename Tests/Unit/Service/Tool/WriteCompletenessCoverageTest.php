<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every write this extension makes states whether it did all it planned
 * (ADR-214, item 9).
 *
 * A list of partial branches cannot be complete: a test can only find the
 * flags that are set, and a branch that forgets one looks like success. So
 * the rule is put on the call instead — every `withWriteTarget(` call under
 * `Classes/` passes a completeness argument, positionally or by name, never
 * through a spread or as a first-class callable, and no branch of it is the
 * literal `null`: not the argument itself, not a branch of a ternary, the
 * right side of `??`, an arm of a `match` or the value of an assignment. Any other expression is accepted, because a tool may
 * decide in a helper (`ReplaceFileReferenceTool` decides in
 * `settleTranslations()`).
 *
 * What this cannot see: a variable or a helper whose value is null at run
 * time, and a call whose method name is itself an expression
 * (`$r->{$name}(...)`), which it does not recognise as this method. The parameter is `?WriteCompleteness` (it must be, ADR-182 freezes the
 * signature), so neither PHP nor this test refuses that; a helper that
 * decides returns the non-nullable enum, and that return type is what holds
 * it, under PHPStan level 10.
 *
 * Read from the syntax tree, not from the text: the method's own declaration
 * and its docblock in ToolResult.php are not calls and never match, and a
 * call split over several lines or carrying a nested call in an argument is
 * read as one. The parser is nikic/php-parser, which the dev dependencies
 * already carry.
 */
#[CoversClass(ToolResult::class)]
final class WriteCompletenessCoverageTest extends TestCase
{
    private const METHOD = 'withWriteTarget';

    private const PARAMETER = 'completeness';

    /** The position of the completeness among the method's parameters. */
    private const POSITION = 2;

    /**
     * The number of builtin call sites when this test was written. A floor, not
     * an exact count: a new writer raises it, and a parser that suddenly finds
     * nothing must fail rather than pass on an empty set.
     */
    private const MINIMUM_CALL_SITES = 20;

    #[Test]
    public function everyWriteTargetCallUnderClassesStatesACompleteness(): void
    {
        $sites   = $this->callSites(dirname(__DIR__, 4) . '/Classes');
        $missing = [];

        foreach ($sites as $site) {
            $reason = $this->refusal($site['call']);
            if ($reason !== null) {
                $missing[] = sprintf('%s:%d %s', $site['file'], $site['call']->getStartLine(), $reason);
            }
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_CALL_SITES,
            count($sites),
            'Fewer withWriteTarget() calls than the builtins make — the scan is reading the wrong tree.',
        );
        self::assertSame(
            [],
            $missing,
            "A write names its record without saying whether it did everything it planned (ADR-214). Pass\n"
            . "WriteCompleteness::COMPLETE or ::PARTIAL — decided for this return — as the third argument:\n"
            . implode("\n", $missing),
        );
    }

    /**
     * The check refuses both shapes it exists for and accepts the ones a tool
     * legitimately writes, so a green run above means something.
     */
    #[Test]
    public function theCheckRefusesAnOmittedOrNullCompletenessAndAcceptsAnExpression(): void
    {
        $cases = [
            '$r->withWriteTarget($t, WriteKind::UPDATED);'                                         => 'omits the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, null);'                                   => 'passes null as the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, NULL);'                                   => 'passes null as the completeness',
            '$r->withWriteTarget(kind: WriteKind::UPDATED, target: $t);'                           => 'omits the completeness',
            '$r->withWriteTarget(kind: WriteKind::UPDATED, target: $t, completeness: null);'       => 'passes null as the completeness',
            '$r?->withWriteTarget($t, WriteKind::UPDATED);'                                        => 'omits the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, $ok ? WriteCompleteness::COMPLETE : null);' => 'passes null as the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, $ok ? null : WriteCompleteness::PARTIAL);' => 'passes null as the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, $c ?? null);'                             => 'passes null as the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, match ($x) { 1 => WriteCompleteness::COMPLETE, default => null });' => 'passes null as the completeness',
            '$r->withWriteTarget(...$args);'                                                       => 'spreads its arguments, so the completeness cannot be read',
            '$r->withWriteTarget(...);'                                                            => 'takes the method as a callable, so no completeness is passed here',
            '$r->withWriteTarget($t, WriteKind::UPDATED, $c = null);'                              => 'passes null as the completeness',
            '$r->withWriteTarget($t, WriteKind::UPDATED, $c ??= null);'                            => 'passes null as the completeness',
            '$r->withWriteTarget($t, ...$rest);'                                                   => 'spreads its arguments, so the completeness cannot be read',
            '$r->withWriteTarget($t, WriteKind::UPDATED, $c ?? WriteCompleteness::PARTIAL);'       => null,
            '$r->withWriteTarget($t, WriteKind::UPDATED, WriteCompleteness::PARTIAL);'             => null,
            '$r->withWriteTarget($t, WriteKind::UPDATED, $settled[\'completeness\']);'             => null,
            '$r->withWriteTarget($t, WriteKind::UPDATED, $ok ? WriteCompleteness::COMPLETE : WriteCompleteness::PARTIAL);' => null,
            '$r->withWriteTarget(completeness: WriteCompleteness::COMPLETE, kind: WriteKind::UPDATED, target: $t);' => null,
        ];

        foreach ($cases as $code => $expected) {
            $calls = $this->callsIn('<?php ' . $code);
            self::assertCount(1, $calls, $code);
            self::assertSame($expected, $this->refusal($calls[0]), $code);
        }

        // A declaration and a docblock are not calls.
        self::assertSame([], $this->callsIn(
            "<?php class X {\n /** Call ->withWriteTarget(\$t, \$k) like this. */\n"
            . ' public function withWriteTarget($t, $k, $completeness = null) {} }',
        ));
    }

    /**
     * Why the call fails the rule, or null when it states a completeness.
     */
    private function refusal(MethodCall|NullsafeMethodCall $call): ?string
    {
        if ($call->isFirstClassCallable()) {
            return 'takes the method as a callable, so no completeness is passed here';
        }

        $argument = null;
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return 'spreads its arguments, so the completeness cannot be read';
            }

            if ($arg->name instanceof Identifier ? $arg->name->toString() === self::PARAMETER : $position === self::POSITION) {
                $argument = $arg->value;
            }
        }

        if (!$argument instanceof Node) {
            return 'omits the completeness';
        }

        return $this->canBeNull($argument) ? 'passes null as the completeness' : null;
    }

    /**
     * Whether a literal `null` is one of the values the expression can take.
     */
    private function canBeNull(Node $expression): bool
    {
        if ($expression instanceof ConstFetch) {
            return $expression->name->toLowerString() === 'null';
        }

        if ($expression instanceof Ternary) {
            return ($expression->if instanceof Node ? $this->canBeNull($expression->if) : $this->canBeNull($expression->cond))
                || $this->canBeNull($expression->else);
        }

        if ($expression instanceof Coalesce) {
            return $this->canBeNull($expression->right);
        }

        // `$c = null` and `$c ??= null` pass the value they assign.
        if ($expression instanceof Assign || $expression instanceof AssignCoalesce) {
            return $this->canBeNull($expression->expr);
        }

        if ($expression instanceof Match_) {
            foreach ($expression->arms as $arm) {
                if ($this->canBeNull($arm->body)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{file: string, call: MethodCall|NullsafeMethodCall}>
     */
    private function callSites(string $directory): array
    {
        $sites = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach ($this->callsIn($source) as $call) {
                $sites[] = ['file' => substr($file->getPathname(), strlen($directory) + 1), 'call' => $call];
            }
        }

        return $sites;
    }

    /**
     * @return list<MethodCall|NullsafeMethodCall>
     */
    private function callsIn(string $source): array
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse($source);
        self::assertIsArray($ast);

        $calls = [];
        foreach ((new NodeFinder())->find($ast, static fn(Node $node): bool => ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === strtolower(self::METHOD)) as $call) {
            if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
                $calls[] = $call;
            }
        }

        return $calls;
    }
}
