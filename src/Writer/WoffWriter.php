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
use Alto\Font\Exception\FontWriteException;
use Alto\Font\Font;
use Alto\Font\OpenType\SfntChecksum;

final readonly class WoffWriter
{
    public function dump(Font $font): string
    {
        $document = $font->sfntDocument();

        if (null !== $document->table('DSIG')) {
            $document = $document->withTables([], ['DSIG']);
        }

        $sfnt = $document->toSfnt();
        $reader = new BinaryReader($sfnt, 'SFNT writing source');
        $numTables = $reader->uint16(4);
        $dataOffset = 44 + $numTables * 20;
        $records = '';
        $tableData = '';
        $sfntRecords = [];

        for ($index = 0; $index < $numTables; ++$index) {
            $recordOffset = 12 + $index * 16;
            $tag = $reader->string($recordOffset, 4);
            $sfntRecords[$tag] = [
                $reader->uint32($recordOffset + 4),
                $reader->uint32($recordOffset + 8),
                $reader->uint32($recordOffset + 12),
            ];
        }

        ksort($sfntRecords);

        foreach ($sfntRecords as $tag => [, $tableOffset, $originalLength]) {
            $table = $reader->string($tableOffset, $originalLength);
            $checksumData = 'head' === $tag ? substr_replace($table, "\0\0\0\0", 8, 4) : $table;
            $checksum = SfntChecksum::calculate($checksumData);
            $compressed = gzcompress($table, 6);

            if (!\is_string($compressed)) {
                throw new FontWriteException(\sprintf('Unable to compress SFNT table "%s".', $tag));
            }

            if (\strlen($compressed) >= $originalLength) {
                $compressed = $table;
            }

            $records .= $tag
                . self::uint32($dataOffset)
                . self::uint32(\strlen($compressed))
                . self::uint32($originalLength)
                . self::uint32($checksum);
            $padded = self::pad4($compressed);
            $tableData .= $padded;
            $dataOffset += \strlen($padded);
        }

        $length = 44 + \strlen($records) + \strlen($tableData);

        return 'wOFF'
            . $reader->string(0, 4)
            . self::uint32($length)
            . self::uint16($numTables)
            . self::uint16(0)
            . self::uint32(\strlen($sfnt))
            . self::uint16(0)
            . self::uint16(0)
            . str_repeat("\0", 20)
            . $records
            . $tableData;
    }

    public function write(Font $font, string|\Stringable $file): void
    {
        ExclusiveFileWriter::preflight($file);
        ExclusiveFileWriter::write($this->dump($font), $file);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private static function pad4(string $data): string
    {
        return $data . str_repeat("\0", (4 - \strlen($data) % 4) % 4);
    }
}
