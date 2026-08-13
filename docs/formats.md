# Font formats

`Font::fromFile()` detects the container from its contents. The filename
extension is used when reporting `FontMetadata::$format`, but it does not make
an unsupported font readable.

| Format | Support | Requirement or boundary |
| --- | --- | --- |
| TrueType with `glyf` outlines | Supported | Includes compound glyphs |
| OpenType with `glyf` outlines | Supported | CFF and CFF2 outlines are rejected |
| WOFF 1 | Supported | Requires the Zlib extension |
| WOFF2 | Supported | Requires `ext-brotli` or the `brotli` executable |
| TTC and OTC collections | Supported | Select a face with `faceIndex` |
| WOFF2 collections | Not supported | Rejected explicitly |
| Variable `glyf` fonts | Supported | Includes `fvar`, `avar`, `gvar`, and `HVAR` |
| Color glyphs | Not supported | COLR, CPAL, SVG, sbix, CBDT, and CBLC are not rendered |

## Font collections

Select a zero-based face when loading a TrueType or OpenType collection:

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);

echo $font->face()->faceIndex;
echo $font->face()->faceCount;
```

An index outside the collection raises `InvalidFontException`.

## WOFF2 decompression

WOFF2 uses Brotli compression. Alto Font first uses the PHP Brotli extension
when it is available, then falls back to the `brotli` command-line program.
If neither is available, loading a WOFF2 file fails rather than silently
returning incomplete data.

WOFF2 support includes transformed `glyf`, `loca`, and `hmtx` tables for
single-font files.

## Failure types

Catch the shared interface when the recovery action is the same for every
font-loading problem:

```php
use Alto\Font\Exception\FontExceptionInterface;
use Alto\Font\Font;

try {
    $font = Font::fromFile($path);
} catch (FontExceptionInterface $error) {
    // Reject the file or try another candidate.
}
```

Use `UnsupportedFontException` when you need to distinguish a valid but
unsupported feature from an `InvalidFontException` caused by malformed data
or an invalid selection.
