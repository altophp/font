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
use Alto\Font\Compression\BrotliCompressorInterface;
use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Compression\BrotliStreamCompressorInterface;
use Alto\Font\Exception\FontWriteException;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\OpenType\Woff2KnownTags;
use Alto\Font\OpenType\Woff2TransformEncoder;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Writer\ExclusiveFileWriter;
use Alto\Font\Writer\Woff2Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Woff2Writer::class)]
#[CoversClass(Woff2KnownTags::class)]
#[CoversClass(Woff2TransformEncoder::class)]
#[CoversClass(ExclusiveFileWriter::class)]
final class Woff2WriterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required.');
        }
    }

    public function testItDumpsAReloadableTransformedWoff2Container(): void
    {
        $source = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($source);
        $font = Font::fromFile($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump($font);
        $reader = new BinaryReader($woff2, 'test WOFF2');

        self::assertSame('wOF2', substr($woff2, 0, 4));
        self::assertSame(\strlen($woff2), $reader->uint32(8));
        self::assertSame(0, \strlen($woff2) % 4);
        $destination = Font::fromFile(self::writeDump($woff2));
        self::assertSame(\strlen($destination->toSfnt()), $reader->uint32(16));
        self::assertSame('Atelier Tiny', $destination->getDescriptor()->family);
        self::assertSame(600, $destination->glyphMetrics(new GlyphId(1))->advanceWidth);
        self::assertCount(1, $destination->glyphOutline(new GlyphId(1))->contours);
        self::assertCount(1, $destination->glyphOutline(new GlyphId(4))->contours);

        $entries = self::woff2Entries($woff2);
        $glyfIndex = array_search('glyf', array_column($entries, 'tag'), true);
        self::assertIsInt($glyfIndex);
        self::assertSame(0, $entries[$glyfIndex]['transformVersion']);
        self::assertNotNull($entries[$glyfIndex]['transformLength']);
        self::assertSame('loca', $entries[$glyfIndex + 1]['tag']);
        self::assertSame(0, $entries[$glyfIndex + 1]['transformVersion']);
        self::assertSame(0, $entries[$glyfIndex + 1]['transformLength']);

        $sourceTables = self::sfntTables($font->toSfnt());
        self::assertSame(\strlen($sourceTables['glyf']), $entries[$glyfIndex]['originalLength']);
        self::assertSame(\strlen($sourceTables['loca']), $entries[$glyfIndex + 1]['originalLength']);

        $hmtxEntries = array_values(array_filter($entries, static fn(array $entry): bool => 'hmtx' === $entry['tag']));
        self::assertCount(1, $hmtxEntries);
        self::assertSame(1, $hmtxEntries[0]['transformVersion']);
        self::assertSame(\strlen($sourceTables['hmtx']), $hmtxEntries[0]['originalLength']);
        self::assertNotNull($hmtxEntries[0]['transformLength']);
    }

    public function testItReportsTheExactReconstructedSfntSizeForInter(): void
    {
        $font = Font::fromFile(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump($font);
        $reader = new BinaryReader($woff2, 'Inter WOFF2');
        $reconstructed = Font::fromFile(self::writeDump($woff2))->toSfnt();

        self::assertSame(0, self::woff2Entries($woff2)[self::woff2EntryIndex($woff2, 'glyf')]['transformVersion']);
        self::assertSame(\strlen($reconstructed), $reader->uint32(16));
    }

    public function testItRoundTripsCompositeGlyphInstructionsThroughTheTransform(): void
    {
        $source = self::temporaryPath('hinted-composite.ttf');
        TinyTrueTypeFont::writeCompoundWithInstructions($source);
        $font = Font::fromFile($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump($font);
        $destination = Font::fromFile(self::writeDump($woff2));

        self::assertEquals($font->glyphMetrics(new GlyphId(4)), $destination->glyphMetrics(new GlyphId(4)));
        self::assertEquals($font->glyphOutline(new GlyphId(4)), $destination->glyphOutline(new GlyphId(4)));
        self::assertSame(0, self::woff2Entries($woff2)[self::woff2EntryIndex($woff2, 'glyf')]['transformVersion']);
    }

    public function testItUsesNullGlyfAndLocaTransformsForUnsupportedCubicPoints(): void
    {
        $source = self::temporaryPath('cubic.ttf');
        TinyTrueTypeFont::writeWithCubicPoint($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump(Font::fromFile($source));
        $entries = self::woff2Entries($woff2);

        self::assertSame(3, $entries[self::woff2EntryIndex($woff2, 'glyf')]['transformVersion']);
        self::assertSame(3, $entries[self::woff2EntryIndex($woff2, 'loca')]['transformVersion']);
        self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($woff2))->getDescriptor()->family);
    }

    public function testItKeepsTheGlyfTransformWithinShortLocaBounds(): void
    {
        $source = self::temporaryPath('expanding-short-loca.ttf');
        TinyTrueTypeFont::writeWithExpandingShortLoca($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump(Font::fromFile($source));
        $entries = self::woff2Entries($woff2);

        self::assertSame(0, $entries[self::woff2EntryIndex($woff2, 'glyf')]['transformVersion']);
        self::assertSame(0, $entries[self::woff2EntryIndex($woff2, 'loca')]['transformVersion']);
        self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($woff2))->getDescriptor()->family);
    }

    public function testItUsesNullTransformsForAZeroContourGlyphWithData(): void
    {
        $source = self::temporaryPath('zero-contour-data.ttf');
        TinyTrueTypeFont::writeWithZeroContourGlyphData($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump(Font::fromFile($source));

        self::assertSame(3, self::woff2Entries($woff2)[self::woff2EntryIndex($woff2, 'glyf')]['transformVersion']);
        self::assertSame(3, self::woff2Entries($woff2)[self::woff2EntryIndex($woff2, 'loca')]['transformVersion']);
        self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($woff2))->getDescriptor()->family);
    }

    public function testItUsesNullTransformsForUnexpectedTrailingGlyphData(): void
    {
        $writers = [
            TinyTrueTypeFont::writeWithTrailingSimpleGlyphData(...),
            TinyTrueTypeFont::writeWithTrailingCompositeGlyphData(...),
        ];

        foreach ($writers as $write) {
            $source = self::temporaryPath('trailing-glyph-data.ttf');
            $write($source);

            $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump(Font::fromFile($source));

            self::assertSame(3, self::woff2Entries($woff2)[self::woff2EntryIndex($woff2, 'glyf')]['transformVersion']);
            self::assertSame(3, self::woff2Entries($woff2)[self::woff2EntryIndex($woff2, 'loca')]['transformVersion']);
            self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($woff2))->getDescriptor()->family);
        }
    }

    public function testItWritesANewWoff2File(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('destination.woff2');
        TinyTrueTypeFont::write($source);

        new Woff2Writer(new BrotliProcessCompressor())->write(Font::fromFile($source), $destination);

        self::assertSame('Atelier Tiny', Font::fromFile($destination)->getDescriptor()->family);
    }

    public function testWritingUsesTheStreamingCompressionBoundary(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('streamed.woff2');
        TinyTrueTypeFont::write($source);
        $compressor = new class implements BrotliStreamCompressorInterface {
            public bool $stringCalled = false;
            public bool $streamCalled = false;

            public function compress(string $data): string
            {
                $this->stringCalled = true;

                throw new \LogicException('String compression must not be used while writing.');
            }

            public function compressStream($input, $output): void
            {
                $this->streamCalled = true;
                new BrotliProcessCompressor()->compressStream($input, $output);
            }
        };

        new Woff2Writer($compressor)->write(Font::fromFile($source), $destination);

        self::assertTrue($compressor->streamCalled);
        self::assertFalse($compressor->stringCalled);
        self::assertSame('Atelier Tiny', Font::fromFile($destination)->getDescriptor()->family);
    }

    public function testItRemovesDsigAndSetsTheWoff2LosslessTransformFlag(): void
    {
        $source = self::temporaryPath('signed.ttf');
        TinyTrueTypeFont::writeWithDsig($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump(Font::fromFile($source));
        $sfnt = Font::fromFile(self::writeDump($woff2))->toSfnt();
        $tables = self::sfntTables($sfnt);

        self::assertArrayNotHasKey('DSIG', $tables);
        self::assertArrayHasKey('head', $tables);
        self::assertSame(0x0800, (new BinaryReader($tables['head'], 'test head'))->uint16(16) & 0x0800);
    }

    public function testTheDecoderRejectsANonZeroReservedHeaderField(): void
    {
        $source = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($source);
        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump(Font::fromFile($source));

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('WOFF2 reserved header field must be zero.');

        Font::fromFile(self::writeDump(substr_replace($woff2, "\0\1", 14, 2)));
    }

    public function testItRejectsAnExistingDestinationBeforeCompression(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('destination.woff2');
        TinyTrueTypeFont::write($source);
        file_put_contents($destination, 'keep me');
        $compressor = new class implements BrotliCompressorInterface {
            public bool $called = false;

            public function compress(string $data): string
            {
                $this->called = true;

                return $data;
            }
        };

        try {
            new Woff2Writer($compressor)->write(Font::fromFile($source), $destination);
            self::fail('Expected an existing destination to be rejected.');
        } catch (FontWriteException $error) {
            self::assertStringContainsString('destination already exists', $error->getMessage());
        }

        self::assertFalse($compressor->called);
        self::assertSame('keep me', file_get_contents($destination));
    }

    private static function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/alto-font-woff2-writer-' . bin2hex(random_bytes(4)) . '-' . $suffix;
    }

    private static function writeDump(string $data): string
    {
        $path = self::temporaryPath('dump.woff2');
        file_put_contents($path, $data);

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private static function sfntTables(string $sfnt): array
    {
        $reader = new BinaryReader($sfnt, 'test SFNT');
        $tables = [];

        for ($index = 0; $index < $reader->uint16(4); ++$index) {
            $recordOffset = 12 + $index * 16;
            $tag = $reader->string($recordOffset, 4);
            $tables[$tag] = $reader->string($reader->uint32($recordOffset + 8), $reader->uint32($recordOffset + 12));
        }

        return $tables;
    }

    /**
     * @return list<array{tag: string, originalLength: int, transformLength: ?int, transformVersion: int}>
     */
    private static function woff2Entries(string $woff2): array
    {
        $reader = new BinaryReader($woff2, 'test WOFF2 directory');
        $cursor = 48;
        $entries = [];

        for ($index = 0; $index < $reader->uint16(12); ++$index) {
            $flags = $reader->uint8($cursor++);
            $tagIndex = $flags & 0x3F;
            $tag = 0x3F === $tagIndex ? $reader->string($cursor, 4) : Woff2KnownTags::at($tagIndex);

            if (0x3F === $tagIndex) {
                $cursor += 4;
            }

            $transformVersion = $flags >> 6;
            $originalLength = self::readUIntBase128($reader, $cursor);
            $transformed = \in_array($tag, ['glyf', 'loca'], true) ? 3 !== $transformVersion : 0 !== $transformVersion;
            $entries[] = [
                'tag' => $tag,
                'originalLength' => $originalLength,
                'transformLength' => $transformed ? self::readUIntBase128($reader, $cursor) : null,
                'transformVersion' => $transformVersion,
            ];
        }

        return $entries;
    }

    private static function woff2EntryIndex(string $woff2, string $tag): int
    {
        $index = array_search($tag, array_column(self::woff2Entries($woff2), 'tag'), true);

        if (!\is_int($index)) {
            self::fail(\sprintf('WOFF2 table "%s" was not found.', $tag));
        }

        return $index;
    }

    private static function readUIntBase128(BinaryReader $reader, int &$cursor): int
    {
        $value = 0;

        do {
            $byte = $reader->uint8($cursor++);
            $value = ($value << 7) | ($byte & 0x7F);
        } while (0 !== ($byte & 0x80));

        return $value;
    }
}
