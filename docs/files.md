# Files

Use this section when the font you need is a file: installed on the machine,
bundled with your application, or stored as bytes in a database, and when the
result of your work has to become a file again. Reading never modifies the
source. Writing always creates a new file and never replaces an existing one.

- [Finding fonts](files/find.md) selects a face by family, weight, and style from directories you name.
- [Reading files](files/read.md) loads a TTF, OTF, WOFF, WOFF2, or collection face from a path or from bytes.
- [Writing files](files/write.md) produces a TTF, WOFF, or WOFF2 file, or the bytes, from any loaded face.

## Convert a file to a web font

The shortest useful script reads a TrueType file and writes it as WOFF, which
every browser accepts in `@font-face`. Place a font at `fonts/Inter-Regular.ttf`
beside `vendor`, create an `output` directory, and run:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;
use Alto\Font\Writer\WoffWriter;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$destination = __DIR__.'/output/inter.woff';

new WoffWriter()->write($font, $destination);

printf("Saved %s (%d bytes)\n", basename($destination), filesize($destination));
```

For the bundled Inter Latin sample this prints `Saved inter.woff (31792 bytes)`
for a 66,912-byte TTF. The glyphs are untouched: the file is smaller because
WOFF compresses each table. [Subsetting](subset.md) is the step that removes
glyphs, and [Compression](compress.md) covers WOFF2, which compresses further
but needs Brotli.

A browser loads the result through a regular declaration:

```css
@font-face {
    font-family: 'Inter';
    src: url('/fonts/inter.woff') format('woff');
}
```

## What a file can become

Any loaded face can be written as TTF, WOFF, or WOFF2, including one face
taken out of a TTC or OTC collection, which comes out as a standalone font. A
variable font is written with all its axes; see [Variable fonts](tables/variations.md).
Each run writes to a new path: choose a fresh filename or remove the previous
output in your application.
