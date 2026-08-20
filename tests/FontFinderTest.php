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

namespace Alto\Font\Tests;

use Alto\Font\Descriptor\FontStretch;
use Alto\Font\Descriptor\FontStyle;
use Alto\Font\Exception\FontNotFoundException;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\FontFinder;
use Alto\Font\FontQuery;
use Alto\Font\Locator\FontLocatorInterface;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontFinder::class)]
final class FontFinderTest extends TestCase
{
    public function testItFindsFontsFromALocator(): void
    {
        $path = self::tinyFontPath('finder-locator');
        $finder = FontFinder::fromLocator(self::locator($path));

        self::assertTrue($finder->has('Atelier Tiny'));
        self::assertTrue($finder->has(FontQuery::family('Atelier Tiny')->weight(400)));
        self::assertFalse($finder->has('Missing'));
        self::assertInstanceOf(Font::class, $finder->find('Atelier Tiny'));
        self::assertSame('Atelier Tiny', $finder->get('Atelier Tiny')->descriptor()->family);
    }

    public function testItThrowsWhenRequiredFontIsMissing(): void
    {
        $finder = FontFinder::fromLocator(self::locator(self::tinyFontPath('finder-missing')));

        $this->expectException(FontNotFoundException::class);
        $this->expectExceptionMessage('Font "Missing" was not found.');

        $finder->get('Missing');
    }

    public function testItCachesLocatorResults(): void
    {
        $locator = new class (self::tinyFontPath('finder-cache')) implements FontLocatorInterface {
            public int $calls = 0;

            public function __construct(private readonly string $path) {}

            public function fonts(): iterable
            {
                ++$this->calls;

                yield $this->path;
            }
        };
        $finder = FontFinder::fromLocator($locator);

        self::assertTrue($finder->has('Atelier Tiny'));
        self::assertTrue($finder->has('Atelier Tiny'));
        self::assertSame(1, $locator->calls);
    }

    public function testItSkipsInvalidCandidates(): void
    {
        $invalidPath = '/path/that/does/not/exist.ttf';
        $finder = FontFinder::fromLocator(self::locator(
            $invalidPath,
            self::tinyFontPath('finder-invalid-candidate'),
        ));

        self::assertTrue($finder->has('Atelier Tiny'));
        self::assertArrayHasKey($invalidPath, $finder->diagnostics());
        self::assertInstanceOf(InvalidFontException::class, $finder->diagnostics()[$invalidPath]);
    }

    public function testItAcceptsShortcutWeightAndStyleArguments(): void
    {
        $finder = FontFinder::fromLocator(self::locator(self::tinyFontPath('finder-shortcuts')));

        self::assertTrue($finder->has('Atelier Tiny', weight: 500, style: FontStyle::Italic));
    }

    public function testItScoresStaticStretchQueries(): void
    {
        $finder = FontFinder::fromLocator(self::locator(self::tinyFontPath('finder-stretch')));

        self::assertTrue($finder->has(FontQuery::family('Atelier Tiny')->stretch(FontStretch::normal())));
        self::assertTrue($finder->has(FontQuery::family('Atelier Tiny')->stretch(new FontStretch(75))));
    }

    public function testItMapsQueriesToVariableFontCoordinates(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-finder-variable-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($path);
        $font = FontFinder::fromLocator(self::locator($path))->get(
            FontQuery::family('Atelier Tiny')
                ->weight(800)
                ->stretch(new FontStretch(75)),
        );

        self::assertSame(['wght' => 800.0, 'wdth' => 75.0], $font->variationCoordinates()?->values);
        self::assertSame(560, $font->metrics('A')->advanceWidth);
        self::assertSame(4, $font->metrics('A')->leftSideBearing);
    }

    public function testItPrefersVariableCandidatesOverApproximateStaticCandidates(): void
    {
        $staticPath = self::tinyFontPath('finder-static-regular');
        $variablePath = sys_get_temp_dir() . '/atelier-font-finder-variable-capable-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($variablePath);
        $font = FontFinder::fromLocator(self::locator($staticPath, $variablePath))->get(
            FontQuery::family('Atelier Tiny')->weight(800),
        );

        self::assertSame(['wght' => 800.0, 'wdth' => 100.0], $font->variationCoordinates()?->values);
        self::assertSame(680, $font->metrics('A')->advanceWidth);
    }

    public function testItCreatesDirectoryAndSystemFinders(): void
    {
        $directory = self::temporaryDirectory('finder-directory');
        TinyTrueTypeFont::write($directory . '/tiny.ttf');

        self::assertTrue(FontFinder::fromDirectories($directory)->has('Atelier Tiny'));
        self::assertInstanceOf(FontFinder::class, FontFinder::system());
    }

    private static function locator(string ...$paths): FontLocatorInterface
    {
        return new class (array_values($paths)) implements FontLocatorInterface {
            /**
             * @param list<string> $paths
             */
            public function __construct(private readonly array $paths) {}

            public function fonts(): iterable
            {
                yield from $this->paths;
            }
        };
    }

    private static function tinyFontPath(string $name): string
    {
        $path = sys_get_temp_dir() . '/atelier-font-' . $name . '-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::write($path);

        return $path;
    }

    private static function temporaryDirectory(string $name): string
    {
        $directory = sys_get_temp_dir() . '/atelier-font-' . $name . '-' . bin2hex(random_bytes(4));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(\sprintf('Could not create "%s".', $directory));
        }

        return $directory;
    }
}
