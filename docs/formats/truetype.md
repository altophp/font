# TrueType

A `.ttf` file is the case the package handles completely: read, write,
subset. Its glyph shapes are quadratic `glyf` outlines, and its signature is
`0x00010000`, or `true` for fonts produced by Apple tools; both load and are
reported as `FontFormat::TrueType`, whatever the file extension. Every other
container is read into the same table structure and written back from it.

## Reading

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

printf("%s, %d glyphs\n", $font->metadata()->format->value, $font->face()->glyphCount);
```

Compound glyphs, which reference other glyphs, are supported. TrueType
instructions and their tables (`cvt `, `fpgm`, `prep`, `gasp`) are read and
preserved; see [Hinting](../tables/hinting.md). A TrueType file whose
`fvar` table declares axes is a variable font; see
[Variable fonts](../tables/variations.md).

## Writing

`SfntWriter` writes any loaded face as a standalone TrueType file. An
unchanged standalone source is written byte for byte; a collection face, a
decoded WOFF or WOFF2, or a subset gets a rebuilt table directory and
checksums. See [Writing](../files/write.md).

## Subsetting

Subsetting rewrites `glyf`, `loca`, `cmap`, the metrics tables, and the
supported layout and variation tables. Which tables survive, and under which
policy, is listed in [Tables](../tables.md).

## Requirements and limits

- Reading and writing need PHP 8.4 with the Iconv and Zlib extensions, nothing else.
- Outlines must be `glyf`; a file with the `OTTO` signature is [OpenType with CFF outlines](opentype.md).
- Bitmap and color glyph data in `EBDT`, `CBDT`, `sbix`, `COLR`, and `SVG ` is not rendered.
