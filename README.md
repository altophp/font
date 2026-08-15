# ALTO Font

Font reading, metadata, writing, and Unicode subsetting for PHP: parse
OpenType/TrueType/WOFF/WOFF2, discover installed fonts, and expose per-glyph
facts.

&nbsp; ![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-00B7FF?logoColor=00B7FF&labelColor=050608)
&nbsp; ![CI](https://img.shields.io/github/actions/workflow/status/altophp/font/CI.yml?branch=main&label=Tests&labelColor=050608&color=00B7FF)
&nbsp; [![Packagist](https://img.shields.io/packagist/v/alto/font?label=Packagist&labelColor=050608&color=00B7FF)](https://packagist.org/packages/alto/font)
&nbsp; ![License](https://img.shields.io/github/license/altophp/font?label=License&labelColor=050608&color=00B7FF)
&nbsp; [![GitHub Sponsors](https://img.shields.io/github/sponsors/smnandre?logo=githubsponsors&logoColor=00B7FF&label=%20Sponsor&labelColor=050608&color=00B7FF)](https://github.com/sponsors/smnandre)

`alto/font` answers "what is this font, and what are the facts about this
glyph" -- metadata, discovery, per-glyph metrics and outlines. It does not
shape text, does not apply kerning, and does not draw anything. Turning a
string of text into positioned, drawn glyphs is a higher-level package's job;
this one only reads and reports facts.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter.ttf');

echo $font->metadata()->family;
echo $font->face()->unitsPerEm;
echo $font->getMetrics('A')->advanceWidth;
```

The package has no PHP package runtime dependencies. Unsupported containers and font features fail
with typed exceptions instead of returning partial data.

## Installation

Install ALTO Font with Composer:

```bash
composer require alto/font
```

ALTO Font requires PHP 8.4 or later with Iconv and Zlib. Both extensions are included in most PHP
distributions. WOFF2 additionally requires the Brotli PHP extension or the `brotli` executable.

## Quick Start

Load a font and inspect its face, descriptor, and one glyph:

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$descriptor = $font->getDescriptor();

printf(
    "%s %s, %d units per em\n",
    $descriptor->family,
    $descriptor->subfamily,
    $font->face()->unitsPerEm,
);

$metrics = $font->getMetrics('A');
$outline = $font->glyphOutline($metrics->glyphId);
```

Metrics and outlines use the font's design units. Read [Getting started](docs/getting-started.md)
for scaling and outline inspection.

## Format Support

| Format | Support |
| --- | --- |
| TrueType and OpenType with `glyf` outlines | Supported |
| WOFF 1 | Supported |
| WOFF2 | Supported, including transformed `glyf`, `loca`, and `hmtx` |
| TTC and OTC collections | Supported with `faceIndex` |
| Variable `glyf` fonts | Supported through `fvar`, `avar`, `gvar`, and `HVAR` |
| CFF/CFF2 outlines, WOFF2 collections, and color glyph rendering | Not supported |

Read [Font formats](docs/formats.md) for requirements, boundaries, and failure types.

## Discovery

Find the closest face for a family, weight, style, and stretch query:

```php
use Alto\Font\FontFinder;
use Alto\Font\FontQuery;

$finder = FontFinder::fromDirectories(__DIR__.'/fonts');
$font = $finder->get(FontQuery::family('Inter')->weight(700)->italic());
```

ALTO Font can search application directories, system fonts, or a custom locator. Read
[Font discovery](docs/discovery.md) for matching and absence policies.

## Metadata and Glyphs

`FontFace` exposes structural metrics and table records. `FontDescriptor` provides names and
CSS-like matching values, while `FontMetadata` includes optional publisher and licensing fields.

Character lookup returns a font-specific glyph identifier. From it, retrieve metrics or neutral
contour geometry made of move, line, quadratic-curve, and close commands.

See [Font metadata](docs/metadata.md) and [Glyphs](docs/glyphs.md).

## Variable Fonts

Inspect axes and select immutable coordinates:

```php
$boldCondensed = $font->withVariations([
    'wght' => 700,
    'wdth' => 85,
]);
```

Selected coordinates affect supported glyph metrics and outlines. Read
[Variable fonts](docs/variations.md) for axes, named instances, clamping, and observable results.
The [complete guide](docs/index.md) links every topic.

## Contributing

Contributions of all kinds are welcome. Visit the
[project on GitHub](https://github.com/altophp/font) to
[report a bug](https://github.com/altophp/font/issues/new),
[suggest a feature](https://github.com/altophp/font/issues/new), or
[open a pull request](https://github.com/altophp/font/pulls).

Before submitting code, run:

```bash
# Runs PHP CS Fixer, PHPStan, and PHPUnit
composer qa
```

Changes to public behavior should include tests and documentation.

## Support

ALTO Font is open source. You can support its continued development through
[GitHub Sponsors](https://github.com/sponsors/smnandre).

Sharing this package with others or
[starring it on GitHub](https://github.com/altophp/font) is also much
appreciated.

## License

ALTO Font is released by [ALTO PHP](https://altophp.com) under the
[MIT License](LICENSE).
