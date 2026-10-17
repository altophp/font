# WOFF2

WOFF2 is the container to write for browsers: the smallest, and accepted by
every current one. It packages an sfnt font whose tables are transformed and
Brotli-compressed, referenced from CSS through `@font-face`. Its signature is
`wOF2`, and the package reports it as `FontFormat::Woff2`.

## Reading

Loading a WOFF2 file decompresses it with the PHP Brotli extension when it is
loaded, otherwise with the `brotli` executable found on `PATH`. If neither is
available, loading fails with `UnsupportedFontException` rather than returning
incomplete data; a stream that fails to decompress raises `InvalidFontException`.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.woff2');

echo $font->metadata()->format->value; // woff2
```

Transformed `glyf`, `loca`, and `hmtx` tables are supported for single-font
files. WOFF2 collections are rejected explicitly. The reconstructed font keeps
the same functionality as the original sfnt, not necessarily the same bytes.

## Writing

`Woff2Writer` requires a Brotli compressor, even when the extension is
installed, so that the application decides between the PHP extension, a child
process, or its own implementation. [Compression](../compress.md) shows
both adapters, the quality profiles, and the memory behavior.

Output updates the required `head.flags`, may reconstruct `glyf`, `loca`, and
`hmtx` canonically, and removes `DSIG`. Extended metadata and private data
blocks of a source WOFF2 are not carried over when the font is written again.
For the bundled Inter sample, the complete face measures 23,300 bytes at
maximum quality, against 66,912 bytes as TTF.

## Subsetting

Subset first, then write the result with `Woff2Writer`; the container does not
change what a subset keeps. See [Subsetting](../subset.md).

## Requirements and limits

- Reading needs `ext-brotli`, or the `brotli` executable on the runtime's `PATH`.
- Writing needs an explicit `BrotliCompressorInterface` passed to `Woff2Writer`.
- Collections are not supported; use the TTC or OTC source instead.
- Outlines must be TrueType `glyf`, as for every other container.

When Brotli is installed but WOFF2 still fails, the PHP process that runs
your script is usually not the one you tested in a terminal; see
[Adapters](../compress/adapters.md#check-the-runtime).
