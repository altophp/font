# Compress WOFF2 output

WOFF2 writing requires an explicit Brotli compressor. This keeps extension and
process choices outside the writer API.

Reading is different: the loader automatically tries the Brotli extension and
then the `brotli` executable.

## Use the Brotli extension

```php
use Alto\Font\Compression\BrotliCompressionProfile;
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Writer\Woff2Writer;

$brotli = new BrotliExtensionCompressor(
    BrotliCompressionProfile::Maximum,
);

new Woff2Writer($brotli)->write(
    $font,
    __DIR__.'/output/font.woff2',
);
```

The extension must expose `brotli_compress()` and `BROTLI_FONT`. `Maximum` uses
quality 11 and is the default. `Fast` uses quality 5 for faster iterative
builds.

## Use the Brotli executable

```php
use Alto\Font\Compression\BrotliCompressionProfile;
use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Writer\Woff2Writer;

$brotli = new BrotliProcessCompressor(
    profile: BrotliCompressionProfile::Fast,
);

new Woff2Writer($brotli)->write(
    $font,
    __DIR__.'/output/font.woff2',
);
```

The binary defaults to `brotli` from `PATH`; the profile defaults to
`Maximum`. Pass `binary: '/opt/homebrew/bin/brotli'` when a macOS installation
is not available through `PATH`.

The process adapter requires `proc_open()`, a writable temporary directory,
and enough temporary disk space for the transformed and compressed streams.

## Choose `dump()` or `write()`

`dump()` returns the complete WOFF2 file as one string. `write()` with
`BrotliProcessCompressor` streams data at the compression boundary through
temporary resources. The parsed font document and WOFF2 table transforms still
exist in memory, so this is not a constant-memory promise for the complete
operation.

The extension adapter implements string compression only. Its `write()` path
therefore materializes the same compressed data used by `dump()`.

## Implement a custom compressor

Implement `BrotliCompressorInterface::compress()` to return one raw Brotli
stream for the supplied WOFF2 table data. Do not add a WOFF2 header, length
prefix, or padding.

An implementation may also implement `BrotliStreamCompressorInterface`.
`compressStream()` reads from the input's current position through EOF, writes
at the output's current position, leaves both resources open, and does not
rewind them.

Missing extension support, unavailable temporary resources, an unavailable
executable, non-zero process exits, and extension compression calls that report
failure raise `CompressionException`.
