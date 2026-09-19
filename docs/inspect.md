# Inspect fonts

`Alto\Font\Font` represents one loaded face. Reading its data leaves the source
file unchanged. Start with the task that matches the information you need:

- [Font files](inspect/font-files.md) loads a known path or collection face.
- [Metadata](inspect/metadata.md) reads names, styles, versions, and license fields.
- [Discovery](inspect/discovery.md) finds a face by family, weight, and style.
- [Glyphs](inspect/glyphs.md) reads mappings, measurements, and outlines.
- [Variations](inspect/variations.md) explores axes and selected coordinates.

The contracts below provide the exact entry points shared by those guides.

Return types below use the public `Alto\Font` subnamespaces: `Metadata`,
`Descriptor`, `Glyph`, `Variation`, and `Subset`. `FontFace` belongs directly
to `Alto\Font`.

## Load and inspect a face

| Signature | Contract |
| --- | --- |
| `Font::fromFile(string\|Stringable $file, int $faceIndex = 0): Font` | Read a local file immediately. Detect its container from the bytes. Select a zero-based face in a TTC or OTC collection. |
| `face(): FontFace` | Structural data for the loaded face, including its original path, container, dimensions, glyph count, and table tags. |
| `metadata(): FontMetadata` | Names, version, license fields, and detected format. Optional name fields may be `null`. |
| `descriptor(): FontDescriptor` | Family, weight, style, and stretch used by font discovery. This does not implement the full CSS font-matching algorithm. |

Loading can raise `InvalidFontException` for missing, unreadable, empty, or
malformed files and invalid face selections; `UnsupportedFontException` for
unsupported font structures; or `CompressionException` for WOFF2 Brotli failures.
Read [Formats](formats.md) for the accepted containers and outlines.
Some table validation occurs when the requested data is accessed, so loading
success does not guarantee every later operation will succeed.

`FontFace` is readonly. Its public properties are:

| Properties | Meaning |
| --- | --- |
| `path: string`, `format: FontFormat` | Source label and detected container. |
| `faceIndex: int`, `faceCount: int` | Selected zero-based face and number of faces in the source. |
| `unitsPerEm: int`, `ascender: int`, `descender: int` | Design-unit dimensions. Divide a measurement by `unitsPerEm` and multiply by the target size to scale it. |
| `glyphCount: int` | Number of glyph slots, including empty slots retained by preserve-mode subsetting. |
| `tables: list<string>` | Available table tags, not raw table bytes. |
| `names: array<int, string>` | Decoded name fields keyed by name ID. `name(int $nameId): ?string` returns a value or `null`. |

## Characters, measurements, and outlines

| Signature | Contract |
| --- | --- |
| `glyphIdForCodepoint(int $codepoint): ?GlyphId` | Find the face-specific identifier for one Unicode codepoint. Return `null` when the character map has no entry. |
| `metrics(GlyphId\|string $glyph): GlyphMetrics` | Read horizontal metrics from an identifier or a UTF-8 string containing exactly one codepoint. |
| `glyphMetrics(GlyphId $glyphId): GlyphMetrics` | Read horizontal metrics using an identifier from this face. |
| `glyphOutline(GlyphId $glyphId): GlyphOutline` | Read neutral contour geometry using an identifier from this face. An empty outline is valid, for example for a space. |

`GlyphMetrics` exposes `glyphId: GlyphId`, `advanceWidth: int`, and
`leftSideBearing: int`. Measurements use font design units. They describe an
individual glyph before text shaping, kerning, or rasterization.

`GlyphId::$value` is a non-negative integer. A glyph identifier is specific to
its font; do not reuse it with another face or after compact subsetting.

`metrics()` throws `InvalidTextException` for invalid UTF-8, empty text, or more
than one codepoint. A missing character throws `GlyphNotFoundException`.
Identifier-based reads can throw `InvalidFontException` for an out-of-range
identifier or malformed glyph data. Unsupported outline structures throw
`UnsupportedFontException`.

`GlyphOutline` exposes `glyphId` and a list of `contours`. Each contour exposes
its `commands` (`M`, `L`, `Q`, or `Z`). `isEmpty(): bool` reports whether there
are no contours. `transform(float $xx, float $yx, float $xy, float $yy,
float $dx, float $dy): GlyphOutline` returns a new affine-transformed outline.
It does not write SVG or draw pixels. See the [outline examples](inspect/glyphs.md).

## Variable-font views

| Signature | Contract |
| --- | --- |
| `variations(): ?FontVariations` | Return axes and named instances, or `null` for a static face. |
| `withVariations(array\|VariationCoordinates $coordinates): Font` | Return a new view at the requested axis coordinates. Array keys are axis tags; values are integers or floats. Unspecified axes use their defaults. Out-of-range values are clamped. |
| `variationCoordinates(): ?VariationCoordinates` | Return resolved selected coordinates, or `null` when no view was selected. |
| `withoutVariations(): Font` | Return the unselected variable source. If no view is selected, return the same object. |

Selecting coordinates on a static font or supplying an unknown axis raises
`InvalidFontException`. `FontVariations::$axes` contains the axis tag, minimum,
default, maximum, flags, and optional name. `instances` contains named presets.

Selected views affect supported glyph measurements and outlines. They do not
create a static font. Writing or subsetting such a view throws
`UnsupportedFontException`; use `withoutVariations()` to work with the original
variable font and all of its axes.

## Produce font data

| Signature | Contract |
| --- | --- |
| `toSfnt(): string` | Return the complete standalone SFNT bytes in memory. It does not write a file. |
| `subset(SubsetOptions $options): SubsetResult` | Return a new font and a report for the character selection and policies. It does not modify or write the source. |

Use the [writer contracts](convert/writers.md#writer-contracts) for file output
and container reconstruction. [Subset](subset.md) documents character
selection, policy defaults, result fields, and failure boundaries.

The older `getFace()`, `getDescriptor()`, `getGlyphMetrics()`, and `getMetrics()`
aliases are deprecated. Use their counterparts without `get` in new code.
