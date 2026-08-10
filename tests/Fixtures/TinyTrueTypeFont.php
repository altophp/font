<?php

declare(strict_types=1);

namespace Alto\Font\Tests\Fixtures;

final class TinyTrueTypeFont
{
    private const array WOFF2_KNOWN_TAGS = [
        'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post',
        'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT',
        'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea',
        'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH',
        'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar',
        'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar',
        'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop',
        'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill',
    ];

    public static function write(string $path, bool $compoundCycle = false, bool $unsupportedCff = false): void
    {
        file_put_contents($path, self::sfnt(self::tables($compoundCycle, $unsupportedCff)));
    }

    public static function writePointMatchedCompound(string $path): void
    {
        file_put_contents($path, self::sfnt(self::tables(compoundCycle: false, unsupportedCff: false, pointMatched: true)));
    }

    public static function writeCompoundWithUniformScale(string $path, bool $useMyMetrics = false): void
    {
        file_put_contents($path, self::sfnt(self::tables(
            compoundCycle: false,
            unsupportedCff: false,
            compoundGlyph: self::compoundGlyph(1, 50, 0, $useMyMetrics, extraFlags: 0x0008, extraData: self::f2dot14(0.5)),
        )));
    }

    public static function writeCompoundWithXYScale(string $path, bool $useMyMetrics = false): void
    {
        file_put_contents($path, self::sfnt(self::tables(
            compoundCycle: false,
            unsupportedCff: false,
            compoundGlyph: self::compoundGlyph(
                1,
                50,
                0,
                $useMyMetrics,
                extraFlags: 0x0040,
                extraData: self::f2dot14(0.5).self::f2dot14(0.25),
            ),
        )));
    }

    public static function writeCompoundWithTwoByTwo(string $path, bool $useMyMetrics = false): void
    {
        file_put_contents($path, self::sfnt(self::tables(
            compoundCycle: false,
            unsupportedCff: false,
            compoundGlyph: self::compoundGlyph(
                1,
                50,
                0,
                $useMyMetrics,
                extraFlags: 0x0080,
                extraData: self::f2dot14(1.0).self::f2dot14(0.25).self::f2dot14(0.0).self::f2dot14(1.0),
            ),
        )));
    }

    public static function writeCompoundWithInstructions(string $path): void
    {
        file_put_contents($path, self::sfnt(self::tables(
            compoundCycle: false,
            unsupportedCff: false,
            compoundGlyph: self::compoundGlyph(1, 50, 0, extraFlags: 0x0100, instructions: "\xB0\x00"),
        )));
    }

