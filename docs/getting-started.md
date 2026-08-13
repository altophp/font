# Getting started

Load a font once, then use the returned `Font` object for metadata and glyph
queries.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$face = $font->face();
$descriptor = $font->getDescriptor();

printf(
    "%s %s, %d units per em\n",
    $descriptor->family,
    $descriptor->subfamily,
    $face->unitsPerEm,
);
```

## Inspect a character

`getMetrics()` is the shortest route when you have exactly one character:

```php
$metrics = $font->getMetrics('A');

echo $metrics->advanceWidth;
echo $metrics->leftSideBearing;
```

Metrics use the font's design units. Divide by `unitsPerEm` and multiply by
your target font size when converting them to another coordinate system.

To inspect the outline, resolve the Unicode code point first:

```php
$glyphId = $font->glyphIdForCodepoint(ord('A'));

if (null === $glyphId) {
    throw new RuntimeException('The font does not contain A.');
}

$outline = $font->glyphOutline($glyphId);

foreach ($outline->contours as $contour) {
    foreach ($contour->commands as $command) {
        printf("%s %s\n", $command->type, implode(' ', $command->coordinates));
    }
}
```

The outline contains generic move, line, quadratic-curve, and close commands.
It is geometry, not an SVG or another rendered format.

Continue with [Metadata](metadata.md), [Glyphs](glyphs.md), or
[Discovery](discovery.md), depending on the job your application performs.
