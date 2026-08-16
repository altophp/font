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
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . '/alto-font-ots-' . bin2hex(random_bytes(8));

if (!mkdir($directory, 0700)) {
    throw new RuntimeException(sprintf('Unable to create validation directory "%s".', $directory));
}

try {
    $font = Font::fromFile(dirname(__DIR__) . '/tests/Fixtures/Fonts/Inter-Regular-latin.woff2');
    $unicodes = UnicodeSet::fromText('Alto Font 0123456789');
    $fonts = [
        'complete font' => $font,
        'preserved-glyph subset' => $font->subset(new SubsetOptions($unicodes))->font,
        'compact subset' => $font->subset(new SubsetOptions(
            $unicodes,
            hinting: HintingPolicy::Drop,
            glyphIds: GlyphIdPolicy::Compact,
            layout: LayoutPolicy::Drop,
        ))->font,
    ];

    foreach ($fonts as $scenario => $generatedFont) {
        $prefix = $directory . '/' . str_replace(' ', '-', $scenario);
        $generatedFiles = [
            'SFNT' => $prefix . '.ttf',
            'WOFF' => $prefix . '.woff',
            'WOFF2' => $prefix . '.woff2',
        ];

        new SfntWriter()->write($generatedFont, $generatedFiles['SFNT']);
        new WoffWriter()->write($generatedFont, $generatedFiles['WOFF']);
        new Woff2Writer(new BrotliExtensionCompressor())->write($generatedFont, $generatedFiles['WOFF2']);

        foreach ($generatedFiles as $format => $file) {
            validateWithOts(
                $scenario . ' ' . $format,
                $file,
                $prefix . '-sanitized-' . strtolower($format) . '.ttf',
            );
        }
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($directory);
}

/**
 * @param non-empty-string $format
 * @param non-empty-string $source
 * @param non-empty-string $destination
 */
function validateWithOts(string $format, string $source, string $destination): void
{
    $process = @proc_open(
        ['python3', '-m', 'ots', $source, $destination],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Python for OpenType Sanitizer.');
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (0 !== $exitCode) {
        $detail = trim(implode("\n", array_filter([$output, $error])));

        throw new RuntimeException(sprintf(
            'OpenType Sanitizer rejected generated %s.%s%s',
            $format,
            '' === $detail ? '' : "\n",
            '' === $detail ? ' Install opentype-sanitizer==9.2.0 first.' : $detail,
        ));
    }

    printf("Validated generated %s with OpenType Sanitizer.\n", $format);
}
