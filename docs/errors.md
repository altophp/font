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

## Exceptions

| Exception | Base class | Raised when | What to do |
| --- | --- | --- | --- |
| `InvalidFontException` | `RuntimeException` | The file is missing, unreadable, empty, or malformed; a face index is outside the collection; a glyph identifier is out of range; coordinates are selected on a static font or name an unknown axis. | Check the path and the face index, then use another source file. The data itself is the problem, not the environment. |
| `UnsupportedFontException` | `RuntimeException` | The font is valid but uses a structure the package does not handle: CFF or CFF2 outlines, a WOFF2 collection, a layout or variation structure that cannot be rewritten, or writing a view selected with `withVariations()`. | Use a font with TrueType outlines, keep the default subset policies, or call `withoutVariations()` before writing or subsetting. |
| `CompressionException` | `RuntimeException` | Brotli is unavailable or fails while reading or writing WOFF2, or Zlib fails while writing WOFF; the process-backed compressor cannot run or cannot use temporary storage. | Install `ext-brotli` or the `brotli` executable, check `PATH` and `proc_open()` for the PHP runtime that runs your script. |
| `FontWriteException` | `RuntimeException` | The destination already exists, its directory is missing, or the file cannot be written. | Create the directory, choose a new path, or manage replacement in your application. Writers never overwrite. |
| `FontNotFoundException` | `RuntimeException` | `FontFinder::get()` finds no font matching the query. | Relax the query, add a directory, or use `find()` to receive `null` instead. |
| `GlyphNotFoundException` | `RuntimeException` | `glyphMetrics()` receives a character the font does not map. | Check availability first with `glyphIdForCodepoint()`, or fall back to another font. |
| `InvalidTextException` | `InvalidArgumentException` | Text is not valid UTF-8, is empty, or contains more than one codepoint where a single character is expected. | Validate the input before calling the package. |
| `InvalidUnicodeRangeException` | `InvalidArgumentException` | A Unicode range or set expression is malformed. | Fix the expression; see [Characters](operations/subset/characters.md). |

Exceptions deriving from `InvalidArgumentException` report a caller mistake
that no retry will fix. Exceptions deriving from `RuntimeException` report a
property of the font, the destination, or the environment.

## Deprecated methods

`getFace()`, `getDescriptor()`, `getGlyphMetrics()`, and `getMetrics()` remain
available but are deprecated since 0.9.0. Use `face()`, `descriptor()`, and
`glyphMetrics()`.

For symptom-based diagnosis, read [Troubleshooting](troubleshooting.md).
