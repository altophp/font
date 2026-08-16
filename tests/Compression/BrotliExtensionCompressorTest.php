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

use Alto\Font\Compression\BrotliCompressorInterface;
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Exception\CompressionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrotliExtensionCompressor::class)]
final class BrotliExtensionCompressorTest extends TestCase
{
    public function testItImplementsTheCompressionBoundary(): void
    {
        self::assertInstanceOf(BrotliCompressorInterface::class, new BrotliExtensionCompressor());
    }

    public function testItRequiresTheFontCompressionMode(): void
    {
        if (\function_exists('brotli_compress') && \defined('BROTLI_FONT')) {
            self::markTestSkipped('This environment provides ext-brotli.');
        }

        $this->expectException(CompressionException::class);
        $this->expectExceptionMessage('BROTLI_FONT mode');

        new BrotliExtensionCompressor()->compress('Alto');
    }

    #[RequiresPhpExtension('brotli')]
    public function testItCompressesWithTheExtensionWhenAvailable(): void
    {
        $data = str_repeat('Alto Font ', 1000);
        $compressed = new BrotliExtensionCompressor()->compress($data);

        self::assertNotSame('', $compressed);
        self::assertSame($data, brotli_uncompress($compressed));
    }
}
