# WOFF

Write WOFF when the font goes to a browser and Brotli is not available in
the runtime that produces the files. It is the W3C container that wraps an
sfnt font for `@font-face`, with each table optionally Zlib-compressed and an
optional metadata block. Its signature is `wOFF`, and the package reports it
as `FontFormat::Woff`.

## Reading

Loading a WOFF file decompresses its tables with the required Zlib extension
and exposes the same face as the original sfnt:

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.woff');

echo $font->metadata()->format->value; // woff
```

## Writing

`WoffWriter` needs no configuration. Each table is compressed with Zlib
level 6 and stored compressed only when that makes it smaller:

```php
use Alto\Font\Font;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

new WoffWriter()->write($font, __DIR__.'/output/inter.woff');
```

The extended metadata and private data blocks of a source WOFF are not
carried over when the font is written again, and `DSIG` is removed. For the
bundled Inter sample, the complete face measures 31,792 bytes as WOFF against
66,912 bytes as TTF. WOFF2 compresses the same face to 23,300 bytes; see
[Compression](../compress.md).

## Subsetting

Subset first, then write the result with `WoffWriter`; see
[Subsetting](../subset.md).

## Requirements and limits

- Reading and writing need the Zlib extension, which the package requires.
- Outlines must be TrueType `glyf`.
- Metadata and private blocks are read but not preserved on output.
