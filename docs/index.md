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

- [Installation](installation.md): install the package and verify Composer autoloading.
- [Getting started](getting-started.md): load a font and check its character coverage.
- [Fonts](fonts.md): understand files, faces, families, characters, and glyphs.
- [Inspect](inspect.md): read files, metadata, glyphs, and variable-font data.
- [Convert](convert.md): write TTF, WOFF, and WOFF2 output.
- [Subset](subset.md): keep selected characters and control the resulting font.
- [Formats](formats.md): check supported containers, outlines, and runtime requirements.

## Boundaries

ALTO Font supports TrueType outlines in the containers listed under
[Formats](formats.md). It does not support CFF or CFF2 outlines, shape text,
rasterize glyphs, or export a selected variable-font instance as a fixed font.
Check the font's license before distributing converted or subsetted files.
