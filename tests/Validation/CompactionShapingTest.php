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
final class CompactionShapingTest extends TestCase
{
    #[DataProvider('scenarioProvider')]
    public function testCompactedContainersPreserveShaping(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/alto-font-shaping-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        try {
            $source = $directory . '/source.ttf';

            if ('inter' === $scenario) {
                $font = Font::fromFile(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
                new SfntWriter()->write($font, $source);
                // Select both composed and decomposed forms used by the shaper.
                $unicodes = UnicodeSet::fromText("AVATAR To WA office ffi fi fl 0123456789 A\u{0301} Á e\u{0308} ë o\u{0302} ô");
            } elseif (\in_array($scenario, ['arabic', 'devanagari'], true)) {
                $fixture = 'arabic' === $scenario ? 'NotoNaskhArabic' : 'NotoSansDevanagari';
                self::assertTrue(copy(__DIR__ . '/../Fixtures/Fonts/' . $fixture . '-Regular.ttf', $source));
                $font = Font::fromFile($source);
                $unicodes = UnicodeSet::fromText('arabic' === $scenario
                    ? 'سلام العربية لَا مُحَمَّد بسم الله'
                    : 'नमस्ते हिन्दी क्षि त्रि श्र ज्ञ कि क्र र्क');
            } elseif (\in_array($scenario, ['cjk', 'recursive'], true)) {
                $fixture = 'cjk' === $scenario ? 'AltoCorpusCJK' : 'AltoCorpusVariable';
                self::assertTrue(copy(__DIR__ . '/../Fixtures/Fonts/' . $fixture . '.ttf', $source));
                $font = Font::fromFile($source);
                $unicodes = UnicodeSet::fromText('cjk' === $scenario
                    ? "日本語の組版、「縦書き」。漢字かなカナ がぎぐげごぱぴぷぺぽか\u{3099}"
                    : "AVATAR To WA office ffi fi fl 0123456789 agijlrs A\u{0301} Á e\u{0308} ë o\u{0302} ô");
            } elseif (\in_array($scenario, ['layout', 'vertical', 'carets', 'variable-carets'], true)) {
                FontValidationTools::run([
                    FontValidationTools::python(),
                    __DIR__ . '/generate_layout.py',
                    $scenario,
                    $source,
                ]);
                $font = Font::fromFile($source);
                $unicodes = UnicodeSet::fromText("abcdfi\u{0301}");
            } else {
                FontValidationTools::run([
                    FontValidationTools::python(),
                    __DIR__ . '/generate_pairpos.py',
                    $scenario,
                    $source,
                ]);
                $font = Font::fromFile($source);
                $unicodes = UnicodeSet::fromCss('U+F0001-F2EE0');
            }

            // Reject malformed fixtures before comparing any generated output.
            FontValidationTools::sanitize($source, $directory . '/source-sanitized.ttf');
            $subset = $font->subset(new SubsetOptions($unicodes, glyphIds: GlyphIdPolicy::Compact))->font;

            if (!\in_array($scenario, ['rows', 'fallback'], true)) {
                self::assertLessThan($font->face()->glyphCount, $subset->face()->glyphCount);
            } else {
                // Every synthetic glyph is retained, so glyph IDs can be compared directly.
                self::assertSame(12001, $subset->face()->glyphCount);
            }

            $decodedFiles = [];
            $originalFiles = [];

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
                $originalFiles[] = $output;
            }

            FontValidationTools::run([
                FontValidationTools::python(),
                __DIR__ . '/compare_shaping.py',
                $scenario,
                $source,
                ...$decodedFiles,
                '--original-outputs',
                ...$originalFiles,
            ]);

            if (\in_array($scenario, ['carets', 'variable-carets'], true)) {
                FontValidationTools::run([
                    FontValidationTools::python(),
                    __DIR__ . '/compare_carets.py',
                    $scenario,
                    $source,
                    ...$decodedFiles,
                ]);
            }
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
        yield 'PairPos class-row splitting' => ['rows'];
        yield 'PairPos glyph-pair fallback and splitting' => ['fallback'];
        yield 'Inter glyph remapping and layout' => ['inter'];
        yield 'Noto Naskh Arabic joining and marks' => ['arabic'];
        yield 'Noto Sans Devanagari conjuncts and reordering' => ['devanagari'];
        yield 'Bounded Japanese horizontal and vertical layout' => ['cjk'];
        yield 'Recursive production multi-axis variation and marks' => ['recursive'];
        yield 'Contextual substitutions and attachments' => ['layout'];
        yield 'Shared vertical variation data' => ['vertical'];
        yield 'Ligature caret Device adjustments' => ['carets'];
        yield 'Ligature caret variations and reordered stores' => ['variable-carets'];
    }
}
