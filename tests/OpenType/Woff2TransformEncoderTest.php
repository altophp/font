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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\OpenType\SfntDocument;
use Alto\Font\OpenType\Woff2TransformEncoder;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Woff2TransformEncoder::class)]
final class Woff2TransformEncoderTest extends TestCase
{
    public function testItTransformsGlyfLocaAndHmtxTogether(): void
    {
        $document = self::document();
        [$tags, $entries] = Woff2TransformEncoder::encode($document);
        $glyfIndex = array_search('glyf', $tags, true);

        self::assertIsInt($glyfIndex);
        self::assertSame('loca', $tags[$glyfIndex + 1]);
        self::assertSame(0, $entries['glyf']['transformVersion']);
        self::assertNotSame('', $entries['glyf']['data']);
        self::assertSame(strlen($document->table('glyf') ?? ''), $entries['glyf']['originalLength']);
        self::assertGreaterThan(0, $entries['glyf']['reconstructedLength']);
        self::assertSame(0, $entries['loca']['transformVersion']);
        self::assertSame('', $entries['loca']['data']);
        self::assertSame(1, $entries['hmtx']['transformVersion']);
    }

    public function testItLeavesMetricsUntransformedWithoutGlyfContext(): void
    {
        [, $entries] = Woff2TransformEncoder::encode(self::document()->withTables([], ['glyf', 'loca']));

        self::assertSame(0, $entries['hmtx']['transformVersion']);
    }

