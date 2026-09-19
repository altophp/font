# Installation

ALTO Font requires PHP 8.4 or later with the Iconv and Zlib extensions.

```bash
composer require alto/font
```

WOFF2 files additionally require either the `brotli` PHP extension or the
`brotli` command-line program on `PATH`. TrueType, OpenType, WOFF, and font
collections do not require that optional dependency.

## Verify the installation

Save this as `check.php` beside the `vendor` directory and run `php check.php`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

echo class_exists(Alto\Font\Font::class) ? "ALTO Font is ready\n" : "Autoload failed\n";
```

Expected output:

```text
ALTO Font is ready
```

This checks the Composer setup without requiring a font file. Continue with
[Getting started](getting-started.md) to load a font and inspect its characters.

## If a font cannot be loaded

Loading failures implement `Alto\Font\Exception\FontExceptionInterface`.
Unsupported font features raise `UnsupportedFontException`; malformed files
and invalid selections raise `InvalidFontException`.
