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

namespace Alto\Font\Tests\OpenType\Layout;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\LayoutTableDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LayoutTableDirectory::class)]
final class LayoutTableDirectoryTest extends TestCase
{
    public function testItCanonicallyReordersVersionOnePointZeroSections(): void
    {
        $source = self::u16(1)
            . self::u16(0)
            . self::u16(12)
            . self::u16(14)
            . self::u16(10)
            . self::u16(0)
            . self::u16(0)
            . self::u16(0);
        $directory = LayoutTableDirectory::parse(new BinaryReader($source, 'GSUB'), 'GSUB');
        $output = $directory->build("\0\0");
        $reader = new BinaryReader($output, 'compacted GSUB');

        self::assertSame(10, $directory->lookupListOffset);
        self::assertSame([10, 12, 14], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
        ]);
        self::assertSame(16, $reader->length());
    }

    public function testItPreservesVersionOnePointOneFeatureVariations(): void
    {
        $featureVariations = self::u16(1) . self::u16(0) . self::u32(0);
        $source = self::u16(1)
            . self::u16(1)
            . self::u16(26)
            . self::u16(24)
            . self::u16(22)
            . self::u32(14)
            . $featureVariations
            . self::u16(0)
            . self::u16(0)
            . self::u16(0);
        $directory = LayoutTableDirectory::parse(new BinaryReader($source, 'GPOS'), 'GPOS');
        $output = $directory->build("\0\0");
        $reader = new BinaryReader($output, 'compacted GPOS');

        self::assertSame([1, 1], [$reader->uint16(0), $reader->uint16(2)]);
        self::assertSame([14, 16, 18, 20], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
            $reader->uint32(10),
        ]);
        self::assertSame($featureVariations, $reader->string(20, 8));
    }

    public function testItRebuildsInterleavedScriptAndFeatureTrees(): void
    {
        $source = str_repeat("\0", 44);
        self::write($source, 0, self::u16(1)
            . self::u16(0)
            . self::u16(12)
            . self::u16(20)
            . self::u16(10));
        self::write($source, 10, self::u16(0));
        self::write($source, 12, self::u16(1) . 'latn' . self::u16(16));
        self::write($source, 20, self::u16(1) . 'liga' . self::u16(20));
        self::write($source, 28, self::u16(4) . self::u16(0));
        self::write($source, 32, self::u16(0) . self::u16(0xFFFF) . self::u16(1) . self::u16(0));
        self::write($source, 40, self::u16(0) . self::u16(0));

        $directory = LayoutTableDirectory::parse(new BinaryReader($source, 'GPOS'), 'GPOS');
        $output = $directory->build(self::u16(0));
        $reader = new BinaryReader($output, 'compacted GPOS');

        self::assertSame([10, 30, 42], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
        ]);
        self::assertSame('latn', $reader->string(12, 4));
        self::assertSame(8, $reader->uint16(16));
        self::assertSame(4, $reader->uint16(18));
        self::assertSame(0, $reader->uint16(22));
        self::assertSame(0xFFFF, $reader->uint16(24));
        self::assertSame(1, $reader->uint16(26));
        self::assertSame(0, $reader->uint16(28));
        self::assertSame('liga', $reader->string(32, 4));
        self::assertSame(8, $reader->uint16(36));
        self::assertSame(0, $reader->uint16(38));
        self::assertSame(0, $reader->uint16(40));
        self::assertSame(44, $reader->length());
    }

    public function testItRebuildsNamedLanguageSystemsAndLookupIndexes(): void
    {
        $source = self::layoutWithNamedLanguage();
        $directory = LayoutTableDirectory::parse(new BinaryReader($source, 'GSUB'), 'GSUB');
        $output = $directory->build(substr($source, 50));
        $reader = new BinaryReader($output, 'compacted GSUB');

        self::assertSame([10, 36, 50], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
        ]);
        self::assertSame('FRA ', $reader->string(22, 4));
        self::assertSame([10, 0, 0, 1, 0], [
            $reader->uint16(26),
            $reader->uint16(28),
            $reader->uint16(30),
            $reader->uint16(32),
            $reader->uint16(34),
        ]);
        self::assertSame([0, 1, 0], [
            $reader->uint16(44),
            $reader->uint16(46),
            $reader->uint16(48),
        ]);
    }

    public function testItRejectsInvalidScriptFeatureAndLanguageReferences(): void
    {
        $cases = [
            [16, self::u16(0), 'script 0 offset must not be NULL'],
            [26, self::u16(0), 'language-system 0 offset must not be NULL'],
            [28, self::u16(1), 'lookup-order data is not supported'],
            [30, self::u16(1), 'required feature index is out of range'],
            [34, self::u16(1), 'feature index is out of range'],
            [42, self::u16(0), 'feature 0 offset must not be NULL'],
            [48, self::u16(1), 'feature lookup index is out of range'],
        ];

        foreach ($cases as [$offset, $replacement, $message]) {
            $source = self::layoutWithNamedLanguage();
            self::write($source, $offset, $replacement);

            try {
                LayoutTableDirectory::parse(new BinaryReader($source, 'GSUB'), 'GSUB');
                self::fail('Expected the malformed layout directory to be rejected.');
            } catch (InvalidFontException|UnsupportedFontException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testItRebuildsInterleavedFeatureVariationTrees(): void
    {
        $source = str_repeat("\0", 78);
        self::write($source, 0, self::u16(1)
            . self::u16(1)
            . self::u16(16)
            . self::u16(18)
            . self::u16(14)
            . self::u32(26));
        self::write($source, 14, self::u16(0));
        self::write($source, 16, self::u16(0));
        self::write($source, 18, self::u16(1) . 'liga' . self::u16(24));
        self::write($source, 26, self::u16(1)
            . self::u16(0)
            . self::u32(1)
            . self::u32(20)
            . self::u32(26));
        self::write($source, 42, self::u16(0) . self::u16(0));
        self::write($source, 46, self::u16(1) . self::u32(20));
        self::write($source, 52, self::u16(1)
            . self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u32(22));
        self::write($source, 66, self::u16(1) . self::u16(2) . self::u16(0xC000) . self::u16(0x2000));
        self::write($source, 74, self::u16(0) . self::u16(0));

        $directory = LayoutTableDirectory::parse(new BinaryReader($source, 'GSUB'), 'GSUB');
        $output = $directory->build(self::u16(0));
        $reader = new BinaryReader($output, 'compacted GSUB');

        self::assertSame([14, 16, 28, 30], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
            $reader->uint32(10),
        ]);
        self::assertSame([1, 0, 1], [
            $reader->uint16(30),
            $reader->uint16(32),
            $reader->uint32(34),
        ]);
        self::assertSame([16, 30], [$reader->uint32(38), $reader->uint32(42)]);
        self::assertSame([1, 6], [$reader->uint16(46), $reader->uint32(48)]);
        self::assertSame([1, 2, 0xC000, 0x2000], [
            $reader->uint16(52),
            $reader->uint16(54),
            $reader->uint16(56),
            $reader->uint16(58),
        ]);
        self::assertSame([1, 0, 1, 0, 12], [
            $reader->uint16(60),
            $reader->uint16(62),
            $reader->uint16(64),
            $reader->uint16(66),
            $reader->uint32(68),
        ]);
        self::assertSame([0, 0], [$reader->uint16(72), $reader->uint16(74)]);
        self::assertSame(76, $reader->length());
    }

    public function testItPreservesRegisteredFeatureParameters(): void
    {
        $parameters = [
            'size' => str_repeat("\x01", 10),
            'ss01' => self::u16(0) . self::u16(256),
            'cv99' => self::u16(0)
                . self::u16(256)
                . self::u16(257)
                . self::u16(258)
                . self::u16(2)
                . self::u16(259)
                . self::u16(2)
                . "\x00\x00\x41\x01\xF6\x00",
        ];

        foreach ($parameters as $tag => $params) {
            $source = self::layoutWithFeatureParameters($tag, $params);
            $directory = LayoutTableDirectory::parse(new BinaryReader($source, 'GSUB'), 'GSUB');
            $output = $directory->build(self::u16(0));
            $reader = new BinaryReader($output, 'compacted GSUB');
            $featureList = $reader->uint16(6);
            $feature = $featureList + $reader->uint16($featureList + 6);
            $featureParams = $feature + $reader->uint16($feature);

            self::assertSame($tag, $reader->string($featureList + 2, 4));
            self::assertSame($params, $reader->string($featureParams, \strlen($params)));
        }
    }

    public function testItRejectsParametersForAFeatureWithoutARegisteredFormat(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('liga uses unsupported feature parameters');

        LayoutTableDirectory::parse(
            new BinaryReader(self::layoutWithFeatureParameters('liga', self::u16(0)), 'GSUB'),
            'GSUB',
        );
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('supports versions 1.0 and 1.1 only');

        LayoutTableDirectory::parse(new BinaryReader(self::u16(2) . str_repeat("\0", 12), 'GSUB'), 'GSUB');
    }

    public function testItRejectsInvalidOrDuplicateSectionOffsets(): void
    {
        foreach ([
            self::u16(1) . self::u16(0) . self::u16(9) . self::u16(12) . self::u16(14) . str_repeat("\0", 6),
            self::u16(1) . self::u16(0) . self::u16(10) . self::u16(10) . self::u16(14) . str_repeat("\0", 6),
        ] as $source) {
            try {
                LayoutTableDirectory::parse(new BinaryReader($source, 'GSUB'), 'GSUB');
                self::fail('Expected an invalid layout table exception.');
            } catch (InvalidFontException $exception) {
                self::assertStringContainsString('offset', $exception->getMessage());
            }
        }
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private static function write(string &$data, int $offset, string $value): void
    {
        $data = substr_replace($data, $value, $offset, \strlen($value));
    }

    private static function layoutWithFeatureParameters(string $tag, string $params): string
    {
        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(14)
            . self::u16(12)
            . self::u16(0)
            . self::u16(0)
            . self::u16(1)
            . $tag
            . self::u16(8)
            . self::u16(4)
            . self::u16(0)
            . $params;
    }

    private static function layoutWithNamedLanguage(): string
    {
        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(36)
            . self::u16(50)
            . self::u16(1)
            . 'latn'
            . self::u16(8)
            . self::u16(0)
            . self::u16(1)
            . 'FRA '
            . self::u16(10)
            . self::u16(0)
            . self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u16(1)
            . 'liga'
            . self::u16(8)
            . self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u16(1)
            . self::u16(4);
    }
}
