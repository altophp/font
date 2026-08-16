# Create a font subset

A subset keeps the characters required by an application and the glyphs needed
to display them. Start with the conservative defaults before enabling compact
glyph IDs or removing layout data.

## Create a subset from text

```php
use Alto\Font\Font;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$characters = UnicodeSet::fromText('Alto Font 0123456789');
$result = $font->subset(new SubsetOptions($characters));
```

The default options preserve glyph IDs, hinting, and OpenType layout tables.
Unused glyph slots remain present but empty. This is the safest starting point
for an unfamiliar font.

## Inspect the result

`subset()` returns a `SubsetResult` rather than only the transformed font:

```php
printf(
    "%d codepoints mapped to %d retained glyphs\n",
    $result->mappedCodepointCount,
    $result->retainedGlyphCount,
);

foreach ($result->warnings as $warning) {
    fwrite(STDERR, $warning."\n");
}
```

| Property | Meaning |
| --- | --- |
| `font` | The immutable subset font passed to a writer |
| `requestedUnicodes` | The normalized set requested by the caller |
| `mappedCodepointCount` | Requested codepoints found in the source cmap |
| `originalGlyphCount` | Glyph count before subsetting |
| `retainedGlyphCount` | `.notdef`, mapped glyphs, compound components, and layout dependencies retained |
| `sfntSize` | Materialized SFNT size, not the final WOFF or WOFF2 size |
| `warnings` | Explicit transformations or losses that the caller should record |

With preserved glyph IDs, `font->face()->glyphCount` still includes empty
slots. It can therefore be greater than `retainedGlyphCount`.

## Write the subset

The result is an ordinary `Font` and can be passed to any writer:

```php
use Alto\Font\Writer\WoffWriter;

new WoffWriter()->write(
    $result->font,
    __DIR__.'/output/inter-subset.woff',
);
```

Read [Convert fonts](converting.md) for output behavior and
[Compress WOFF2](woff2-compression.md) when the target is WOFF2.

## Choose a different character source

Use `UnicodeSet::fromText()` for application strings and translation corpora.
Use CSS ranges, explicit codepoints, or composed ranges when the selection is
defined independently from text. See [Build Unicode sets](unicode-sets.md).

## Optimize further

Compact glyph IDs, hint removal, and layout removal can reduce output size but
change more font data. See [Choose subset policies](subsetting-policies.md)
before enabling them.

Subsetting currently supports TrueType `glyf` outlines. Unsupported CFF,
color, bitmap, variation, layout, or glyph-indexed structures fail explicitly
instead of producing a partially valid font.
