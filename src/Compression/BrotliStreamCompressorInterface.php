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

interface BrotliStreamCompressorInterface extends BrotliCompressorInterface
{
    /**
     * Compresses from the input's current position through EOF and writes the
     * raw Brotli stream at the output's current position.
     *
     * Implementations must leave both streams open and must not rewind them.
     *
     * @param resource $input
     * @param resource $output
     */
    public function compressStream($input, $output): void;
}