    public static function writeVariable(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $tables['fvar'] = self::fvar();
        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    public static function writeVariableWithGvar(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $tables['fvar'] = self::fvar();
        $tables['gvar'] = self::gvar();
        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    public static function writeVariableWithSparseGvar(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $tables['fvar'] = self::fvar();
        $tables['gvar'] = self::sparseGvar();
        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    public static function writeVariableWithCompoundGvar(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $tables['fvar'] = self::fvar();
        $tables['gvar'] = self::compoundGvar();
        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    public static function writeVariableWithUseMyMetricsGvar(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false, useMyMetrics: true);
        $tables['fvar'] = self::fvar();
        $tables['gvar'] = self::compoundPhantomGvar();
        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    public static function writeVariableCompoundWithUniformScaleAndUseMyMetrics(string $path): void
    {
        self::writeVariableCompoundWithTransformedUseMyMetrics(
            $path,
            self::compoundGlyph(1, 50, 0, useMyMetrics: true, extraFlags: 0x0008, extraData: self::f2dot14(0.5)),
        );
    }

    public static function writeVariableCompoundWithXYScaleAndUseMyMetrics(string $path): void
    {
        self::writeVariableCompoundWithTransformedUseMyMetrics(
            $path,
            self::compoundGlyph(
                1,
                50,
                0,
                useMyMetrics: true,
                extraFlags: 0x0040,
                extraData: self::f2dot14(0.5).self::f2dot14(0.25),
            ),
        );
    }

    public static function writeVariableCompoundWithTwoByTwoAndUseMyMetrics(string $path): void
    {
        self::writeVariableCompoundWithTransformedUseMyMetrics(
            $path,
            self::compoundGlyph(
                1,
                50,
                0,
                useMyMetrics: true,
                extraFlags: 0x0080,
                extraData: self::f2dot14(1.0).self::f2dot14(0.25).self::f2dot14(0.0).self::f2dot14(1.0),
            ),
        );
    }

    private static function writeVariableCompoundWithTransformedUseMyMetrics(string $path, string $compoundGlyph): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false, compoundGlyph: $compoundGlyph);
        $tables['fvar'] = self::fvar();
        $tables['gvar'] = self::compoundPhantomGvar();
        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    public static function writeVariableWithHvar(string $path, bool $includeGvar = false): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $tables['fvar'] = self::fvar();
        $tables['HVAR'] = self::hvar();

        if ($includeGvar) {
            $tables['gvar'] = self::gvar();
        }

        ksort($tables);

        file_put_contents($path, self::sfnt($tables));
    }

    /**
     * @param list<int> $unitsPerEmByFace
     */
    public static function writeCollection(string $path, array $unitsPerEmByFace = [1000, 2048]): void
    {
        $headerSize = 12 + 4 * \count($unitsPerEmByFace);
        $cursor = $headerSize;
        $offsets = [];
        $blobs = '';

        foreach ($unitsPerEmByFace as $unitsPerEm) {
            $blob = self::sfnt(self::tables(compoundCycle: false, unsupportedCff: false, unitsPerEm: $unitsPerEm), $cursor);
            $offsets[] = $cursor;
            $blobs .= $blob;
            $cursor += \strlen($blob);
        }

        $header = 'ttcf'.self::u32(0x00010000).self::u32(\count($unitsPerEmByFace));

        foreach ($offsets as $offset) {
            $header .= self::u32($offset);
        }

        file_put_contents($path, $header.$blobs);
    }

    public static function writeCollectionHeaderOnly(string $path, int $numFonts): void
    {
        file_put_contents($path, 'ttcf'.self::u32(0x00010000).self::u32($numFonts));
    }

    public static function writeWoff(string $path): void
    {
        file_put_contents($path, self::woff(self::tables(compoundCycle: false, unsupportedCff: false)));
    }

    public static function writeWoff2(string $path): void
    {
        file_put_contents($path, self::woff2(self::tables(compoundCycle: false, unsupportedCff: false)));
    }

    public static function writeWoff2WithTransformedHmtx(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $directory = '';
        $tableData = '';
        $totalSfntSize = 12 + \count($tables) * 16;
        $transformedHmtx = "\x02"
            . self::u16(500) . self::u16(600) . self::u16(610) . self::u16(500) . self::u16(650)
            . self::i16(0) . self::i16(10) . self::i16(20) . self::i16(0) . self::i16(50);

        foreach ($tables as $tag => $table) {
            $tagIndex = array_search($tag, self::WOFF2_KNOWN_TAGS, true);

            if (!\is_int($tagIndex)) {
                throw new \LogicException(\sprintf('Unknown WOFF2 fixture tag "%s".', $tag));
            }

            $flags = 'hmtx' === $tag ? (1 << 6) | $tagIndex : self::woff2NullTransformFlags($tag, $tagIndex);
            $directory .= self::u8($flags) . self::base128(\strlen($table));
            $encodedTable = $table;

            if ('hmtx' === $tag) {
                $directory .= self::base128(\strlen($transformedHmtx));
                $encodedTable = $transformedHmtx;
            }

            $tableData .= $encodedTable;
            $totalSfntSize += \strlen(self::align4($table));
        }

        $compressedData = self::brotliCompress($tableData);

        if (null === $compressedData) {
            throw new \RuntimeException('The brotli binary is required to generate the WOFF2 fixture.');
        }

        $length = 48 + \strlen($directory) + \strlen($compressedData);

        file_put_contents(
            $path,
            'wOF2'
                . "\x00\x01\x00\x00"
                . self::u32($length)
                . self::u16(\count($tables))
                . self::u16(0)
                . self::u32($totalSfntSize)
                . self::u32(\strlen($compressedData))
                . self::u16(1)
                . self::u16(0)
                . str_repeat("\0", 20)
                . $directory
                . $compressedData,
        );
    }

    public static function writeWoff2WithImplausibleTableLength(string $path): void
    {
        $tables = self::tables(compoundCycle: false, unsupportedCff: false);
        $directory = '';
        $tableData = '';
        $first = true;

        foreach ($tables as $tag => $table) {
            $tagIndex = array_search($tag, self::WOFF2_KNOWN_TAGS, true);

            if (!\is_int($tagIndex)) {
                throw new \LogicException(\sprintf('Unknown WOFF2 fixture tag "%s".', $tag));
            }

            $declaredLength = $first ? 200 * 1024 * 1024 : \strlen($table);
            $first = false;

            $directory .= self::u8(self::woff2NullTransformFlags($tag, $tagIndex)).self::base128($declaredLength);
            $tableData .= $table;
        }

        $compressedData = self::brotliCompress($tableData);

        if (null === $compressedData) {
            throw new \RuntimeException('The brotli binary is required to generate the WOFF2 fixture.');
        }

        $length = 48 + \strlen($directory) + \strlen($compressedData);

        file_put_contents($path, 'wOF2'."\x00\x01\x00\x00".self::u32($length).self::u16(\count($tables)).self::u16(0).self::u32(0).self::u32(\strlen($compressedData)).self::u16(1).self::u16(0).str_repeat("\0", 20).$directory.$compressedData);
    }

    public static function hasBrotliEncoder(): bool
    {
        return null !== self::brotliCompress('x');
    }

    /**
     * @return array<string, string>
     */
    private static function tables(
        bool $compoundCycle,
        bool $unsupportedCff,
        bool $useMyMetrics = false,
        bool $pointMatched = false,
        int $unitsPerEm = 1000,
        ?string $compoundGlyph = null,
    ): array {
        $glyphs = [
            '',
            self::simpleGlyph([[100, 0], [300, 700], [500, 0]]),
            self::simpleGlyph([[100, 700], [300, 0], [500, 700]]),
            self::simpleGlyph([[80, 0], [80, 700], [420, 700], [420, 0]]),
            $compoundGlyph ?? match (true) {
                $compoundCycle => self::compoundGlyph(4, 0, 0),
                $pointMatched => self::pointMatchedCompoundGlyph(1),
                default => self::compoundGlyph(1, 50, 0, $useMyMetrics),
            },
        ];

        $glyf = '';
        $loca = [];

        foreach ($glyphs as $glyph) {
            $loca[] = \strlen($glyf);
            $glyf .= self::align4($glyph);
        }

        $loca[] = \strlen($glyf);

        $tables = [
            'cmap' => self::cmap(),
            'glyf' => $glyf,
            'head' => self::head($unitsPerEm),
            'hhea' => self::hhea(),
            'hmtx' => self::hmtx(),
            'loca' => self::loca($loca),
            'maxp' => self::maxp(),
            'name' => self::name(),
        ];

        if ($unsupportedCff) {
            $tables['CFF '] = "\0\0\0\0";
        }

        ksort($tables);

        return $tables;
    }

    /**
     * @param array<string, string> $tables
     */
    private static function sfnt(array $tables, int $baseOffset = 0): string
    {
        $numTables = \count($tables);
        $offset = $baseOffset + 12 + $numTables * 16;
        $records = '';
        $data = '';

        foreach ($tables as $tag => $table) {
            $padded = self::align4($table);
            $records .= $tag.self::u32(0).self::u32($offset).self::u32(\strlen($table));
            $data .= $padded;
            $offset += \strlen($padded);
        }

        return "\x00\x01\x00\x00".self::u16($numTables).self::u16(0).self::u16(0).self::u16(0).$records.$data;
    }

    /**
     * @param array<string, string> $tables
     */
    private static function woff(array $tables): string
    {
        $numTables = \count($tables);
        $offset = 44 + $numTables * 20;
        $records = '';
        $data = '';
        $totalSfntSize = 12 + $numTables * 16;

        foreach ($tables as $table) {
            $totalSfntSize += \strlen(self::align4($table));
        }

        foreach ($tables as $tag => $table) {
            $compressed = gzcompress($table);

            if (!\is_string($compressed) || \strlen($compressed) >= \strlen($table)) {
                $compressed = $table;
            }

            $records .= $tag.self::u32($offset).self::u32(\strlen($compressed)).self::u32(\strlen($table)).self::u32(0);
            $padded = self::align4($compressed);
            $data .= $padded;
            $offset += \strlen($padded);
        }

        $length = 44 + \strlen($records) + \strlen($data);

        return 'wOFF'
            ."\x00\x01\x00\x00"
            .self::u32($length)
            .self::u16($numTables)
            .self::u16(0)
            .self::u32($totalSfntSize)
            .self::u16(1)
            .self::u16(0)
            .self::u32(0)
            .self::u32(0)
            .self::u32(0)
            .self::u32(0)
            .self::u32(0)
            .$records
            .$data;
    }

    /**
     * @param array<string, string> $tables
     */
    private static function woff2(array $tables): string
    {
        $directory = '';
        $tableData = '';
        $totalSfntSize = 12 + \count($tables) * 16;

        foreach ($tables as $tag => $table) {
            $tagIndex = array_search($tag, self::WOFF2_KNOWN_TAGS, true);

            if (!\is_int($tagIndex)) {
                throw new \LogicException(\sprintf('Unknown WOFF2 fixture tag "%s".', $tag));
            }

            $directory .= self::u8(self::woff2NullTransformFlags($tag, $tagIndex)).self::base128(\strlen($table));
            $tableData .= $table;
            $totalSfntSize += \strlen(self::align4($table));
        }

        $compressedData = self::brotliCompress($tableData);

        if (null === $compressedData) {
            throw new \RuntimeException('The brotli binary is required to generate the WOFF2 fixture.');
        }

        $length = 48 + \strlen($directory) + \strlen($compressedData);

        return 'wOF2'
            ."\x00\x01\x00\x00"
            .self::u32($length)
            .self::u16(\count($tables))
            .self::u16(0)
            .self::u32($totalSfntSize)
            .self::u32(\strlen($compressedData))
            .self::u16(1)
            .self::u16(0)
            .str_repeat("\0", 20)
            .$directory
            .$compressedData;
    }

    /**
     * @param list<int> $offsets
     */
    private static function loca(array $offsets): string
    {
        $data = '';

        foreach ($offsets as $offset) {
            $data .= self::u32($offset);
        }

        return $data;
    }

    private static function head(int $unitsPerEm = 1000): string
    {
        $data = str_repeat("\0", 54);
        self::put($data, 18, self::u16($unitsPerEm));
        self::put($data, 50, self::i16(1));

        return $data;
    }

    private static function hhea(): string
    {
        $data = str_repeat("\0", 36);
        self::put($data, 4, self::i16(800));
        self::put($data, 6, self::i16(-200));
        self::put($data, 34, self::u16(5));

        return $data;
    }

    private static function hmtx(): string
    {
        $metrics = [
            [500, 0],
            [600, 10],
            [610, 20],
            [500, 0],
            [650, 50],
        ];
        $data = '';

        foreach ($metrics as [$advanceWidth, $leftSideBearing]) {
            $data .= self::u16($advanceWidth).self::i16($leftSideBearing);
        }

        return $data;
    }

    private static function maxp(): string
    {
        return "\x00\x01\x00\x00".self::u16(5);
    }

    private static function name(): string
    {
        $family = self::utf16beAscii('Atelier Tiny');
        $subfamily = self::utf16beAscii('Regular');
        $strings = $family.$subfamily;

        $records = self::u16(3).self::u16(1).self::u16(0x0409).self::u16(1).self::u16(\strlen($family)).self::u16(0);
        $records .= self::u16(3).self::u16(1).self::u16(0x0409).self::u16(2).self::u16(\strlen($subfamily)).self::u16(\strlen($family));

        return self::u16(0).self::u16(2).self::u16(30).$records.$strings;
    }

    private static function fvar(): string
    {
        return self::u16(1)
            .self::u16(0)
            .self::u16(16)
            .self::u16(2)
            .self::u16(2)
            .self::u16(20)
            .self::u16(2)
            .self::u16(14)
            .'wght'
            .self::fixed(100.0)
            .self::fixed(400.0)
            .self::fixed(900.0)
            .self::u16(0)
            .self::u16(256)
            .'wdth'
            .self::fixed(75.0)
            .self::fixed(100.0)
            .self::fixed(125.0)
            .self::u16(0)
            .self::u16(257)
            .self::u16(2)
            .self::u16(0)
            .self::fixed(400.0)
            .self::fixed(100.0)
            .self::u16(0xFFFF)
            .self::u16(258)
            .self::u16(0)
            .self::fixed(800.0)
            .self::fixed(75.0)
            .self::u16(0xFFFF);
    }

    private static function gvar(): string
    {
        $wghtTupleData = self::pointNumbers([0, 1, 2, 4])
            .self::deltaBytes([-20, 0, 20, 40])
            .self::zeroDeltas(4);
        $wdthTupleData = self::pointNumbers([0, 1, 2, 4])
            .self::deltaBytes([20, 0, -20, -80])
            .self::zeroDeltas(4);
        $glyphData = self::u16(2)
            .self::u16(20)
            .self::u16(\strlen($wghtTupleData))
            .self::u16(0x8000 | 0x2000)
            .self::f2dot14(1.0)
            .self::f2dot14(0.0)
            .self::u16(\strlen($wdthTupleData))
            .self::u16(0x8000 | 0x2000)
            .self::f2dot14(0.0)
            .self::f2dot14(-1.0)
            .$wghtTupleData
            .$wdthTupleData;
        $glyphData = self::pad2($glyphData);
        $offsets = [0, 0, \strlen($glyphData), \strlen($glyphData), \strlen($glyphData), \strlen($glyphData)];
        $offsetData = '';

        foreach ($offsets as $offset) {
            $offsetData .= self::u16(intdiv($offset, 2));
        }

        return self::u16(1)
            .self::u16(0)
            .self::u16(2)
            .self::u16(0)
            .self::u32(0)
            .self::u16(5)
            .self::u16(0)
            .self::u32(20 + \strlen($offsetData))
            .$offsetData
            .$glyphData;
    }

    private static function sparseGvar(): string
    {
        $tupleData = self::pointNumbers([0, 2])
            .self::deltaBytes([20, -20])
            .self::zeroDeltas(2);
        $glyphData = self::u16(1)
            .self::u16(12)
            .self::u16(\strlen($tupleData))
            .self::u16(0x8000 | 0x2000)
            .self::f2dot14(1.0)
            .self::f2dot14(0.0)
            .$tupleData;
        $glyphData = self::pad2($glyphData);
        $offsets = [0, 0, 0, 0, \strlen($glyphData), \strlen($glyphData)];
        $offsetData = '';

        foreach ($offsets as $offset) {
            $offsetData .= self::u16(intdiv($offset, 2));
        }

        return self::u16(1)
            .self::u16(0)
            .self::u16(2)
            .self::u16(0)
            .self::u32(0)
            .self::u16(5)
            .self::u16(0)
            .self::u32(20 + \strlen($offsetData))
            .$offsetData
            .$glyphData;
    }

    private static function compoundGvar(): string
    {
        $tupleData = self::pointNumbers([0])
            .self::deltaBytes([30])
            .self::deltaBytes([40]);
        $glyphData = self::u16(1)
            .self::u16(12)
            .self::u16(\strlen($tupleData))
            .self::u16(0x8000 | 0x2000)
            .self::f2dot14(1.0)
            .self::f2dot14(0.0)
            .$tupleData;
        $glyphData = self::pad2($glyphData);
        $offsets = [0, 0, 0, 0, 0, \strlen($glyphData)];
        $offsetData = '';

        foreach ($offsets as $offset) {
            $offsetData .= self::u16(intdiv($offset, 2));
        }

        return self::u16(1)
            .self::u16(0)
            .self::u16(2)
            .self::u16(0)
            .self::u32(0)
            .self::u16(5)
            .self::u16(0)
            .self::u32(20 + \strlen($offsetData))
            .$offsetData
            .$glyphData;
    }

    private static function compoundPhantomGvar(): string
    {
        $tupleData = self::pointNumbers([2])
            .self::deltaBytes([100])
            .self::zeroDeltas(1);
        $glyphData = self::u16(1)
            .self::u16(12)
            .self::u16(\strlen($tupleData))
            .self::u16(0x8000 | 0x2000)
            .self::f2dot14(1.0)
            .self::f2dot14(0.0)
            .$tupleData;
        $glyphData = self::pad2($glyphData);
        $offsets = [0, 0, 0, 0, 0, \strlen($glyphData)];
        $offsetData = '';

        foreach ($offsets as $offset) {
            $offsetData .= self::u16(intdiv($offset, 2));
        }

        return self::u16(1)
            .self::u16(0)
            .self::u16(2)
            .self::u16(0)
            .self::u32(0)
            .self::u16(5)
            .self::u16(0)
            .self::u32(20 + \strlen($offsetData))
            .$offsetData
            .$glyphData;
    }

    private static function hvar(): string
    {
        $store = self::itemVariationStore();
        $advanceMap = self::deltaSetIndexMap([0, 0, 0, 0, 0]);
        $leftSideBearingMap = self::deltaSetIndexMap([1, 1, 1, 1, 1]);
        $rightSideBearingMap = self::deltaSetIndexMap([1, 1, 1, 1, 1]);
        $itemVariationStoreOffset = 20;
        $advanceMapOffset = $itemVariationStoreOffset + \strlen($store);
        $leftSideBearingMapOffset = $advanceMapOffset + \strlen($advanceMap);
        $rightSideBearingMapOffset = $leftSideBearingMapOffset + \strlen($leftSideBearingMap);

        return self::u16(1)
            .self::u16(0)
            .self::u32($itemVariationStoreOffset)
            .self::u32($advanceMapOffset)
            .self::u32($leftSideBearingMapOffset)
            .self::u32($rightSideBearingMapOffset)
            .$store
            .$advanceMap
            .$leftSideBearingMap
            .$rightSideBearingMap;
    }

    private static function itemVariationStore(): string
    {
        $regionList = self::variationRegionList();
        $itemData = self::itemVariationData();
        $regionListOffset = 12;
        $itemDataOffset = $regionListOffset + \strlen($regionList);

        return self::u16(1)
            .self::u32($regionListOffset)
            .self::u16(1)
            .self::u32($itemDataOffset)
            .$regionList
            .$itemData;
    }

    private static function variationRegionList(): string
    {
        return self::u16(2)
            .self::u16(2)
            .self::f2dot14(0.0).self::f2dot14(1.0).self::f2dot14(1.0)
            .self::f2dot14(0.0).self::f2dot14(0.0).self::f2dot14(0.0)
            .self::f2dot14(0.0).self::f2dot14(0.0).self::f2dot14(0.0)
            .self::f2dot14(-1.0).self::f2dot14(-1.0).self::f2dot14(0.0);
    }

    private static function itemVariationData(): string
    {
        return self::u16(2)
            .self::u16(2)
            .self::u16(2)
            .self::u16(0)
            .self::u16(1)
            .self::i16(100)
            .self::i16(-120)
            .self::i16(5)
            .self::i16(-10);
    }

    /**
     * @param list<int> $innerIndexes
     */
    private static function deltaSetIndexMap(array $innerIndexes): string
    {
        return self::u8(0).self::u8(0).self::u16(\count($innerIndexes)).implode('', array_map(
            static fn (int $innerIndex): string => self::u8($innerIndex),
            $innerIndexes,
        ));
    }

    private static function cmap(): string
    {
        $segments = [
            [32, 32, 0],
            [65, 65, 1],
            [72, 72, 3],
            [86, 86, 2],
            [101, 101, 3],
            [108, 108, 3],
            [111, 111, 3],
            [193, 193, 4],
            [0xFFFF, 0xFFFF, 0],
        ];
        $segCount = \count($segments);
        $entrySelector = (int) floor(log($segCount, 2));
        $searchRange = 2 * (2 ** $entrySelector);
        $rangeShift = $segCount * 2 - $searchRange;
        $endCodes = '';
        $startCodes = '';
        $idDeltas = '';
        $idRangeOffsets = '';

        foreach ($segments as [$start, $end, $glyphId]) {
            $endCodes .= self::u16($end);
            $startCodes .= self::u16($start);
            $idDeltas .= self::i16(($glyphId - $start) & 0xFFFF);
            $idRangeOffsets .= self::u16(0);
        }

        $format4Body = self::u16(0)
            .self::u16($segCount * 2)
            .self::u16($searchRange)
            .self::u16($entrySelector)
            .self::u16($rangeShift)
            .$endCodes
            .self::u16(0)
            .$startCodes
            .$idDeltas
            .$idRangeOffsets;
        $format4 = self::u16(4).self::u16(2 + 2 + \strlen($format4Body)).$format4Body;

        return self::u16(0).self::u16(1).self::u16(3).self::u16(1).self::u32(12).$format4;
    }

    /**
     * @param list<array{0: int, 1: int}> $points
     */
    private static function simpleGlyph(array $points): string
    {
        $flags = str_repeat("\x01", \count($points));
        $xCoordinates = '';
        $yCoordinates = '';
        $previousX = 0;
        $previousY = 0;

        foreach ($points as [$x, $y]) {
            $xCoordinates .= self::i16($x - $previousX);
            $yCoordinates .= self::i16($y - $previousY);
            $previousX = $x;
            $previousY = $y;
        }

        return self::i16(1)
            .self::i16(min(array_column($points, 0)))
            .self::i16(min(array_column($points, 1)))
            .self::i16(max(array_column($points, 0)))
            .self::i16(max(array_column($points, 1)))
            .self::u16(\count($points) - 1)
            .self::u16(0)
            .$flags
            .$xCoordinates
            .$yCoordinates;
    }

    private static function compoundGlyph(
        int $glyphId,
        int $dx,
        int $dy,
        bool $useMyMetrics = false,
        int $extraFlags = 0,
        string $extraData = '',
        string $instructions = '',
    ): string {
        $flags = 0x0001 | 0x0002 | $extraFlags;

        if ('' !== $instructions) {
            $flags |= 0x0100;
        }

        if ($useMyMetrics) {
            $flags |= 0x0200;
        }

        return self::i16(-1)
            .self::i16(0)
            .self::i16(0)
            .self::i16(550)
            .self::i16(700)
            .self::u16($flags)
            .self::u16($glyphId)
            .self::i16($dx)
            .self::i16($dy)
            .$extraData
            .('' === $instructions ? '' : self::u16(\strlen($instructions)).$instructions);
    }

    private static function pointMatchedCompoundGlyph(int $glyphId): string
    {
        return self::i16(-1)
            .self::i16(0)
            .self::i16(0)
            .self::i16(550)
            .self::i16(700)
            .self::u16(0x0001)
            .self::u16($glyphId)
            .self::u16(0)
            .self::u16(0);
    }

    private static function align4(string $data): string
    {
        return $data.str_repeat("\0", (4 - \strlen($data) % 4) % 4);
    }

    private static function pad2(string $data): string
    {
        return $data.str_repeat("\0", \strlen($data) % 2);
    }

    private static function put(string &$data, int $offset, string $value): void
    {
        $data = substr_replace($data, $value, $offset, \strlen($value));
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u8(int $value): string
    {
        return pack('C', $value & 0xFF);
    }

    private static function i16(int $value): string
    {
        return self::u16($value);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value);
    }

    private static function fixed(float $value): string
    {
        return self::u32((int) round($value * 65536.0));
    }

    private static function f2dot14(float $value): string
    {
        return self::u16((int) round($value * 16384.0));
    }

    /**
     * @param list<int> $points
     */
    private static function pointNumbers(array $points): string
    {
        $previous = 0;
        $deltas = [];

        foreach ($points as $point) {
            $deltas[] = $point - $previous;
            $previous = $point;
        }

        return self::u8(\count($points)).self::u8(\count($deltas) - 1).implode('', array_map(
            static fn (int $delta): string => self::u8($delta),
            $deltas,
        ));
    }

    /**
     * @param list<int> $deltas
     */
    private static function deltaBytes(array $deltas): string
    {
        return self::u8(\count($deltas) - 1).implode('', array_map(
            static fn (int $delta): string => pack('c', $delta),
            $deltas,
        ));
    }

    private static function zeroDeltas(int $count): string
    {
        return self::u8(0x80 | ($count - 1));
    }

    private static function woff2NullTransformFlags(string $tag, int $tagIndex): int
    {
        if ('glyf' === $tag || 'loca' === $tag) {
            return (3 << 6) | $tagIndex;
        }

        return $tagIndex;
    }

    private static function base128(int $value): string
    {
        if ($value < 0) {
            throw new \LogicException('UIntBase128 fixture values must be positive.');
        }

        $bytes = [$value & 0x7F];
        $value >>= 7;

        while ($value > 0) {
            array_unshift($bytes, 0x80 | ($value & 0x7F));
            $value >>= 7;
        }

        return implode('', array_map(
            static fn (int $byte): string => self::u8($byte),
            $bytes,
        ));
    }

    private static function brotliCompress(string $data): ?string
    {
        $process = proc_open(
            ['brotli', '--stdout', '--quality=11'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $data);
        fclose($pipes[0]);
        $compressed = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (0 !== proc_close($process) || !\is_string($compressed)) {
            return null;
        }

        return $compressed;
    }

    private static function utf16beAscii(string $value): string
    {
        $encoded = '';

        foreach (str_split($value) as $character) {
            $encoded .= "\0".$character;
        }

        return $encoded;
    }
}
