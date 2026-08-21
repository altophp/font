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
 * Compresses WOFF2 table data with the Brotli executable.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BrotliProcessCompressor implements BrotliStreamCompressorInterface
{
    public function __construct(
        private string $binary = 'brotli',
        private BrotliCompressionProfile $profile = BrotliCompressionProfile::Maximum,
    ) {}

    public function compress(string $data): string
    {
        $input = tmpfile();

        if (false === $input) {
            throw new CompressionException('Unable to create temporary streams for Brotli compression.');
        }

        $output = tmpfile();

        if (false === $output) {
            fclose($input);

            throw new CompressionException('Unable to create temporary streams for Brotli compression.');
        }

        try {
            self::writeAll($input, $data);

            if (!rewind($input)) {
                throw new CompressionException('Unable to prepare data for Brotli compression.');
            }

            $this->compressStream($input, $output);

            if (!rewind($output)) {
                throw new CompressionException('Unable to read Brotli compressed data.');
            }

            $compressed = stream_get_contents($output);

            if (!\is_string($compressed)) {
                throw new CompressionException('Unable to read Brotli compressed data.');
            }

            return $compressed;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function compressStream($input, $output): void
    {
        $errors = tmpfile();

        if (false === $errors) {
            throw new CompressionException('Unable to create a temporary stream for Brotli errors.');
        }

        try {
            $process = @proc_open(
                [$this->binary, '--stdout', '--quality=' . $this->profile->value],
                [$input, $output, $errors],
                $pipes,
            );

            if (!\is_resource($process)) {
                throw new CompressionException(\sprintf('Unable to start Brotli encoder "%s".', $this->binary));
            }

            $exitCode = proc_close($process);
            rewind($errors);
            $error = stream_get_contents($errors);

            if (0 !== $exitCode) {
                $detail = \is_string($error) ? trim($error) : '';

                throw new CompressionException('Brotli compression failed.' . ('' === $detail ? '' : ' ' . $detail));
            }
        } finally {
            fclose($errors);
        }
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = \strlen($data);

        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset, min(1048576, $length - $offset)));

            if (false === $written || 0 === $written) {
                throw new CompressionException('Unable to prepare data for Brotli compression.');
            }

            $offset += $written;
        }
    }
}
