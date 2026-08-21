# Use font writers

Writers keep the selected face and change its output container. They do not
reduce the character or glyph set. Use [Create a font subset](../subsetting.md)
first when the output should contain fewer glyphs.

## Write SFNT, WOFF, and WOFF2

```php
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

new SfntWriter()->write($font, __DIR__.'/output/inter.ttf');
new WoffWriter()->write($font, __DIR__.'/output/inter.woff');
new Woff2Writer(new BrotliExtensionCompressor())->write(
    $font,
    __DIR__.'/output/inter.woff2',
);
```

The output directory must exist. Writers create a new file exclusively and
never replace an existing path. A partial file is removed if writing fails.

## Return bytes instead of writing a file

Every writer provides `dump()`:

```php
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;

$sfntBytes = new SfntWriter()->dump($font);
$woffBytes = new WoffWriter()->dump($font);
$woff2Bytes = new Woff2Writer(
    new BrotliExtensionCompressor(),
)->dump($font);
```

`dump()` materializes the complete output as one string. Use it for storage
APIs that accept bytes. Use `write()` for an exclusive filesystem destination.

`Font::toSfnt()` is the direct convenience API for in-memory SFNT output.

## Extract a collection face

```php
$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);

new SfntWriter()->write($font, __DIR__.'/output/selected-face.ttf');
```

A selected TTC or OTC face is always written as a standalone font.

## Understand reconstruction

| Source and target | Result |
| --- | --- |
| Unchanged standalone SFNT to SFNT | Preserved byte-for-byte |
| Collection face to SFNT | Standalone face with rebuilt directory and checksums |
| WOFF or WOFF2 to SFNT | Reconstructed standalone SFNT |
| Any supported face to WOFF | Each table uses Zlib level 6 only when compression makes it smaller |
| Any supported face to WOFF2 | Tables transformed when supported, then Brotli-compressed |

WOFF and WOFF2 container metadata and private-data blocks are not preserved
when a webfont is decoded and written again. WOFF and WOFF2 output remove
`DSIG`. WOFF2 output also updates required `head.flags` and may reconstruct
`glyf`, `loca`, and `hmtx` canonically. Rebuilt output is structurally
equivalent, not necessarily byte-for-byte identical.

## Handle failures

| Exception | Meaning |
| --- | --- |
| `FontWriteException` | The destination is empty, exists already, or cannot be written |
| `CompressionException` | Zlib or Brotli compression, support, or temporary streams failed |
| `UnsupportedFontException` | The requested view or font feature cannot be written |
| `InvalidFontException` | Required source tables are malformed or inconsistent |

These exceptions implement `FontExceptionInterface`. A selected variable view
created with `withVariations()` cannot be written as a static font.

See [WOFF2 compression](../compression/woff2.md) for Brotli configuration and
memory behavior.
