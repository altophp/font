# Operations

Every operation reads a font and produces a new one; the source file is never
modified. The complete path, from a font on disk to a file your application
serves, is: find or load the font, read what it contains, keep the characters
you need, compress, and write.

- [Find](operations/find.md) selects an installed or bundled font by family, weight, and style.
- [Read](operations/read.md) loads a file or bytes and exposes the face.
- [Subset](operations/subset.md) keeps the characters of your text and reports the result.
- [Compress](operations/compress.md) chooses between WOFF and WOFF2 and configures Brotli.
- [Write](operations/write.md) produces TTF, WOFF, or WOFF2 files or bytes, and extracts a collection face.

## From a TrueType file to a web font

Place a font at `fonts/Inter-Regular.ttf` beside `vendor`, create an `output`
directory, and run this script with PHP. The destination must not exist yet.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$characters = UnicodeSet::fromText('ALTO Font 0123456789');
$result = $font->subset(new SubsetOptions($characters));

$destination = __DIR__.'/output/inter-subset.woff';
new WoffWriter()->write($result->font, $destination);

printf("Saved inter-subset.woff (%d bytes)\n", filesize($destination));
printf("%d of %d requested characters found\n", $result->mappedCodepointCount, count($characters));
```

The script writes a loadable WOFF file at `output/inter-subset.woff` and prints
its size and how many of the requested characters the source contains. The
original font stays unchanged. For WOFF2 output, add a Brotli compressor as
described in [Compress](operations/compress.md).

A browser loads the result through a regular font-face declaration:

```css
@font-face {
    font-family: 'Inter Subset';
    src: url('/fonts/inter-subset.woff') format('woff');
}
```

## What each step changes

Compression reduces the container; subsetting removes glyphs. The defaults
keep hinting and text-layout data, so extra glyphs may remain. For the bundled
Inter Latin sample:

| Output | Writer | Size |
| --- | --- | --- |
| Complete face as TTF | `SfntWriter` | 66,912 bytes |
| Complete face as WOFF | `WoffWriter` | 31,792 bytes |
| Complete face as WOFF2, Brotli maximum | `Woff2Writer` | 23,300 bytes |
| Default subset of `ALTO Font 0123456789` as WOFF | `WoffWriter` | 19,784 bytes |

![One loaded Inter face is written as TTF, WOFF and WOFF2, with the same characters and measured output sizes.](assets/figures/conversion.svg)

These are measurements for one fixture, not promised savings. Compare the
original and the subset in the same output format when measuring subsetting
alone, and check the result in a browser or rendering engine before shipping.

## Boundaries

Writing to an existing destination raises `FontWriteException`; choose a new
path or manage replacement in your application. A view selected with
`withVariations()` cannot be written or subsetted; call `withoutVariations()`
first. Only TrueType outlines are processed: an `.otf` file with CFF outlines
is rejected on load. See [Errors](errors.md) and [Formats](formats.md).
