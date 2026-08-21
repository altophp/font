# Compress fonts

Compression reduces the container representation of a font. It does not remove
characters, glyphs, hinting, or layout data. Use [subsetting](subsetting.md)
when the font itself should contain less data.

Compression happens while converting a face to a webfont container:

| Output | Behavior |
| --- | --- |
| SFNT | Tables are stored without container compression |
| WOFF | Each table uses Zlib level 6 only when the result is smaller |
| WOFF2 | Transformed table data is compressed as one Brotli stream |

```php
use Alto\Font\Writer\WoffWriter;

new WoffWriter()->write(
    $font,
    __DIR__.'/output/font.woff',
);
```

WOFF compression is automatic and uses the required Zlib extension. WOFF2
writing instead requires an explicit Brotli adapter so the application chooses
between the PHP extension, a process, or its own implementation.

Read [WOFF2](compression/woff2.md) for adapters, profiles, temporary resources,
and memory behavior. Read [Conversion](conversion.md) for writer and container
selection.
