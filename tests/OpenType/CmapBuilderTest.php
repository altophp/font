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
use Alto\Font\OpenType\CmapBuilder;
use Alto\Font\OpenType\Table\CmapTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CmapBuilder::class)]
final class CmapBuilderTest extends TestCase
{
    public function testItBuildsBmpAndSupplementaryUnicodeMappings(): void
    {
        $data = CmapBuilder::build([
            0x1F600 => 7,
            0x0042 => 4,
            0x0041 => 3,
        ]);
        $reader = new BinaryReader($data, 'test cmap');
        $cmap = CmapTable::parse($reader);

        self::assertSame(4, $reader->uint16(2));
        self::assertSame(3, $cmap->glyphIdForCodepoint(0x0041));
        self::assertSame(4, $cmap->glyphIdForCodepoint(0x0042));
        self::assertSame(7, $cmap->glyphIdForCodepoint(0x1F600));
        self::assertNull($cmap->glyphIdForCodepoint(0x0043));
    }

    public function testItBuildsAnEmptyUnicodeMap(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(CmapBuilder::build([]), 'empty cmap'));

        self::assertSame([], $cmap->mappings());
    }
}
