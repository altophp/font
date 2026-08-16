# Alto Font

Alto Font loads font files, exposes their data, converts supported formats,
compresses webfont output, and creates Unicode subsets. It does not shape text,
apply kerning, or draw glyphs.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.woff2');

$family = $font->metadata()->family;
$advanceWidth = $font->getMetrics('A')->advanceWidth;
```

## Introduction

- [Installation](installation.md): install the package and check its runtime requirements.
- [Getting started](getting-started.md): load a font and inspect one glyph.

## Fonts

- [Font basics](fonts.md): distinguish families, faces, files, containers, outlines, characters, and glyphs.
- [Formats](formats.md): understand containers, outlines, collections, and runtime requirements.
- [Font files](font-files.md): load a known file or find a matching font.
- [Discovery](discovery.md): find the best matching font in directories or the operating system.
- [Font data](font-data.md): choose the API that answers a structure or content question.
- [Metadata](metadata.md): inspect names, descriptors, dimensions, and licensing fields.
- [Glyphs](glyphs.md): resolve characters to glyphs and read metrics and outlines.
- [Variations](variations.md): inspect axes and select a variable-font view.

## Conversion

- [Convert fonts](conversion.md): choose an output container and understand what conversion changes.
- [Writers](conversion/writers.md): write standalone SFNT, WOFF, and WOFF2 files.

## Compression

- [Compress fonts](compression.md): understand SFNT, WOFF, and WOFF2 compression behavior.
- [WOFF2](compression/woff2.md): choose a Brotli adapter and compression profile.

## Subsetting

- [Create subset](subsetting.md): keep the characters needed by an application.
- [Unicode sets](subsetting/unicode-sets.md): select text, codepoints, ranges, and CSS unicode ranges.
- [Policies](subsetting/policies.md): control glyph IDs, hinting, layout, and variable data.

Alto Font reports and transforms font data. Text layout, fallback,
bidirectional text, shaping, and rendering belong to higher-level packages.
