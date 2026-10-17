# Writing WOFF2

Write WOFF2 when the files are served to browsers: it is the smallest
container, and every current browser accepts it. The package transforms the
tables itself and needs Brotli only for the final stream. PHP has no Brotli
built in, so you pass one of two adapters to `Woff2Writer`.

## With the PHP Brotli extension

Use this when the PHP runtime that runs your script has `ext-brotli` built
with font mode, which the adapter checks through `BROTLI_FONT`. Create
`output` first; the destination must be new.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\Woff2Writer;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$destination = __DIR__.'/output/inter.woff2';

new Woff2Writer(new BrotliExtensionCompressor())->write($font, $destination);

printf("Saved %s (%d bytes)\n", basename($destination), filesize($destination));
```

## With the brotli executable

Use this when `brotli` is installed on the machine, from a package manager
for instance, and the PHP process may call `proc_open()`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\Woff2Writer;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$destination = __DIR__.'/output/inter.woff2';

new Woff2Writer(new BrotliProcessCompressor())->write($font, $destination);

printf("Saved %s (%d bytes)\n", basename($destination), filesize($destination));
```

Pass `binary: '/opt/homebrew/bin/brotli'` or another absolute path to the
constructor when the executable is not on the `PATH` of the PHP process. The
adapter also needs a writable temporary directory.

## Choose a quality profile

Both adapters default to `BrotliCompressionProfile::Maximum`, quality 11.
`Fast`, quality 5, suits builds you rerun on every change. Measured with the
`brotli` 1.2 executable on the complete bundled Inter Latin sample:

| Profile | Size | Time |
| --- | --- | --- |
| `Maximum` | 23,272 bytes | 0.13 s |
| `Fast` | 26,156 bytes | 0.02 s |

```php
use Alto\Font\Compression\BrotliCompressionProfile;
use Alto\Font\Compression\BrotliProcessCompressor;

$compressor = new BrotliProcessCompressor(profile: BrotliCompressionProfile::Fast);
```

Deploy the files written with `Maximum`.

## Write a subset

Pass the font returned by [Subsetting](../subset.md) to the same writer:

```php
$result = $font->subset(new SubsetOptions($characters, glyphIds: GlyphIdPolicy::Compact));

new Woff2Writer(new BrotliExtensionCompressor())->write($result->font, __DIR__.'/output/inter-ui.woff2');
```

For the translated-interface subset of the Subsetting page, this gives 18,848
bytes, against 27,100 bytes for the same subset as WOFF.

## When writing fails

`CompressionException` is raised when the extension lacks font mode, when the
executable cannot be found or started, or when the temporary directory is not
writable. Check the runtime that runs your script rather than your terminal:
a PHP-FPM worker or a container often has neither the extension nor the
executable. [Adapters](adapters.md#check-the-runtime) lists the checks.

Reading a WOFF2 file also needs Brotli; without it, `Font::fromFile()` raises
`UnsupportedFontException`. [WOFF2](../formats/woff2.md) under Formats
describes the container itself.
