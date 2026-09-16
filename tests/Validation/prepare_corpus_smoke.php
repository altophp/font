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

use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;

require __DIR__ . '/../../vendor/autoload.php';

$directory = $argv[1] ?? sys_get_temp_dir() . '/alto-font-browser-smoke';

if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Unable to create output directory.');
}

$samples = [
    'cjk' => ['AltoCorpusCJK.ttf', '日本語の組版、「縦書き」。漢字かなカナ がぎぐげご', 'ltr'],
    'recursive' => ['AltoCorpusVariable.ttf', "AVATAR office ffi agijlrs A\u{0301} Á e\u{0308} ë", 'ltr'],
    'arabic' => ['NotoNaskhArabic-Regular.ttf', 'سلام العربية لَا مُحَمَّد', 'rtl'],
    'devanagari' => ['NotoSansDevanagari-Regular.ttf', 'नमस्ते हिन्दी क्षि त्रि श्र ज्ञ कि क्र र्क', 'ltr'],
];
$results = [];

foreach ($samples as $name => [$fixture, $text, $direction]) {
    $measurements = [];

    for ($iteration = 0; $iteration < 3; ++$iteration) {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $started = hrtime(true);
        $font = Font::fromFile(__DIR__ . '/../Fixtures/Fonts/' . $fixture);
        $loaded = hrtime(true);
        $subset = $font->subset(new SubsetOptions(UnicodeSet::fromText($text), glyphIds: GlyphIdPolicy::Compact))->font;
        $compacted = hrtime(true);
        $sizes = [];
        $writeTimes = [];

        foreach (['ttf' => new SfntWriter(), 'woff' => new WoffWriter(), 'woff2' => new Woff2Writer(new BrotliExtensionCompressor())] as $extension => $writer) {
            $writeStarted = hrtime(true);
            $path = $directory . '/' . $name . '.' . $extension;
            if (is_file($path)) {
                unlink($path);
            }
            $writer->write($subset, $path);
            $writeTimes[$extension] = (hrtime(true) - $writeStarted) / 1e6;
            $sizes[$extension] = filesize($path);
        }

        $measurements[] = [
            'load_ms' => ($loaded - $started) / 1e6,
            'compact_ms' => ($compacted - $loaded) / 1e6,
            'write_ms' => $writeTimes,
            'peak_process_bytes' => memory_get_peak_usage(true),
        ];
        $sourceGlyphs = $font->face()->glyphCount;
        $outputGlyphs = $subset->face()->glyphCount;
        unset($font, $subset, $writer);
    }

    if (!copy(__DIR__ . '/../Fixtures/Fonts/' . $fixture, $directory . '/' . $name . '.source.ttf')) {
        throw new RuntimeException('Unable to copy source fixture.');
    }

    $results[] = [
        'name' => $name,
        'fixture' => $fixture,
        'text' => $text,
        'direction' => $direction,
        'source_glyphs' => $sourceGlyphs,
        'output_glyphs' => $outputGlyphs,
        'output_bytes' => $sizes,
        'measurements' => $measurements,
    ];
}

$report = json_encode([
    'php' => PHP_VERSION,
    'platform' => PHP_OS_FAMILY . ' ' . php_uname('m'),
    'measurement_scope' => 'Three sequential runs per fixture; peak process allocation includes PHP baseline and allocator retention. No timing thresholds.',
    'samples' => $results,
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
file_put_contents($directory . '/manifest.json', $report . "\n");
copy(__DIR__ . '/browser_smoke.html', $directory . '/index.html');
echo $report . "\n";
