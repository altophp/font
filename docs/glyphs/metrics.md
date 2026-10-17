# Glyph metrics

Measure a glyph when you place text yourself, size a label, or compare faces:
`glyphMetrics()` reports the advance width and the left side bearing in the
font's design units, before shaping, kerning, or rasterization. Divide a value
by `face()->unitsPerEm` and multiply by the target size to scale it.

## Read metrics for a character

Place a font at `fonts/Inter-Regular.ttf` and run this script beside `vendor`.
Passing one character resolves it and reports a clear failure if it is absent:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$metrics = $font->glyphMetrics('A');

printf("Glyph: %d\n", $metrics->glyphId->value);
printf("Advance: %d\n", $metrics->advanceWidth);
printf("Side bearing: %d\n", $metrics->leftSideBearing);
```

Passing invalid UTF-8, an empty string, or more than one Unicode codepoint
raises `InvalidTextException`. A valid character absent from the font raises
`GlyphNotFoundException`. Use [`glyphIdForCodepoint()`](../glyphs.md#resolve-a-character)
first when absence is expected.

## Read metrics by identifier

An identifier obtained from a previous call or from `glyphIdForCodepoint()`
reads the same measurements without resolving a character again:

```php
$glyphId = $metrics->glyphId;
$metrics = $font->glyphMetrics($glyphId);
```

The advance width and side bearing use the font's design units, not pixels.

![Inter A annotated with its baseline, glyph origin, next origin, 1413-unit advance and 52-unit left side bearing.](../assets/figures/glyph-metrics.svg)

In this Inter fixture, `A` advances by 1,413 units and has a 52-unit left side
bearing. Advance width is the distance to the next origin before shaping;
it is not the width of the visible ink. The measurements use the bundled Inter fixture.

## Measure at another weight

For a variable font, select coordinates first; the view reports the metrics
at that position in the design space:

```php
$bold = $font->withVariations(['wght' => 700]);

printf("Default A advance: %g\n", $font->glyphMetrics('A')->advanceWidth);
printf("Selected A advance: %g\n", $bold->glyphMetrics('A')->advanceWidth);
```

Both values may be equal when that glyph's advance does not vary. See
[Variable fonts](../tables/variations.md) for axes, instances, and limits.

## Contract

`GlyphMetrics` exposes `glyphId: GlyphId`, `advanceWidth: int`, and
`leftSideBearing: int`. The values describe an individual glyph; the
distance to the next glyph in real text also depends on kerning and shaping,
which the package does not perform.
