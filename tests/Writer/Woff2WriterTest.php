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
use Alto\Font\OpenType\Woff2KnownTags;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Writer\ExclusiveFileWriter;
use Alto\Font\Writer\Woff2Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Woff2Writer::class)]
#[CoversClass(Woff2KnownTags::class)]
#[CoversClass(ExclusiveFileWriter::class)]
final class Woff2WriterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required.');
        }
    }

    public function testItDumpsAReloadableNullTransformWoff2Container(): void
    {
        $source = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($source);
        $font = Font::fromFile($source);

        $woff2 = new Woff2Writer(new BrotliProcessCompressor())->dump($font);
        $reader = new BinaryReader($woff2, 'test WOFF2');

        self::assertSame('wOF2', substr($woff2, 0, 4));
        self::assertSame(\strlen($woff2), $reader->uint32(8));
        self::assertSame(0, \strlen($woff2) % 4);
        self::assertSame(\strlen($font->toSfnt()), $reader->uint32(16));
        self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($woff2))->getDescriptor()->family);
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
}
