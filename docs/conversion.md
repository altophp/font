# Convert fonts

Conversion keeps the selected face and writes it in another container. It does
not remove characters or glyphs. Create a [subset](subsetting.md) first when the
output should contain less font data.

## Choose an output

| Output | Use it for | Compression |
| --- | --- | --- |
| SFNT | Standalone TrueType or OpenType output | None |
| WOFF | Broad webfont compatibility | Zlib applied automatically when useful |
| WOFF2 | Smaller webfont distribution | Brotli compressor required |

```php
use Alto\Font\Font;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

new WoffWriter()->write(
    $font,
    __DIR__.'/output/inter.woff',
);
```

Writers create a new destination and refuse to replace an existing file.
Collection faces and decoded webfonts are reconstructed as standalone fonts.

## Continue

- [Writers](conversion/writers.md) covers SFNT, WOFF, and WOFF2 output, `dump()`, reconstruction, and failures.
- [Compression](compression.md) explains when Zlib or Brotli is applied.
- [Subsetting](subsetting.md) reduces the character and glyph set before conversion.
