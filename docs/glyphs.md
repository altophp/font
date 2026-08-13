# Glyphs

Font files map Unicode code points to glyph identifiers. Glyph identifiers are
font-specific and must not be reused with another font.

## Resolve a character

```php
$glyphId = $font->glyphIdForCodepoint(0x00E9); // é

if (null === $glyphId) {
    // This font has no glyph for the character.
}
```

`glyphIdForCodepoint()` returns `null` when the font's character map has no
entry. It does not perform font fallback.

When working with one character, `getMetrics()` resolves it and reports a
clear failure if it is absent:

```php
$metrics = $font->getMetrics('A');

echo $metrics->glyphId->value;
echo $metrics->advanceWidth;
echo $metrics->leftSideBearing;
```

Passing an empty string, more than one Unicode code point, or a missing
character raises `InvalidFontException`. Use the explicit code-point method
when absence is expected.

## Read metrics by identifier

```php
use Alto\Font\Glyph\GlyphId;

$glyphId = new GlyphId(42);
$metrics = $font->glyphMetrics($glyphId);
```

The advance width and side bearing use the font's design units, not pixels.

## Read an outline

```php
$outline = $font->glyphOutline($glyphId);

if ($outline->isEmpty()) {
    // Spaces and other non-drawing glyphs can have no contours.
}

foreach ($outline->contours as $contour) {
    foreach ($contour->commands as $command) {
        // M, L, Q, or Z with their design-unit coordinates.
    }
}
```

`M`, `L`, `Q`, and `Z` represent move, line, quadratic curve, and close-path
commands. Alto Font exposes this neutral geometry without serializing it to
SVG, a bitmap, or another drawing format.

## Transform geometry

Outlines, contours, and path commands accept a two-dimensional affine
transform:

```php
$scale = 16 / $font->face()->unitsPerEm;

$scaled = $outline->transform(
    xx: $scale,
    yx: 0,
    xy: 0,
    yy: -$scale,
    dx: 0,
    dy: 16,
);
```

The example scales the outline to 16 units, flips the font's upward Y axis,
and moves the baseline. It still does not draw the result.
