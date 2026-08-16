# Alto Font

Alto Font reads and writes font files, exposes their metadata, glyph metrics,
outlines, and variable-font axes, and creates conservative Unicode subsets. It
does not shape text, apply kerning, or draw glyphs.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.woff2');

$family = $font->metadata()->family;
$advanceWidth = $font->getMetrics('A')->advanceWidth;
```

## Introduction

- [Installation](installation.md): install the package and check its runtime requirements.
- [Getting started](getting-started.md): load a font and inspect one glyph.

## Inspect fonts

- [Inspect a font](inspection.md): choose the API that answers a metadata or structure question.
- [Formats](formats.md): understand containers, outlines, collections, and runtime requirements.
- [Discovery](discovery.md): find the best matching font in directories or the operating system.
- [Metadata](metadata.md): inspect names, descriptors, dimensions, and licensing fields.
- [Glyphs](glyphs.md): resolve characters to glyphs and read metrics and outlines.
- [Variations](variations.md): inspect axes and select a variable-font view.

## Subset fonts

- [Create a subset](subsetting.md): keep the characters needed by an application.
- [Unicode sets](unicode-sets.md): select text, codepoints, ranges, and CSS unicode ranges.
- [Subset policies](subsetting-policies.md): control glyph IDs, hinting, layout, and variable data.

## Convert fonts

- [Convert fonts](converting.md): write standalone SFNT, WOFF, and WOFF2 files.

## Compress fonts

- [Compress WOFF2](woff2-compression.md): choose a Brotli adapter and compression profile.

Alto Font reports and transforms font data. Text layout, fallback,
bidirectional text, shaping, and rendering belong to higher-level packages.
