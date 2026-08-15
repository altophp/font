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

namespace Alto\Font\Tests\Writer;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Font;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Writer\ExclusiveFileWriter;
use Alto\Font\Writer\WoffWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WoffWriter::class)]
#[CoversClass(ExclusiveFileWriter::class)]
final class WoffWriterTest extends TestCase
{
    public function testItDumpsAReloadableWoffContainer(): void
    {
        $source = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($source);
        $font = Font::fromFile($source);

        $woff = new WoffWriter()->dump($font);
        $reader = new BinaryReader($woff, 'test WOFF');

        self::assertSame('wOFF', substr($woff, 0, 4));
        self::assertSame(\strlen($woff), $reader->uint32(8));
        self::assertSame(\strlen($font->toSfnt()), $reader->uint32(16));
        self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($woff))->getDescriptor()->family);
    }

    public function testItCompressesTablesOnlyWhenTheResultIsSmaller(): void
    {
        $source = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($source);
        $reader = new BinaryReader(new WoffWriter()->dump(Font::fromFile($source)), 'test WOFF');
        $numTables = $reader->uint16(12);
        $compressedTableCount = 0;
        $uncompressedTableCount = 0;

        for ($index = 0; $index < $numTables; ++$index) {
            $recordOffset = 44 + $index * 20;
            $compressedLength = $reader->uint32($recordOffset + 8);
            $originalLength = $reader->uint32($recordOffset + 12);
            self::assertLessThanOrEqual($originalLength, $compressedLength);

            if ($compressedLength < $originalLength) {
                ++$compressedTableCount;
            } else {
                ++$uncompressedTableCount;
            }
        }

        self::assertGreaterThan(0, $compressedTableCount);
        self::assertGreaterThan(0, $uncompressedTableCount);
    }

    public function testItWritesANewWoffFile(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('destination.woff');
        TinyTrueTypeFont::write($source);

        new WoffWriter()->write(Font::fromFile($source), $destination);

        self::assertSame('Atelier Tiny', Font::fromFile($destination)->getDescriptor()->family);
    }

    public function testItRemovesDsigWhenReconstructingTheFont(): void
    {
        $source = self::temporaryPath('signed.ttf');
        TinyTrueTypeFont::writeWithDsig($source);

        $woff = new WoffWriter()->dump(Font::fromFile($source));
        $font = Font::fromFile(self::writeDump($woff));

        self::assertNotContains('DSIG', $font->face()->tables);
    }

    private static function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/alto-font-woff-writer-' . bin2hex(random_bytes(4)) . '-' . $suffix;
    }

    private static function writeDump(string $data): string
    {
        $path = self::temporaryPath('dump.woff');
        file_put_contents($path, $data);

        return $path;
    }
}
