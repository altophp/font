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

namespace Alto\Font\Compression;

use Alto\Font\Exception\CompressionException;

/**
 * Compresses WOFF2 table data with the Brotli PHP extension.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BrotliExtensionCompressor implements BrotliCompressorInterface
{
    public function __construct(private BrotliCompressionProfile $profile = BrotliCompressionProfile::Maximum) {}

    public function compress(string $data): string
    {
        if (!\function_exists('brotli_compress') || !\defined('BROTLI_FONT')) {
            throw new CompressionException('WOFF2 compression requires ext-brotli with BROTLI_FONT mode support.');
        }

        $compress = 'brotli_compress';
        $compressed = $compress($data, $this->profile->value, \constant('BROTLI_FONT'));

        if (!\is_string($compressed)) {
            throw new CompressionException('Brotli font compression failed.');
        }

        return $compressed;
    }
}
