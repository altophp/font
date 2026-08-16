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

## Fonts

- [Formats](formats.md): understand supported containers, outlines, and optional WOFF2 requirements.
- [Discovery](discovery.md): find the best matching font in directories or the operating system.
- [Metadata](metadata.md): inspect names, descriptors, dimensions, and licensing fields.
- [Glyphs](glyphs.md): resolve characters to glyphs and read metrics and outlines.
- [Variations](variations.md): inspect axes and select a variable-font instance.
- [Writing](writing.md): create standalone SFNT, WOFF, WOFF2, and conservative Unicode subsets.

Alto Font reports and transforms font data. Text layout, fallback,
bidirectional text, shaping, and rendering belong to higher-level packages.
