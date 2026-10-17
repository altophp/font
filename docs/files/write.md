# Writing files

Write a file when a loaded face, or the result of a subset, has to leave PHP:
a TTF for an application bundle, a WOFF or WOFF2 for a web server, or the
bytes for a storage API. A writer changes the container and keeps
every character and glyph; [Subsetting](../subset.md) is the step that
removes them, before writing.

## Write TTF, WOFF, and WOFF2

Place a font at `fonts/Inter-Regular.ttf` beside `vendor` and create an
`output` directory. The WOFF2 line needs the PHP Brotli extension; remove it
if the extension is not installed.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\SfntWriter;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

new SfntWriter()->write($font, __DIR__.'/output/inter.ttf');
new WoffWriter()->write($font, __DIR__.'/output/inter.woff');
new Woff2Writer(new BrotliExtensionCompressor())->write($font, __DIR__.'/output/inter.woff2');
```

The script creates three files and prints nothing. Each writer creates its
file exclusively: the parent directory must exist, the destination must be a
new path, and a partial file is removed if writing fails. Choose a new
filename for each run, or delete the previous output in your application.
`Woff2Writer` always takes a compressor; [Compression](../compress.md) covers
the two adapters and the quality profiles.

## Return bytes instead of a file

Every writer also has `dump()`, which returns the complete output as one
string, for a storage API or an HTTP response:

```php
$woffBytes = new WoffWriter()->dump($font);
$sfntBytes = $font->toSfnt();
```

`Font::toSfnt()` is the shortcut for TTF bytes. Both materialize the whole
output in memory; the process-backed Brotli adapter can stream WOFF2 to a file
instead, see [Adapters](../compress/adapters.md).

## Extract one face from a collection

A face selected from a TTC or OTC is written as a standalone font:

```php
$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);

new SfntWriter()->write($font, __DIR__.'/output/selected-face.ttf');
```

## What the output keeps

An unchanged standalone TTF is written byte for byte. Everything else gets a
rebuilt table directory and checksums:

| Source and target | Result |
| --- | --- |
| Collection face to TTF | Standalone face, rebuilt directory and checksums |
| WOFF or WOFF2 to TTF | Reconstructed standalone font |
| Any face to WOFF | Each table Zlib-compressed at level 6 when that makes it smaller |
| Any face to WOFF2 | Tables transformed where the format allows, then one Brotli stream |

Output is structurally equivalent to the source, not necessarily byte
identical: WOFF and WOFF2 output drop `DSIG`, WOFF2 output updates
`head.flags` and may rebuild `glyf`, `loca`, and `hmtx` in canonical form, and
the metadata and private-data blocks of a WOFF or WOFF2 source are not carried
over.

## Write a variable font

A variable font is written with all its axes. A view selected with
`withVariations()` is a reading position rather than a file: call
`withoutVariations()` to write its source, or start from a static font when
you need one weight. See [Variable fonts](../tables/variations.md).

## When writing fails

- `FontWriteException`: the destination is empty, exists already, or its directory is missing or not writable.
- `CompressionException`: Zlib or Brotli failed, the Brotli adapter is unusable in this runtime, or temporary storage is not writable; see [Adapters](../compress/adapters.md#check-the-runtime).
- `InvalidFontException`: a required source table is malformed or inconsistent.

All of them implement `FontExceptionInterface`; [Errors](../errors.md) lists
every exception.
