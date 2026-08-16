# ALTO Font

Inspect, subset, convert, and compress OpenType, TrueType, WOFF, and WOFF2 font
files from PHP.

&nbsp; ![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-00B7FF?logoColor=00B7FF&labelColor=050608)
&nbsp; ![CI](https://img.shields.io/github/actions/workflow/status/altophp/font/CI.yml?branch=main&label=Tests&labelColor=050608&color=00B7FF)
&nbsp; [![Packagist](https://img.shields.io/packagist/v/alto/font?label=Packagist&labelColor=050608&color=00B7FF)](https://packagist.org/packages/alto/font)
&nbsp; ![License](https://img.shields.io/github/license/altophp/font?label=License&labelColor=050608&color=00B7FF)
&nbsp; [![GitHub Sponsors](https://img.shields.io/github/sponsors/smnandre?logo=githubsponsors&logoColor=00B7FF&label=%20Sponsor&labelColor=050608&color=00B7FF)](https://github.com/sponsors/smnandre)

ALTO Font answers what a font contains: names, descriptors, licensing metadata,
face dimensions, character maps, glyph metrics, outlines, collections, and
variable-font axes. It also creates conservative Unicode subsets, converts
supported faces between containers, and compresses WOFF2 output. It does not
shape text, apply kerning, or render glyphs.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter.ttf');

echo $font->metadata()->family;
echo $font->face()->unitsPerEm;
echo $font->getMetrics('A')->advanceWidth;
```

Unsupported containers and font features fail with typed exceptions instead
of returning partial data.

## Installation

Install ALTO Font with Composer:

```bash
composer require alto/font
```

ALTO Font requires PHP 8.4 or later with Iconv and Zlib. WOFF2 additionally
requires the Brotli PHP extension or the `brotli` executable. Writing WOFF2
uses an explicit compressor adapter.

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

Metrics and outlines use the font's design units. Read
[Getting started](docs/getting-started.md) for scaling and outline inspection.

## Format Support

| Format | Reading | Writing and subsetting |
| --- | --- | --- |
| TrueType and OpenType with `glyf` outlines | Supported | Supported |
| WOFF 1 | Supported | Supported |
| WOFF2 | Supported, including transformed `glyf`, `loca`, and `hmtx` | Supported with an injected Brotli compressor |
| TTC and OTC collections | Supported with `faceIndex` | Selected faces can be extracted |
| Variable `glyf` fonts | Supported through `fvar`, `avar`, `gvar`, and `HVAR` | Axes can be preserved while subsetting |
| CFF/CFF2 outlines, WOFF2 collections, and color glyphs | Not supported | Not supported |

Read [Font formats](docs/formats.md) for requirements, boundaries, and failure
types.

## Discovery

Find the closest face for a family, weight, style, and stretch query:

```php
use Alto\Font\FontFinder;
use Alto\Font\FontQuery;

$finder = FontFinder::fromDirectories(__DIR__.'/fonts');
$font = $finder->get(FontQuery::family('Inter')->weight(700)->italic());
```

ALTO Font can search application directories, system fonts, or a custom
locator. Read [Font discovery](docs/discovery.md) for matching and absence
policies.

## Inspect Fonts

`FontFace` exposes structural metrics and table tags. `FontDescriptor` provides
names and CSS-like matching values, while `FontMetadata` includes optional
manufacturer, designer, vendor URL, and licensing fields.

Character lookup returns a font-specific glyph identifier. From it, retrieve
metrics or neutral contour geometry made of move, line, quadratic-curve, and
close commands.

Start with [Inspect a font](docs/inspection.md), then continue with
[Font metadata](docs/metadata.md) or [Glyphs](docs/glyphs.md).

## Subset Fonts

Create an immutable subset with conservative defaults:

```php
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;

$subset = $font->subset(new SubsetOptions(
    UnicodeSet::fromText('Alto Font 0123456789'),
));

echo $subset->retainedGlyphCount;
```

Read [Create a subset](docs/subsetting.md) before enabling compact glyph IDs,
hint removal, or layout removal.

## Convert Fonts

Write a complete face or subset as standalone SFNT, WOFF, or WOFF2:

```php
use Alto\Font\Writer\WoffWriter;

new WoffWriter()->write(
    $subset->font,
    __DIR__.'/fonts/alto-subset.woff',
);
```

Writers create new destinations and refuse to replace existing files. Read
[Convert fonts](docs/converting.md) for reconstruction and data-loss boundaries.

## Compress WOFF2

WOFF2 output requires an explicit Brotli adapter:

```php
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Writer\Woff2Writer;

new Woff2Writer(new BrotliExtensionCompressor())->write(
    $subset->font,
    __DIR__.'/fonts/alto-subset.woff2',
);
```

Read [Compress WOFF2](docs/woff2-compression.md) for extension, process, profile,
and memory trade-offs.

## Variable Fonts

Inspect axes and select immutable coordinates:

```php
$boldCondensed = $font->withVariations([
    'wght' => 700,
    'wdth' => 85,
]);
```

Selected coordinates affect supported glyph metrics and outlines. Read
[Variable fonts](docs/variations.md) for axes, named instances, clamping, and
observable results. The [complete guide](docs/index.md) links every topic.

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
