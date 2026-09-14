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
use Alto\Font\OpenType\SfntFont;
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
        $command = self::otsCommand();
        self::assertOtsAvailable($command);
        $directory = sys_get_temp_dir() . '/alto-font-ots-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        try {
            $font = Font::fromFile(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
            $unicodes = UnicodeSet::fromText('ALTO Font 0123456789');
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
            $fonts['compact legacy kern'] = self::withLegacyKern($font)->subset(new SubsetOptions(
                UnicodeSet::fromText('AV'),
                glyphIds: GlyphIdPolicy::Compact,
                layout: LayoutPolicy::Drop,
            ))->font;
            $fonts['compact vertical metrics'] = self::withVerticalMetrics($font)->subset(new SubsetOptions(
                UnicodeSet::fromText('AV'),
                glyphIds: GlyphIdPolicy::Compact,
                layout: LayoutPolicy::Drop,
            ))->font;

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
                        $command,
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

    private static function withLegacyKern(Font $font): Font
    {
        $left = $font->glyphIdForCodepoint(65)?->value;
        $right = $font->glyphIdForCodepoint(86)?->value;
        self::assertNotNull($left);
        self::assertNotNull($right);
        $kern = self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u16(20)
            . self::u16(1)
            . self::u16(1)
            . self::u16(6)
            . self::u16(0)
            . self::u16(0)
            . self::u16($left)
            . self::u16($right)
            . self::u16(-80);

        return self::withTables($font, ['kern' => $kern]);
    }

    private static function withVerticalMetrics(Font $font): Font
    {
        $vhea = str_repeat("\0", 36);
        $vhea = substr_replace($vhea, pack('N', 0x00011000), 0, 4);
        $vhea = substr_replace($vhea, self::u16(1000), 10, 2);
        $vhea = substr_replace($vhea, self::u16($font->face()->glyphCount), 34, 2);

        return self::withTables($font, [
            'vhea' => $vhea,
            'vmtx' => str_repeat(self::u16(1000) . self::u16(0), $font->face()->glyphCount),
        ]);
    }

    /**
     * @param array<string, string> $tables
     */
    private static function withTables(Font $font, array $tables): Font
    {
        return new Font(SfntFont::parse($font->sfntDocument()->withTables($tables)->toSfnt()));
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    /**
     * @param list<string> $command
     */
    private static function assertOtsAvailable(array $command): void
    {
        $process = @proc_open(
            [...$command, '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource(
            $process,
            \sprintf('Unable to start OpenType Sanitizer command "%s".', implode(' ', $command)),
        );

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(
            0,
            $exitCode,
            \sprintf(
                "OpenType Sanitizer is unavailable through \"%s\". Install opentype-sanitizer==9.2.0, or set OTS_PYTHON or OTS_SANITIZER.\n%s%s",
                implode(' ', $command),
                \is_string($output) ? $output : '',
                \is_string($error) ? $error : '',
            ),
        );
    }

    /**
     * @param list<string> $command
     */
    private static function assertAcceptedByOts(array $command, string $format, string $source, string $destination): void
    {
        $process = proc_open(
            [...$command, $source, $destination],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Unable to start OpenType Sanitizer.');

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

    /**
     * @return list<string>
     */
    private static function otsCommand(): array
    {
        $sanitizer = getenv('OTS_SANITIZER');

        if (\is_string($sanitizer) && '' !== trim($sanitizer)) {
            return [$sanitizer];
        }

        $python = getenv('OTS_PYTHON');
        $python = \is_string($python) && '' !== trim($python) ? $python : 'python3';

        return [
            $python,
            '-c',
            <<<'PYTHON'
import ots
import sys

if '--version' in sys.argv:
    print(ots.__version__)
    raise SystemExit(0)

raise SystemExit(ots.sanitize(sys.argv[1], sys.argv[2]).returncode)
PYTHON,
        ];
    }
}
