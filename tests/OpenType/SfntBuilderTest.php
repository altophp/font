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

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\OpenType\SfntBuilder;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SfntBuilder::class)]
final class SfntBuilderTest extends TestCase
{
    public function testItBuildsSortedTableRecordsAndAdjustsTheFontChecksum(): void
    {
        $head = str_repeat("\0", 54);
        $sfnt = SfntBuilder::build("\x00\x01\x00\x00", [
            'name' => 'Alto',
            'head' => $head,
        ]);
        $numberOfTables = unpack('nvalue', substr($sfnt, 4, 2));

        self::assertSame("\x00\x01\x00\x00", substr($sfnt, 0, 4));
        self::assertIsArray($numberOfTables);
        self::assertSame(2, $numberOfTables['value']);
        self::assertSame('head', substr($sfnt, 12, 4));
        self::assertSame('name', substr($sfnt, 28, 4));
        self::assertSame(0xB1B0AFBA, self::checksum($sfnt));
    }

    public function testItRejectsAnInvalidFlavor(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('flavor must contain exactly 4 bytes');

        SfntBuilder::build('ttf', ['head' => str_repeat("\0", 54)]);
    }

    public function testItRejectsAnEmptyTableSet(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('at least one table');

        SfntBuilder::build("\x00\x01\x00\x00", []);
    }

    public function testItRejectsAnInvalidTableTag(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('must contain exactly 4 bytes');

        SfntBuilder::build("\x00\x01\x00\x00", ['names' => 'Alto']);
    }

    public function testItRejectsATruncatedHeadTable(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('head table is truncated');

        SfntBuilder::build("\x00\x01\x00\x00", ['head' => "\0"]);
    }

    public function testItAdjustsAnImmutableDocumentChecksum(): void
    {
        $document = self::document();
        $adjusted = SfntBuilder::withChecksumAdjustment($document);
        $head = $adjusted->table('head');

        self::assertIsString($head);
        self::assertNotSame("\0\0\0\0", substr($head, 8, 4));
        self::assertSame(0xB1B0AFBA, self::checksum($adjusted->toSfnt()));
        self::assertSame($document->table('name'), $adjusted->table('name'));
    }

    public function testChecksumAdjustmentRequiresACompleteHeadTable(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('head table is truncated');

        SfntBuilder::withChecksumAdjustment(self::document()->withTables(['head' => "\0"]));
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - \strlen($data) % 4) % 4);
        $sum = 0;

        for ($offset = 0; $offset < \strlen($data); $offset += 4) {
            $word = unpack('Nvalue', substr($data, $offset, 4));
            $value = false === $word ? null : $word['value'] ?? null;

            if (!\is_int($value)) {
                self::fail('Could not calculate test font checksum.');
            }

            $sum = ($sum + $value) & 0xFFFFFFFF;
        }

        return $sum;
    }

    private static function document(): \Alto\Font\OpenType\SfntDocument
    {
        $path = sys_get_temp_dir() . '/alto-font-sfnt-builder-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::write($path);

        return Font::fromFile($path)->sfntDocument();
    }
}
