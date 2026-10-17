# Compression

Compress a font when it is served over the network. WOFF and WOFF2 wrap the
same tables in a compressed container; the browser unpacks them, so nothing
changes in the glyphs, hinting, or layout. Compression happens while writing,
through the writer you choose, and it combines with subsetting: subset first
to remove glyphs, then write a compressed container.

- [Writing WOFF2](compress/woff2.md) produces the smallest container, with the PHP Brotli extension or the `brotli` executable.
- [Adapters](compress/adapters.md) plugs in your own Brotli implementation and covers streaming, memory, and runtime checks.
- [Sizes](compress/sizes.md) lists measured sizes for TTF, WOFF, and WOFF2, with and without subsetting.

## Choose WOFF or WOFF2

Write WOFF2 when you can: every current browser accepts it, and on the bundled
Inter Latin sample it is 27% smaller than WOFF. Writing it needs Brotli, which
PHP does not ship; [Writing WOFF2](compress/woff2.md) shows the two ways to provide it.
Write WOFF when Brotli is not available in the runtime that produces the
files: it needs only the Zlib extension, which the package already requires.

For the complete sample face, the TTF measures 66,912 bytes, the WOFF 31,792,
and the WOFF2 23,272 at maximum quality.

## Write a WOFF file

```php
use Alto\Font\Font;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

new WoffWriter()->write($font, __DIR__.'/output/inter.woff');
```

Each table is compressed with Zlib at level 6, and stored as it is when
compression would not make it smaller. There is nothing to configure.

## Write a WOFF2 file

```php
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\Woff2Writer;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

new Woff2Writer(new BrotliExtensionCompressor())->write($font, __DIR__.'/output/inter.woff2');
```

`Woff2Writer` takes the compressor explicitly, even when Brotli is installed,
so that the dependency is visible in your code. Replace
`BrotliExtensionCompressor` with `BrotliProcessCompressor` to use the `brotli`
executable; see [Writing WOFF2](compress/woff2.md).
