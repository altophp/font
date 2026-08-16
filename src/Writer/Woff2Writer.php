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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Compression\BrotliCompressorInterface;
use Alto\Font\Compression\BrotliStreamCompressorInterface;
use Alto\Font\Exception\CompressionException;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\OpenType\SfntBuilder;
use Alto\Font\OpenType\SfntDocument;
use Alto\Font\OpenType\Woff2KnownTags;
use Alto\Font\OpenType\Woff2TransformEncoder;

final readonly class Woff2Writer
{
    private const int CHUNK_SIZE = 1048576;

    public function __construct(private BrotliCompressorInterface $brotli) {}

    public function dump(Font $font): string
    {
        $document = self::prepareDocument($font);
        [$tags, $entries] = Woff2TransformEncoder::encode($document);
        [$directory, $totalSfntSize] = self::buildDirectory($tags, $entries);
        $tableData = '';

        foreach (self::tableChunks($tags, $entries) as $chunk) {
            $tableData .= $chunk;
        }

        $compressed = $this->brotli->compress($tableData);
        $prefix = self::containerPrefix($document, $directory, $totalSfntSize, \strlen($compressed));

        return $prefix
            . $compressed
            . str_repeat("\0", self::paddingLength(\strlen($prefix), \strlen($compressed)));
    }

    public function write(Font $font, string|\Stringable $file): void
    {
        ExclusiveFileWriter::preflight($file);

        if (!$this->brotli instanceof BrotliStreamCompressorInterface) {
            ExclusiveFileWriter::write($this->dump($font), $file);

            return;
        }

        $document = self::prepareDocument($font);
        [$tags, $entries] = Woff2TransformEncoder::encode($document);
        [$directory, $totalSfntSize] = self::buildDirectory($tags, $entries);
        $input = tmpfile();

        if (false === $input) {
            throw new CompressionException('Unable to create a temporary WOFF2 input stream.');
        }

        $compressed = tmpfile();

        if (false === $compressed) {
            fclose($input);

            throw new CompressionException('Unable to create a temporary WOFF2 output stream.');
        }

        try {
            self::writeStream($input, self::tableChunks($tags, $entries));

            if (!rewind($input)) {
                throw new CompressionException('Unable to prepare the WOFF2 input stream.');
            }

            $this->brotli->compressStream($input, $compressed);

            if (!fflush($compressed)) {
                throw new CompressionException('Unable to flush the WOFF2 compressed stream.');
            }

            $statistics = fstat($compressed);

            if (!\is_array($statistics) || !isset($statistics['size']) || !\is_int($statistics['size'])) {
                throw new CompressionException('Unable to determine the WOFF2 compressed size.');
            }

            $compressedSize = $statistics['size'];
            $prefix = self::containerPrefix($document, $directory, $totalSfntSize, $compressedSize);
            $padding = self::paddingLength(\strlen($prefix), $compressedSize);

            if (!rewind($compressed)) {
                throw new CompressionException('Unable to read the WOFF2 compressed stream.');
            }

            ExclusiveFileWriter::writeChunks(
                self::outputChunks($prefix, $compressed, $padding),
                $file,
            );
        } finally {
            fclose($input);
            fclose($compressed);
        }
    }

    private static function prepareDocument(Font $font): SfntDocument
    {
        $document = $font->sfntDocument();
        $head = $document->table('head');

        if (null === $head || \strlen($head) < 18) {
            throw new InvalidFontException('SFNT head table is truncated.');
        }

        $headReader = new BinaryReader($head, 'WOFF2 head table');
        $head = substr_replace($head, self::uint16($headReader->uint16(16) | 0x0800), 16, 2);

        return SfntBuilder::withChecksumAdjustment($document->withTables(['head' => $head], ['DSIG']));
    }

    /**
     * @param list<string>                                                                        $tags
     * @param array<string, array{data: string, originalLength: int, reconstructedLength: int, transformVersion: int}> $entries
     *
     * @return array{string, int}
     */
    private static function buildDirectory(array $tags, array $entries): array
    {
        $directory = '';
        $totalSfntSize = 12 + \count($tags) * 16;

        foreach ($tags as $tag) {
            $entry = $entries[$tag] ?? null;

            if (null === $entry) {
                throw new InvalidFontException(\sprintf('SFNT table "%s" disappeared while writing WOFF2.', $tag));
            }

            $tagIndex = Woff2KnownTags::indexOf($tag);

            if (null === $tagIndex) {
                $directory .= chr((($entry['transformVersion'] << 6) | 0x3F) & 0xFF) . $tag;
            } else {
                $directory .= chr((($entry['transformVersion'] << 6) | $tagIndex) & 0xFF);
            }

            $directory .= self::uintBase128($entry['originalLength']);

            if (self::isTransformed($tag, $entry['transformVersion'])) {
                $directory .= self::uintBase128(\strlen($entry['data']));
            }

            $totalSfntSize += ($entry['reconstructedLength'] + 3) & ~3;
        }

        return [$directory, $totalSfntSize];
    }

    /**
     * @param list<string>                                                                        $tags
     * @param array<string, array{data: string, originalLength: int, reconstructedLength: int, transformVersion: int}> $entries
     *
     * @return \Generator<int, string>
     */
    private static function tableChunks(array $tags, array $entries): \Generator
    {
        foreach ($tags as $tag) {
            $entry = $entries[$tag] ?? null;

            if (null === $entry) {
                throw new InvalidFontException(\sprintf('SFNT table "%s" disappeared while writing WOFF2.', $tag));
            }

            for ($offset = 0, $length = \strlen($entry['data']); $offset < $length; $offset += self::CHUNK_SIZE) {
                yield substr($entry['data'], $offset, min(self::CHUNK_SIZE, $length - $offset));
            }
        }
    }

    private static function isTransformed(string $tag, int $transformVersion): bool
    {
        return \in_array($tag, ['glyf', 'loca'], true) ? 3 !== $transformVersion : 0 !== $transformVersion;
    }

    /**
     * @param resource         $stream
     * @param iterable<string> $chunks
     */
    private static function writeStream($stream, iterable $chunks): void
    {
        foreach ($chunks as $chunk) {
            $offset = 0;
            $length = \strlen($chunk);

            while ($offset < $length) {
                $written = fwrite($stream, substr($chunk, $offset));

                if (false === $written || 0 === $written) {
                    throw new CompressionException('Unable to write the WOFF2 temporary stream.');
                }

                $offset += $written;
            }
        }
    }

    /**
     * @param resource $compressed
     *
     * @return \Generator<int, string>
     */
    private static function outputChunks(string $prefix, $compressed, int $padding): \Generator
    {
        yield $prefix;

        while (!feof($compressed)) {
            $chunk = fread($compressed, self::CHUNK_SIZE);

            if (false === $chunk) {
                throw new CompressionException('Unable to read the WOFF2 compressed stream.');
            }

            if ('' !== $chunk) {
                yield $chunk;
            }
        }

        if ($padding > 0) {
            yield str_repeat("\0", $padding);
        }
    }

    private static function containerPrefix(
        SfntDocument $document,
        string $directory,
        int $totalSfntSize,
        int $compressedSize,
    ): string {
        $contentLength = 48 + \strlen($directory) + $compressedSize;
        $fileLength = $contentLength + (4 - $contentLength % 4) % 4;

        return 'wOF2'
            . $document->flavor
            . self::uint32($fileLength)
            . self::uint16(\count($document->tableTags()))
            . self::uint16(0)
            . self::uint32($totalSfntSize)
            . self::uint32($compressedSize)
            . self::uint16(0)
            . self::uint16(0)
            . str_repeat("\0", 20)
            . $directory;
    }

    private static function paddingLength(int $prefixLength, int $compressedSize): int
    {
        return (4 - (($prefixLength + $compressedSize) % 4)) % 4;
    }

    private static function uintBase128(int $value): string
    {
        $bytes = [$value & 0x7F];
        $value >>= 7;

        while ($value > 0) {
            array_unshift($bytes, 0x80 | ($value & 0x7F));
            $value >>= 7;
        }

        return implode('', array_map(chr(...), $bytes));
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
