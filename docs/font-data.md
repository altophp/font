# Font data

A loaded `Font` exposes facts about one face without modifying the source file.
Choose the narrowest API for the question being answered.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$face = $font->face();
$metadata = $font->metadata();

printf(
    "%s %s: %d glyphs, %d units per em\n",
    $metadata->family,
    $metadata->subfamily,
    $face->glyphCount,
    $face->unitsPerEm,
);
```

## Choose a data API

| Question | API |
| --- | --- |
| What container and face was loaded? | `face()` and `metadata()->format` |
| What names, version, vendor, or license fields exist? | `metadata()` |
| What weight, style, and stretch describe the face? | `getDescriptor()` |
| Does the face contain one Unicode codepoint? | `glyphIdForCodepoint()` |
| What are one glyph's advance and side bearing? | `glyphMetrics()` or `getMetrics()` |
| What is the glyph's neutral contour geometry? | `glyphOutline()` |
| Which variation axes and instances exist? | `variations()` |

## Continue by data type

- [Metadata](metadata.md) covers identity, dimensions, descriptors, and licensing fields.
- [Glyphs](glyphs.md) covers character lookup, metrics, and outlines.
- [Variations](variations.md) covers axes, instances, and immutable selected views.

Reading font data does not subset, convert, compress, shape, or render text.
Those are separate workflows.
