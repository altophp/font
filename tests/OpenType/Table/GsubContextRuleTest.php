<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Font\Tests\OpenType\Table;

use Alto\Font\OpenType\Table\GsubContextRule;
use Alto\Font\OpenType\Table\GsubGlyphSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GsubContextRule::class)]
final class GsubContextRuleTest extends TestCase
{
    public function testItPreservesInputsConditionsAndLookupRecords(): void
    {
        $input = GsubGlyphSet::explicit([2]);
        $condition = GsubGlyphSet::explicit([1, 2, 3]);
        $records = [
            ['sequenceIndex' => 0, 'lookupIndex' => 4],
            ['sequenceIndex' => 1, 'lookupIndex' => 7],
        ];

        $rule = new GsubContextRule([$input], [$condition], $records);

        self::assertSame([$input], $rule->inputs);
        self::assertSame([$condition], $rule->conditions);
        self::assertSame($records, $rule->lookupRecords);
    }
}
