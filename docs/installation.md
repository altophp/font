# Installation

Alto Font requires PHP 8.4 or later and the Zlib extension.

```bash
composer require alto/font
```

WOFF2 files additionally require either the `brotli` PHP extension or the
`brotli` command-line program on `PATH`. TrueType, OpenType, WOFF, and font
collections do not require that optional dependency.

## Verify the installation

Use a font file that belongs to your application or test fixtures:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');

echo $font->metadata()->family;
```

The script prints the family stored in the font, such as `Inter`.

Loading failures implement `Alto\Font\Exception\FontExceptionInterface`.
Unsupported font features raise `UnsupportedFontException`; malformed files
and invalid selections raise `InvalidFontException`.
