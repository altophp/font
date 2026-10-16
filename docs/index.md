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
- [Getting started](getting-started.md): check that a font covers the characters of your text.
- [Operations](operations.md): find, read, subset, compress, and write fonts, from a file on disk to a delivered web font.
- [Fonts](fonts.md): families, faces, and files, and what a loaded face exposes.
- [Glyphs](glyphs.md): map characters to glyphs, measure them, and trace their outlines.
- [Formats](formats.md): supported containers and outlines, detected by signature.
- [Tables](tables.md): the sfnt table structure, and what the package keeps, rewrites, or rejects.
- [Errors](errors.md): the exceptions the package raises and how to recover.
- [Troubleshooting](troubleshooting.md): symptoms, causes, and fixes.

## Boundaries

ALTO Font supports TrueType outlines in the containers listed under
[Formats](formats.md). It does not support CFF or CFF2 outlines, shape text,
rasterize glyphs, or export a selected variable-font instance as a fixed font.
Check the font's license before distributing converted or subsetted files.
