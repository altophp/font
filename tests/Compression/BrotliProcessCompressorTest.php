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

namespace Alto\Font\Tests\Compression;

use Alto\Font\Compression\BrotliCompressionProfile;
use Alto\Font\Compression\BrotliCompressorInterface;
use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Compression\BrotliStreamCompressorInterface;
use Alto\Font\Exception\CompressionException;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrotliProcessCompressor::class)]
#[CoversClass(CompressionException::class)]
final class BrotliProcessCompressorTest extends TestCase
{
    public function testItImplementsTheCompressionBoundary(): void
    {
        self::assertInstanceOf(BrotliCompressorInterface::class, new BrotliProcessCompressor());
        self::assertInstanceOf(BrotliStreamCompressorInterface::class, new BrotliProcessCompressor());
    }

    public function testItCompressesDataWithTheConfiguredProcess(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required.');
        }

        $data = str_repeat('Alto Font ', 1000);
        $compressed = new BrotliProcessCompressor()->compress($data);

        self::assertNotSame('', $compressed);
        self::assertLessThan(\strlen($data), \strlen($compressed));
    }

    public function testItSupportsAFastBuildProfile(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required.');
        }

        $data = str_repeat('Alto Font ', 1000);
        $compressed = new BrotliProcessCompressor(profile: BrotliCompressionProfile::Fast)->compress($data);

        self::assertNotSame('', $compressed);
        self::assertLessThan(\strlen($data), \strlen($compressed));
    }

    public function testItCompressesSeekableStreams(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required.');
        }

        $input = tmpfile();
        $output = tmpfile();
        self::assertIsResource($input);
        self::assertIsResource($output);

        try {
            $data = str_repeat('Alto streamed font data ', 1000);
            fwrite($input, $data);
            rewind($input);

            new BrotliProcessCompressor()->compressStream($input, $output);

            rewind($output);
            $compressed = stream_get_contents($output);
            self::assertIsString($compressed);
            self::assertNotSame('', $compressed);
            self::assertLessThan(\strlen($data), \strlen($compressed));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function testItReportsAnUnavailableProcess(): void
    {
        $this->expectException(CompressionException::class);
        $this->expectExceptionMessage('Unable to start Brotli encoder');

        new BrotliProcessCompressor('/path/to/missing-brotli')->compress('Alto');
    }

}
