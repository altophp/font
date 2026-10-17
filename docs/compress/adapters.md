# Adapters

Provide your own Brotli implementation when neither the PHP extension nor the
`brotli` executable fits the runtime that writes your fonts: another Brotli
binding, a service that compresses remotely, or a test double. `Woff2Writer`
accepts any `BrotliCompressorInterface`.

## Implement a compressor

`compress()` receives the transformed table data and returns one raw Brotli
stream, without a WOFF2 header, length prefix, or padding:

```php
use Alto\Font\Compression\BrotliCompressorInterface;
use Alto\Font\Writer\Woff2Writer;

final class RemoteBrotliCompressor implements BrotliCompressorInterface
{
    public function compress(string $data): string
    {
        // Send $data to the compression service and return the raw Brotli stream.
    }
}

$writer = new Woff2Writer(new RemoteBrotliCompressor());
```

Throw `CompressionException` when the stream cannot be produced, so that
callers can catch one exception type for every adapter.

## Stream instead of holding bytes

An adapter that also implements `BrotliStreamCompressorInterface` lets
`write()` stream at the compression boundary. `compressStream($input, $output)`
reads from the current position of `$input` to its end, writes the raw Brotli
stream at the current position of `$output`, and leaves both resources open
without rewinding them. The parsed font and the table transforms still occupy
memory; neither method promises constant memory for the whole operation.

`BrotliProcessCompressor` streams; `BrotliExtensionCompressor` materializes
the compressed data for both `write()` and `dump()`.

## Files or bytes

`write()` creates a new file and never replaces an existing path. `dump()`
returns the complete WOFF2 as one string, for a storage API that accepts
bytes.

## Check the runtime

The runtime that writes the fonts is the one to check, not your terminal: a
PHP-FPM worker, a queue consumer, or a container can have a different set of
extensions and a different `PATH`.

- Extension adapter: `function_exists('brotli_compress')` and `defined('BROTLI_FONT')` must both be true.
- Process adapter: the executable must be found through `PATH` or the `binary` argument, `proc_open()` must not be disabled, and `sys_get_temp_dir()` must be writable.

Reading WOFF2 detects the extension or the executable on its own; writing
needs the explicit adapter even when both are installed.
