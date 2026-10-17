# Errors

Every exception raised by ALTO Font implements
`Alto\Font\Exception\FontExceptionInterface`. Catch the interface when the
recovery is the same for every failure, or a specific class when the next step
depends on the cause.

```php
use Alto\Font\Exception\FontExceptionInterface;
use Alto\Font\Font;

try {
    $font = Font::fromFile($path);
} catch (FontExceptionInterface $error) {
    // Reject the file or try another candidate.
}
```

## Font and environment failures

These exceptions extend `RuntimeException`. They report a property of the
font, the destination, or the environment, so another file or another setup
can succeed where this one failed.

### InvalidFontException

The package raises it when the file is missing, unreadable, empty, or
malformed; when a WOFF2 stream fails to decompress; when a face index is
outside the collection; when a glyph identifier is out of range; or when
coordinates are selected on a static font or name an unknown axis.

Check the path and the face index, then use another source file. The data
itself is the problem, not the environment.

### UnsupportedFontException

The package raises it when the font is valid but uses a structure it does not
handle: CFF or CFF2 outlines, a WOFF2 collection, a WOFF2 file when neither
`ext-brotli` nor the `brotli` executable is available, a layout or variation
structure that cannot be rewritten, or writing a view selected with
`withVariations()`.

Use a font with TrueType outlines, keep the default subset policies, or call
`withoutVariations()` before writing or subsetting.

### CompressionException

The package raises it when writing WOFF2 fails because the configured
compressor cannot run, `ext-brotli` lacks font mode, or the process-backed
compressor cannot use temporary storage, and when Zlib fails while writing
WOFF.

Install `ext-brotli` or the `brotli` executable, then check `PATH` and
`proc_open()` for the PHP runtime that runs your script.

### FontWriteException

The package raises it when the destination already exists, its directory is
missing, or the file cannot be written.

Create the directory, choose a new path, or manage replacement in your
application. Writers never overwrite.

### FontNotFoundException

The package raises it when `FontFinder::get()` finds no font matching the
query.

Relax the query, add a directory, or use `find()` to receive `null` instead.

### GlyphNotFoundException

The package raises it when `glyphMetrics()` receives a character the font does
not map.

Check availability first with `glyphIdForCodepoint()`, or fall back to another
font.

## Caller mistakes

These exceptions extend `InvalidArgumentException`. They report a mistake in
the calling code that no retry will fix.

### InvalidTextException

The package raises it when text is not valid UTF-8, is empty, or contains more
than one codepoint where a single character is expected.

Validate the input before calling the package.

### InvalidUnicodeRangeException

The package raises it when a Unicode range or set expression is malformed.

Fix the expression; see [Characters](subset/characters.md).

## Deprecated and changed methods

`getFace()`, `getDescriptor()`, `getGlyphMetrics()`, and `getMetrics()` remain
available but are deprecated since 0.9.0. Use `face()`, `descriptor()`, and
`glyphMetrics()`.

Since 0.9.0, `metrics()` takes no argument and returns the font-wide
`FontMetrics`. A call such as `$font->metrics('A')` must become
`$font->glyphMetrics('A')`, which accepts a glyph identifier or one UTF-8
character and returns `GlyphMetrics`; see [Metrics](fonts/metrics.md) and
[Glyph metrics](glyphs/metrics.md).
