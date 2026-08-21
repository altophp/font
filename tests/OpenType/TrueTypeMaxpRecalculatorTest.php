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
use Alto\Font\OpenType\TrueTypeMaxpRecalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrueTypeMaxpRecalculator::class)]
final class TrueTypeMaxpRecalculatorTest extends TestCase
{
    public function testItRecalculatesSimpleAndRecursiveCompositeMaxima(): void
    {
        $glyphs = [
            '',
            self::simpleGlyph([2], 2),
            self::simpleGlyph([1, 4], 4),
            self::compoundGlyph([1, 2], 3),
            self::compoundGlyph([3]),
        ];
        [$glyf, $offsets] = self::glyf($glyphs);
        $maxp = TrueTypeMaxpRecalculator::recalculate(
            self::maxp(0xFFFF),
            $glyf,
            $offsets,
            [0 => true, 1 => true, 2 => true, 3 => true, 4 => true],
        );
        $reader = new BinaryReader($maxp, 'recalculated maxp');

        self::assertSame([5, 5, 2, 8, 3], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
            $reader->uint16(10),
            $reader->uint16(12),
        ]);
        self::assertSame([0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF], [
            $reader->uint16(14),
            $reader->uint16(16),
            $reader->uint16(18),
            $reader->uint16(20),
            $reader->uint16(22),
            $reader->uint16(24),
        ]);
        self::assertSame([4, 2, 2], [
            $reader->uint16(26),
            $reader->uint16(28),
            $reader->uint16(30),
        ]);
    }

    public function testItExcludesDiscardedGlyphGeometryAndInstructions(): void
    {
        $glyphs = ['', self::simpleGlyph([1], 1), self::simpleGlyph([8], 12)];
        [$glyf, $offsets] = self::glyf($glyphs);
        $maxp = TrueTypeMaxpRecalculator::recalculate(
            self::maxp(99),
            $glyf,
            $offsets,
            [0 => true, 1 => true],
        );
        $reader = new BinaryReader($maxp, 'filtered maxp');

        self::assertSame([3, 2, 1, 1], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
            $reader->uint16(26),
        ]);
    }

    public function testItHandlesAnEmptyRetainedGlyphSetProfile(): void
    {
        $maxp = TrueTypeMaxpRecalculator::recalculate(self::maxp(7), '', [0, 0], [0 => true]);
        $reader = new BinaryReader($maxp, 'empty maxp');

        self::assertSame(1, $reader->uint16(4));

        foreach ([6, 8, 10, 12, 26, 28, 30] as $offset) {
            self::assertSame(0, $reader->uint16($offset));
        }
    }

    /**
     * @param list<int>        $offsets
     * @param array<int, true> $retained
     */
    #[DataProvider('invalidFonts')]
    public function testItFailsClosedForMalformedProfiles(
        string $maxp,
        string $glyf,
        array $offsets,
        array $retained,
        string $message,
    ): void {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        TrueTypeMaxpRecalculator::recalculate($maxp, $glyf, $offsets, $retained);
    }

    /**
     * @return iterable<string, array{string, string, list<int>, array<int, true>, string}>
     */
    public static function invalidFonts(): iterable
    {
        yield 'truncated maxp' => [str_repeat("\0", 31), '', [0, 0], [0 => true], 'maxp version 1.0 table is truncated'];
        yield 'wrong maxp version' => ["\0\0P\0" . str_repeat("\0", 28), '', [0, 0], [0 => true], 'require maxp version 1.0'];
        yield 'no glyph offsets' => [self::maxp(0), '', [0], [], 'valid glyph count'];
        yield 'retained glyph outside range' => [self::maxp(0), '', [0, 0], [1 => true], 'outside the maxp glyph range'];
        yield 'invalid loca bounds' => [self::maxp(0), '', [0, 1], [0 => true], 'invalid glyf offsets'];

        $decreasingEndpoints = self::glyphHeader(2) . self::uint16(3) . self::uint16(3) . self::uint16(0);
        yield 'decreasing contour endpoints' => [self::maxp(0), $decreasingEndpoints, [0, \strlen($decreasingEndpoints)], [0 => true], 'not strictly increasing'];

        $repeatedFlags = self::glyphHeader(1) . self::uint16(0) . self::uint16(0) . "\x39\x01";
        yield 'flag repetition overflow' => [self::maxp(0), $repeatedFlags, [0, \strlen($repeatedFlags)], [0 => true], 'flag repetitions exceed'];

        $missingCoordinates = self::glyphHeader(1) . self::uint16(0) . self::uint16(0) . "\x01";
        yield 'coordinate overflow' => [self::maxp(0), $missingCoordinates, [0, \strlen($missingCoordinates)], [0 => true], 'coordinates exceed'];

        $discardedReference = self::compoundGlyph([1]);
        yield 'discarded component' => [self::maxp(0), $discardedReference, [0, \strlen($discardedReference), \strlen($discardedReference)], [0 => true], 'references discarded glyph ID 1'];

        $cycle = self::compoundGlyph([0]);
        yield 'component cycle' => [self::maxp(0), $cycle, [0, \strlen($cycle)], [0 => true], 'Compound glyph cycle'];

        $conflictingTransform = self::glyphHeader(-1) . self::uint16(0x0001 | 0x0008 | 0x0040) . self::uint16(0) . str_repeat("\0", 8);
        yield 'conflicting transforms' => [self::maxp(0), $conflictingTransform, [0, \strlen($conflictingTransform)], [0 => true], 'conflicting transform flags'];

        $truncatedComponent = self::glyphHeader(-1) . self::uint16(0x0001) . self::uint16(0);
        yield 'truncated component' => [self::maxp(0), $truncatedComponent, [0, \strlen($truncatedComponent)], [0 => true], 'components exceed'];

        $truncatedInstructions = self::glyphHeader(-1)
            . self::uint16(0x0001 | 0x0100) . self::uint16(1) . str_repeat("\0", 4)
            . self::uint16(3) . "\x01";
        $truncatedInstructionsGlyf = $truncatedInstructions;
        yield 'truncated compound instructions' => [
            self::maxp(0),
            $truncatedInstructionsGlyf,
            [0, \strlen($truncatedInstructions), \strlen($truncatedInstructionsGlyf)],
            [0 => true, 1 => true],
            'instructions exceed',
        ];
    }

    /**
     * @param list<int> $endPoints
     */
    private static function simpleGlyph(array $endPoints, int $instructionLength): string
    {
        $pointCount = [] === $endPoints ? 0 : max($endPoints) + 1;
        $glyph = self::glyphHeader(\count($endPoints));

        foreach ($endPoints as $endPoint) {
            $glyph .= self::uint16($endPoint);
        }

        $glyph .= self::uint16($instructionLength) . str_repeat("\xAA", $instructionLength);

        if ($pointCount > 0) {
            if ($pointCount > 256) {
                throw new \LogicException('Test glyphs support at most 256 points.');
            }

            $glyph .= "\x39" . chr($pointCount - 1);
        }

        return $glyph;
    }

    /**
     * @param list<int> $componentGlyphIds
     */
    private static function compoundGlyph(array $componentGlyphIds, int $instructionLength = 0): string
    {
        $glyph = self::glyphHeader(-1);
        $last = \count($componentGlyphIds) - 1;

        foreach ($componentGlyphIds as $index => $componentGlyphId) {
            $flags = 0x0001 | 0x0002;

            if ($index < $last) {
                $flags |= 0x0020;
            } elseif ($instructionLength > 0) {
                $flags |= 0x0100;
            }

            $glyph .= self::uint16($flags) . self::uint16($componentGlyphId) . str_repeat("\0", 4);
        }

        if ($instructionLength > 0) {
            $glyph .= self::uint16($instructionLength) . str_repeat("\xBB", $instructionLength);
        }

        return $glyph;
    }

    private static function glyphHeader(int $contourCount): string
    {
        return self::uint16($contourCount) . str_repeat("\0", 8);
    }

    /**
     * @param list<string> $glyphs
     *
     * @return array{string, list<int>}
     */
    private static function glyf(array $glyphs): array
    {
        $glyf = '';
        $offsets = [];

        foreach ($glyphs as $glyph) {
            $offsets[] = \strlen($glyf);
            $glyf .= $glyph;
        }

        $offsets[] = \strlen($glyf);

        return [$glyf, $offsets];
    }

    private static function maxp(int $fill): string
    {
        return "\0\1\0\0" . str_repeat(self::uint16($fill), 14);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
