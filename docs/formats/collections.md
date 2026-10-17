# Collections

A TrueType or OpenType collection (`.ttc`, `.otc`) stores several faces in one
file and lets them share tables, typically the glyph data of a CJK family.
Its signature is `ttcf`, and the package reports it as
`FontFormat::TrueTypeCollection`.

## Reading

Select a zero-based face when loading:

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);

echo $font->face()->faceIndex;
echo $font->face()->faceCount;
```

A collection that declares no faces, or an index outside `0` to
`faceCount - 1`, raises `InvalidFontException`. A collection nested inside a
collection raises `UnsupportedFontException`, and so does a selected face with
CFF outlines. Standalone files accept only index zero.

[Finding](../files/find.md) searches the first face of each collection;
load another face explicitly with `faceIndex`.

## Writing

A selected face is always written as a standalone font with a rebuilt table
directory and checksums; the package does not write collections:

```php
use Alto\Font\Font;
use Alto\Font\Writer\SfntWriter;

$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);

new SfntWriter()->write($font, __DIR__.'/output/selected-face.ttf');
```

## Subsetting

Subsetting applies to the selected face only, and the result is a standalone
font; see [Subsetting](../subset.md).

## Requirements and limits

- A TTC or OTC with `glyf` faces: read one face, write it standalone, subset it.
- A WOFF2 collection is rejected explicitly; use the TTC or OTC source.
- Writing a collection is not available.
