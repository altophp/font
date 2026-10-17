# Alto Font

ALTO Font reads, inspects, converts, and subsets TrueType-based font files from
PHP. It exposes metadata, character coverage, glyph measurements, outlines,
and variable-font data without changing the source file.

Use it to identify a font, prepare TTF, WOFF, or WOFF2 output, or create a
smaller font containing the characters an application needs. Text shaping and
raster rendering remain the responsibility of the application using the font.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
echo null === $font->glyphIdForCodepoint(0x41) ? 'A is missing' : 'A is available';
```

A font containing the capital letter A prints `A is available`.

## Documentation

- [Installation](installation.md): add the package with Composer and run a smoke test.
- [Getting started](getting-started.md): read a font installed on your machine and check that it covers your text.
- [Fonts](fonts.md): families, faces, and files, and what a loaded face exposes.
- [Glyphs](glyphs.md): map characters to glyphs, measure them, and trace their outlines.
- [Files](files.md): find a font by family, read a file or bytes, write TTF, WOFF, or WOFF2.
- [Subsetting](subset.md): keep the characters your application uses and check what remains.
- [Compression](compress.md): choose WOFF or WOFF2 and provide Brotli.
- [Formats](formats.md): supported containers and outlines, detected by signature.
- [Tables](tables.md): what subsetting keeps, rewrites, or refuses inside the font.
- [Errors](errors.md): the exceptions the package raises and how to recover.

Next: [Installation](installation.md).
