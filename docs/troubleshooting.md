# Troubleshooting

Find the symptom that matches your situation, check its cause, and apply the
fix before writing or distributing the result. [Errors](errors.md) lists the
exceptions by class.

## The font will not load

Check the path and read permissions first, then the [supported formats](formats.md).
An `.otf` extension does not tell you whether the font contains supported
outlines: CFF and CFF2 outlines and WOFF2 collections are unsupported, and
renaming a file does not make it compatible.

`InvalidFontException` indicates malformed data or an invalid selection, such
as a collection face index outside its range. `UnsupportedFontException`
indicates a feature the package cannot handle. Use a supported source font.

## Brotli is installed, but WOFF2 still fails

Reading WOFF2 uses the PHP Brotli extension when it is loaded, then the
`brotli` executable. Writing requires an explicit compressor passed to
`Woff2Writer`.

Check the PHP runtime that runs your script and its `PATH`; a web worker may
differ from your terminal. The process-backed compressor also needs
`proc_open()` and writable temporary storage. See [Compress](operations/compress.md)
for both configurations and custom executable paths.

## Writing fails, or the second run fails

Create the destination directory before writing. Writers do not create it and
never overwrite an existing file; use a new filename for another run.
`FontWriteException` reports destination problems, `CompressionException`
reports compression or temporary-resource failures.

## Some requested characters are absent

Subsetting keeps the requested characters that exist in the source; it cannot
add characters the source lacks. Use the
[missing-character check](operations/subset.md#check-missing-characters-before-writing)
before writing, and select the complete text your application needs, including
punctuation, spaces, and accented forms. `SubsetResult::$warnings` describes
transformations; it is not a list of missing characters.

## A selected weight cannot be written or subsetted

`withVariations()` returns a view for reading measurements and outlines. A
weighted `FontFinder` query can return such a view for a variable font. Neither
is a static font export.

Call `withoutVariations()` to write or subset the original variable font with
all its axes. If you need a fixed-weight file, use a static font instead. See
[Variable fonts](tables/variations.md).

## The subset is larger than expected

Compound glyphs and substitution dependencies remain even when they are not
requested, and the default policy keeps the original glyph slots. Compare
actual output files in the same format, then consider
[compact glyph numbering](operations/subset/policies.md#compact-glyph-numbers).

`sfntSize` measures the uncompressed font, and `face()->glyphCount` includes
empty slots in preserve mode. Use `filesize()` and `retainedGlyphCount` for the
written size and the retained glyph count.

## The output looks different

Start with the default subset options, which preserve hinting and layout, and
compare exactly the text your application uses at its actual display sizes.
Check that accented forms and required characters were included.

Matching outlines and positioning do not guarantee matching pixels on every
renderer. Recursive has a documented CoreText dependency on glyph names and
additional glyphs; see the
[technical diagnosis](https://github.com/altophp/font/blob/main/tests/Validation/RECURSIVE_RENDERING.md).
Its workaround is not a general solution for other fonts.

## Calls to `metrics($glyph)` stopped working

Since 0.9.0, `metrics()` takes no argument and returns `FontMetrics`, the
font-wide metrics. Replace `$font->metrics('A')` with `$font->glyphMetrics('A')`,
which accepts a glyph identifier or one UTF-8 character and returns
`GlyphMetrics`. See [Metrics](fonts/metrics.md) and [Glyph metrics](glyphs.md#read-metrics-by-identifier).

## Reporting a problem

Include the exception or the visible difference, the font source and version,
the selected text, the options, the output format, and the target renderer.
