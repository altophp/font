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
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OpenTypeSanitizerTest extends TestCase
{
    public function testGeneratedFontsPassOpenTypeSanitizer(): void
    {
        $directory = sys_get_temp_dir() . '/alto-font-ots-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        try {
            $font = Font::fromFile(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
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
                    self::assertAcceptedByOts(
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
    }

    private static function assertAcceptedByOts(string $format, string $source, string $destination): void
    {
        $process = proc_open(
            ['python3', '-m', 'ots', $source, $destination],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Unable to start Python for OpenType Sanitizer.');

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(
            0,
            $exitCode,
            sprintf(
                "OpenType Sanitizer rejected generated %s.\n%s%s",
                $format,
                is_string($output) ? $output : '',
                is_string($error) ? $error : '',
            ),
        );
    }
}
