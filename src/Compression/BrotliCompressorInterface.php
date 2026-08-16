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

interface BrotliCompressorInterface
{
    /**
     * Returns one raw Brotli stream for the supplied WOFF2 table data.
     *
     * The result must not include a WOFF2 header, length prefix, or padding.
     */
    public function compress(string $data): string;
}
