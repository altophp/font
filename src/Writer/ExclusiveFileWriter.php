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

namespace Alto\Font\Writer;

use Alto\Font\Exception\FontWriteException;

/**
 * @internal
 */
final readonly class ExclusiveFileWriter
{
    public static function preflight(string|\Stringable $file): void
    {
        $path = (string) $file;

        if ('' === $path) {
            throw new FontWriteException('Font destination must not be empty.');
        }

        if (file_exists($path) || is_link($path)) {
            throw new FontWriteException(\sprintf('Unable to write font "%s": destination already exists.', $path));
        }
    }

    public static function write(string $data, string|\Stringable $file): void
    {
        self::writeChunks([$data], $file);
    }

    /**
     * @param iterable<string> $chunks
     */
    public static function writeChunks(iterable $chunks, string|\Stringable $file): void
    {
        $path = (string) $file;

        self::preflight($path);

        $stream = @fopen($path, 'xb');

        if (false === $stream) {
            $reason = file_exists($path) || is_link($path)
                ? 'destination already exists'
                : 'destination could not be created';

            throw new FontWriteException(\sprintf('Unable to write font "%s": %s.', $path, $reason));
        }

        try {
            foreach ($chunks as $chunk) {
                self::writeAll($stream, $chunk, $path);
            }

            if (!@fclose($stream)) {
                throw new FontWriteException(\sprintf('Unable to close font "%s" after writing.', $path));
            }
        } catch (\Throwable $error) {
            if (\is_resource($stream)) {
                fclose($stream);
            }

            @unlink($path);

            throw $error;
        }
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $data, string $path): void
    {
        $offset = 0;
        $length = \strlen($data);

        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset, min(1048576, $length - $offset)));

            if (false === $written || 0 === $written) {
                throw new FontWriteException(\sprintf('Unable to write font "%s".', $path));
            }

            $offset += $written;
        }
    }
}
