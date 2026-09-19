# Write a WOFF2 file

Choose a Brotli compressor for writing. Reading WOFF2 detects the PHP Brotli
extension or executable automatically; writing uses the adapter you provide.

## With the PHP Brotli extension

Use this when the PHP runtime running your script provides `brotli_compress()`
and `BROTLI_FONT`. Create `output` first and use a new destination filename.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\Woff2Writer;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$destination = __DIR__.'/output/inter.woff2';

new Woff2Writer(new BrotliExtensionCompressor())->write($font, $destination);

printf("Saved inter.woff2 (%d bytes)\n", filesize($destination));
```

This writes `output/inter.woff2` and prints its actual byte size.
To write a subset, pass `$result->font` from the [subset workflow](../subset.md)
to the same writer.

## With the Brotli executable

Use this as a separate script if `brotli` is on the process `PATH`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\Woff2Writer;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$destination = __DIR__.'/output/inter.woff2';

new Woff2Writer(new BrotliProcessCompressor())->write($font, $destination);

printf("Saved inter.woff2 (%d bytes)\n", filesize($destination));
```

Set `binary: '/path/to/brotli'` in the compressor constructor if needed.
The process adapter requires `proc_open()`, a writable temporary directory,
and enough temporary disk space. Missing dependencies and compression failures
raise `CompressionException`; see [runtime requirements](#check-the-runtime).

## Choose compression speed

Both adapters default to `BrotliCompressionProfile::Maximum` (quality 11).
For faster iterative builds, pass `profile: BrotliCompressionProfile::Fast`
(quality 5). Import `Alto\Font\Compression\BrotliCompressionProfile` when using
these constants. Compare the resulting sizes for your own fonts.

## Files, bytes, and memory

Use `write()` for a new file or `dump()` to obtain a complete WOFF2 string for
a storage API. Existing files are never replaced.

With the process adapter, `write()` streams at the compression boundary using
temporary resources. The parsed font and transforms still occupy memory.
The extension adapter materializes the compressed data for both methods.
Neither promises constant memory for the whole operation.

## Custom compressors

Implement `BrotliCompressorInterface::compress()` to return a raw Brotli stream,
without a WOFF2 header, length prefix, or padding.

An optional `BrotliStreamCompressorInterface::compressStream()` implementation
reads from the current input position to EOF, writes at the current output
position, leaves both resources open, and does not rewind them.

## Check the runtime

Reading WOFF2 detects the PHP Brotli extension or the `brotli` executable.
Writing needs an explicit compressor passed to `Woff2Writer`, even when Brotli
is installed. Check the PHP runtime executing your script: a web worker may
have different extensions and an executable `PATH` unlike your terminal.

The process adapter needs `proc_open()` and writable temporary storage. Configure
an explicit executable path when it is unavailable through the worker's `PATH`.
