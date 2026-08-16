# Inspect a font

Inspection reads facts from a font without creating a new file. Load one face,
then choose the narrowest API for the question being answered.

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

## Choose an inspection API

| Question | API |
| --- | --- |
| What container and face was loaded? | `face()` and `metadata()->format` |
| What are the family, version, manufacturer, designer, vendor URL, or license fields? | `metadata()` |
| What weight, style, and stretch should discovery use? | `getDescriptor()` |
| Does the face contain one Unicode codepoint? | `glyphIdForCodepoint()` |
| What are one glyph's advance and side bearing? | `glyphMetrics()` or `getMetrics()` |
| What is the glyph's neutral contour geometry? | `glyphOutline()` |
| Which variation axes and instances exist? | `variations()` |

## Inspect a collection

The default `faceIndex` is zero. Read `faceCount`, then load another face
explicitly when needed:

```php
$first = Font::fromFile(__DIR__.'/fonts/Collection.ttc');

for ($index = 0; $index < $first->face()->faceCount; ++$index) {
    $face = Font::fromFile(
        __DIR__.'/fonts/Collection.ttc',
        faceIndex: $index,
    );

    echo $face->metadata()->family."\n";
}
```

## Continue by topic

- [Font metadata](metadata.md) covers identity, dimensions, descriptors, and licensing fields.
- [Glyphs](glyphs.md) covers coverage, metrics, and outlines.
- [Variable fonts](variations.md) covers axes, instances, and immutable selected views.
- [Font discovery](discovery.md) covers matching application and system fonts.

Inspection does not subset, convert, compress, shape, or render text. Those are
separate workflows.
