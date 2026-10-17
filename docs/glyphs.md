# Glyphs

Use this section to check whether a font can display a character, to measure
a glyph before placing text yourself, or to read its shape in order to draw
it. A font maps Unicode codepoints to glyph identifiers through its `cmap`
table; a glyph is the font-specific shape and set of measurements, and its
identifier is valid only within that font, and only until a compact subset
renumbers the glyphs.

- [Glyph metrics](glyphs/metrics.md) reads the advance width and side bearing, at the default or a selected weight.
- [Outlines](glyphs/outlines.md) reads and transforms the contour geometry.
- [Glyph names](glyphs/names.md) derives Unicode text from Adobe and Unicode-style glyph names.
- [Glyph counts](glyphs/counts.md) explains slots, retained glyphs, and the counts a subset reports.

## Resolve a character

Place a font at `fonts/Inter-Regular.ttf` and run this script beside `vendor`.
The remaining examples continue with the same `$font`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$glyphId = $font->glyphIdForCodepoint(0x00E9); // é

echo null === $glyphId ? "é is missing\n" : "é is available\n";
```

`glyphIdForCodepoint()` returns `null` when the font's character map has no
entry. It does not perform font fallback.

When working with one character, `glyphMetrics()` resolves it and reports a
clear failure if it is absent:

```php
$metrics = $font->glyphMetrics('A');

printf("Glyph: %d\n", $metrics->glyphId->value);
printf("Advance: %d\n", $metrics->advanceWidth);
printf("Side bearing: %d\n", $metrics->leftSideBearing);
```

Passing invalid UTF-8, an empty string, or more than one Unicode code point
raises `InvalidTextException`. A valid character absent from the font raises
`GlyphNotFoundException`. Use the explicit code-point method when absence is
expected.

## Contracts

- `glyphIdForCodepoint(int $codepoint): ?GlyphId` finds the face-specific identifier for one Unicode codepoint and returns `null` when the character map has no entry.
- `glyphMetrics(GlyphId|string $glyph): GlyphMetrics` reads horizontal metrics from an identifier or one Unicode character. See [Glyph metrics](glyphs/metrics.md).
- `glyphOutline(GlyphId $glyphId): GlyphOutline` reads neutral contour geometry using an identifier from this face. See [Outlines](glyphs/outlines.md).

`GlyphId::$value` is a non-negative integer. A glyph identifier is specific to
its font; do not reuse it with another face or after compact subsetting.
`glyphMetrics()` throws `InvalidTextException` for invalid UTF-8, empty text,
or more than one codepoint, and `GlyphNotFoundException` for a missing
character. Identifier-based reads can throw `InvalidFontException` for an
out-of-range identifier or malformed glyph data, and
`UnsupportedFontException` for unsupported outline structures.