    #[DataProvider('invalidTableCases')]
    public function testItRejectsInvalidSourceTables(string $case, string $message): void
    {
        $document = self::document();
        $head = $document->table('head') ?? '';
        $loca = $document->table('loca') ?? '';
        $glyf = $document->table('glyf') ?? '';
        $glyphOffset = self::glyphOffset($document, 1);

        $document = match ($case) {
            'loca-format' => $document->withTables(['head' => substr_replace($head, pack('n', 2), 50, 2)]),
            'loca-length' => $document->withTables(['loca' => substr($loca, 0, -4)]),
            'loca-offset' => $document->withTables(['loca' => substr_replace($loca, pack('N', strlen($glyf) + 4), 4, 4)]),
            'contour-count' => $document->withTables(['glyf' => substr_replace($glyf, pack('n', 0xFFFE), $glyphOffset, 2)]),
            'end-points' => $document->withTables(['glyf' => substr_replace($glyf, pack('n', 2), $glyphOffset, 2)]),
            'repeated-flags' => $document->withTables(['glyf' => substr_replace($glyf, "\x09\xFF", $glyphOffset + 14, 2)]),
            default => throw new \LogicException('Unknown source-table case.'),
        };

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        Woff2TransformEncoder::encode($document);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTableCases(): iterable
    {
        yield 'invalid loca format' => ['loca-format', 'invalid loca format 2'];
        yield 'wrong loca length' => ['loca-length', 'loca table length does not match'];
        yield 'loca offset outside glyf' => ['loca-offset', 'loca offsets are invalid'];
        yield 'invalid contour count' => ['contour-count', 'uses invalid contour count -2'];
        yield 'non-monotonic contour endpoints' => ['end-points', 'invalid contour endpoints'];
        yield 'flag repeat exceeds point count' => ['repeated-flags', 'repeats flags beyond its point count'];
    }

    #[DataProvider('compoundTransforms')]
    public function testItTransformsEveryCompositeScaleEncoding(string $writer): void
    {
        [, $entries] = Woff2TransformEncoder::encode(self::document($writer));

        self::assertSame(0, $entries['glyf']['transformVersion']);
        self::assertNotSame('', $entries['glyf']['data']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function compoundTransforms(): iterable
    {
        yield 'uniform scale' => ['writeCompoundWithUniformScale'];
        yield 'independent x and y scales' => ['writeCompoundWithXYScale'];
        yield 'two by two matrix' => ['writeCompoundWithTwoByTwo'];
        yield 'instructions' => ['writeCompoundWithInstructions'];
    }

    public function testItFallsBackForUnsupportedOrLossyGlyphs(): void
    {
        foreach (['writeWithCubicPoint', 'writeWithZeroContourGlyphData', 'writeWithTrailingSimpleGlyphData', 'writeWithTrailingCompositeGlyphData'] as $writer) {
            [, $entries] = Woff2TransformEncoder::encode(self::document($writer));

            self::assertSame(3, $entries['glyf']['transformVersion'], $writer);
            self::assertSame(3, $entries['loca']['transformVersion'], $writer);
        }
    }

    public function testItFallsBackWhenReconstructionWouldOverflowShortLoca(): void
    {
        $document = self::document();
        $numGlyphs = 7000;
        $glyph = pack('n5', 1, 300, 1, 300, 1)
            . pack('n2', 0, 0)
            . "\x25"
            . pack('n', 300)
            . "\x01";
        $loca = '';

        for ($glyphId = 0; $glyphId <= $numGlyphs; ++$glyphId) {
            $loca .= pack('n', intdiv($glyphId * strlen($glyph), 2));
        }

        $head = substr_replace($document->table('head') ?? '', pack('n', 0), 50, 2);
        $maxp = substr_replace($document->table('maxp') ?? '', pack('n', $numGlyphs), 4, 2);
        $document = $document->withTables([
            'glyf' => str_repeat($glyph, $numGlyphs),
            'head' => $head,
            'loca' => $loca,
            'maxp' => $maxp,
        ], ['hhea', 'hmtx']);

        [, $entries] = Woff2TransformEncoder::encode($document);

        self::assertSame(3, $entries['glyf']['transformVersion']);
        self::assertSame(3, $entries['loca']['transformVersion']);
    }

    #[DataProvider('hmtxTransformCases')]
    public function testItSelectsTheLosslessHmtxTransform(int $expectedFlags, bool $proportionalMatch, bool $trailingMatch): void
    {
        $document = self::document();
        $xMins = self::glyphXMins($document);
        $hhea = substr_replace($document->table('hhea') ?? '', pack('n', 3), 34, 2);
        $hmtx = '';

        for ($glyphId = 0; $glyphId < 3; ++$glyphId) {
            $bearing = $proportionalMatch ? $xMins[$glyphId] : $xMins[$glyphId] + 1;
            $hmtx .= pack('n2', 500 + $glyphId, $bearing & 0xFFFF);
        }

        for ($glyphId = 3; $glyphId < count($xMins); ++$glyphId) {
            $bearing = $trailingMatch ? $xMins[$glyphId] : $xMins[$glyphId] + 1;
            $hmtx .= pack('n', $bearing & 0xFFFF);
        }

        [, $entries] = Woff2TransformEncoder::encode($document->withTables(['hhea' => $hhea, 'hmtx' => $hmtx]));

        if (0 === $expectedFlags) {
            self::assertSame(0, $entries['hmtx']['transformVersion']);
            self::assertSame($hmtx, $entries['hmtx']['data']);

            return;
        }

        self::assertSame(1, $entries['hmtx']['transformVersion']);
        self::assertSame($expectedFlags, ord($entries['hmtx']['data'][0]));
    }

    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function hmtxTransformCases(): iterable
    {
        yield 'both bearing arrays derived' => [3, true, true];
        yield 'proportional bearings derived' => [1, true, false];
        yield 'trailing bearings derived' => [2, false, true];
        yield 'neither array derived' => [0, false, false];
    }

    public function testItLeavesMalformedHmtxUntransformed(): void
    {
        $document = self::document();
        $hmtx = ($document->table('hmtx') ?? '') . "\0";

        [, $entries] = Woff2TransformEncoder::encode($document->withTables(['hmtx' => $hmtx]));

        self::assertSame(0, $entries['hmtx']['transformVersion']);
        self::assertSame($hmtx, $entries['hmtx']['data']);
    }

    public function testItRejectsInvalidHorizontalMetricCounts(): void
    {
        $document = self::document();
        $hhea = substr_replace($document->table('hhea') ?? '', "\0\0", 34, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('hmtx metrics counts are invalid');

        Woff2TransformEncoder::encode($document->withTables(['hhea' => $hhea]));
    }

    public function testItPreservesExplicitBoundsAndTheOverlapFlag(): void
    {
        $document = self::document();
        $glyf = $document->table('glyf') ?? '';
        $glyphOffset = self::glyphOffset($document, 1);
        $glyf = substr_replace($glyf, pack('n', 99), $glyphOffset + 2, 2);
        $glyf[$glyphOffset + 14] = pack('C', ord($glyf[$glyphOffset + 14]) | 0x40);

        [, $entries] = Woff2TransformEncoder::encode($document->withTables(['glyf' => $glyf]));
        $transformed = new BinaryReader($entries['glyf']['data'], 'transformed glyf');

        self::assertSame(0, $entries['glyf']['transformVersion']);
        self::assertSame(1, $transformed->uint16(2));
        self::assertGreaterThan(4, $transformed->uint32(28));
        self::assertSame(0x40, ord($entries['glyf']['data'][strlen($entries['glyf']['data']) - 1]));
    }

    private static function document(string $writer = 'write'): SfntDocument
    {
        $path = sys_get_temp_dir() . '/alto-font-woff2-encoder-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::$writer($path);

        return Font::fromFile($path)->sfntDocument();
    }

    private static function glyphOffset(SfntDocument $document, int $glyphId): int
    {
        $head = new BinaryReader($document->table('head') ?? '', 'test head');
        $loca = new BinaryReader($document->table('loca') ?? '', 'test loca');

        return 0 === $head->int16(50) ? $loca->uint16($glyphId * 2) * 2 : $loca->uint32($glyphId * 4);
    }

    /**
     * @return list<int>
     */
    private static function glyphXMins(SfntDocument $document): array
    {
        $maxp = new BinaryReader($document->table('maxp') ?? '', 'test maxp');
        $glyf = new BinaryReader($document->table('glyf') ?? '', 'test glyf');
        $xMins = [];

        for ($glyphId = 0; $glyphId < $maxp->uint16(4); ++$glyphId) {
            $start = self::glyphOffset($document, $glyphId);
            $end = self::glyphOffset($document, $glyphId + 1);
            $xMins[] = $start === $end ? 0 : $glyf->int16($start + 2);
        }

        return $xMins;
    }

}
