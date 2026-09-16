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

namespace Alto\Font\Tests\Validation;

use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OversizedPairSetShapingTest extends TestCase
{
    #[DataProvider('scenarioProvider')]
    public function testCompactedContainersPreserveShaping(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/alto-font-shaping-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        try {
            $source = $directory . '/source.ttf';

            FontValidationTools::run([
                FontValidationTools::python(),
                __DIR__ . '/oversized_pairset_generate.py',
                $scenario,
                $source,
            ]);
            FontValidationTools::sanitize($source, $directory . '/source-sanitized.ttf');
            $font = Font::fromFile($source);
            $subset = $font->subset(new SubsetOptions(
                UnicodeSet::fromCss('U+F0001-F2EE0'),
                glyphIds: GlyphIdPolicy::Compact,
            ))->font;
            self::assertSame(12001, $subset->face()->glyphCount);

            $decodedFiles = [];

            foreach ([
                'ttf' => new SfntWriter(),
                'woff' => new WoffWriter(),
                'woff2' => new Woff2Writer(new BrotliExtensionCompressor()),
            ] as $extension => $writer) {
                $output = $directory . '/subset.' . $extension;
                $decoded = $output . '.sanitized.ttf';
                $writer->write($subset, $output);
                // OTS independently decodes each container for HarfBuzz.
                FontValidationTools::sanitize($output, $decoded);
                $decodedFiles[] = $decoded;
            }

            FontValidationTools::run([
                FontValidationTools::python(),
                __DIR__ . '/oversized_pairset_compare.py',
                $scenario,
                $source,
                ...$decodedFiles,
            ]);

        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scenarioProvider(): iterable
    {
        yield 'Oversized explicit PairSet with both value records' => ['values'];
        yield 'Oversized explicit PairSet with Device adjustments' => ['device'];
    }
}
