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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Font;
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\HvarCompactor;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\Table\HvarTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HvarCompactor::class)]
final class HvarCompactorTest extends TestCase
{
    public function testItRemapsExplicitDeltaSetIndexMaps(): void
    {
        $font = self::variableFont();
        $hvar = $font->sfntDocument()->table('HVAR');
        $variations = $font->variations();
        self::assertNotNull($hvar);
        self::assertNotNull($variations);

        $compacted = HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [4 => true]));
        $table = HvarTable::parse(new BinaryReader($compacted, 'compacted HVAR'), $variations);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame(100.0, $table->advanceWidthDelta(0, $coordinates));
        self::assertSame(100.0, $table->advanceWidthDelta(1, $coordinates));
        self::assertSame(5.0, $table->leftSideBearingDelta(1, $coordinates));
    }

    public function testItMaterializesAnImplicitAdvanceMapping(): void
    {
        $font = self::variableFont();
        $hvar = $font->sfntDocument()->table('HVAR');
        $variations = $font->variations();
        self::assertNotNull($hvar);
        self::assertNotNull($variations);
        $hvar = substr_replace($hvar, pack('N', 0), 8, 4);

        $compacted = HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
        $table = HvarTable::parse(new BinaryReader($compacted, 'implicit compacted HVAR'), $variations);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame(100.0, $table->advanceWidthDelta(0, $coordinates));
        self::assertSame(5.0, $table->advanceWidthDelta(1, $coordinates));
    }

    private static function variableFont(): Font
    {
        $path = sys_get_temp_dir() . '/alto-font-hvar-compactor-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($path);

        return Font::fromFile($path);
    }
}
