# Fonts

Family, face, file, and glyph mean different things, and the API follows
those meanings. A family groups related designs such as Inter. A face is one
selectable member of that family, such as Inter Bold Italic. A file stores one
face, or a collection of faces, in a particular container. These are the terms
of the OpenType specification, and the ones this documentation uses.

| Concept | Meaning | ALTO Font API |
| --- | --- | --- |
| Family | Related faces sharing a family name | `metadata()->family` |
| Face | One weight, style, stretch, and set of glyph data | `face()` and `descriptor()` |
| File | The path loaded directly or selected by discovery | `Font::fromFile()` and `FontFinder` |
| Container | TrueType, OpenType, WOFF, WOFF2, TTC, or OTC packaging | `metadata()->format` |
| Outline | Geometry technology used to describe glyph shapes | TrueType `glyf` is supported |
| Character | Text supplied by an application | `UnicodeSet::fromText()` |
| Codepoint | Numeric Unicode value assigned to a character | `glyphIdForCodepoint()` |
| Glyph | Font-specific shape and metrics selected for display | `glyphMetrics()` and `glyphOutline()` |

A container does not determine every feature inside the font. An OpenType
file can contain supported TrueType `glyf` outlines or unsupported CFF
outlines; see [Formats](formats.md). Likewise, one character maps to a
font-specific glyph identifier that is not a Unicode codepoint; see
[Glyphs](glyphs.md).

## Font and FontFace

`Alto\Font\Font` represents one loaded face and is the entry point for every
operation. `face()` returns a readonly `FontFace` with the structural facts of
the file: path, container, face index and count, units per em, ascender and
descender, glyph count, and table tags. A variable font contains several faces
in one file; a view selected with `withVariations()` is still the same
`FontFace`. See [Variable fonts](tables/variations.md).

Reading never modifies the source. Operations that produce a different font,
such as subsetting or writing, return or write a new one.

## Read a face

- [Families](fonts/families.md) explains family and subfamily names, and the weight, style, and stretch read from them.
- [Metadata](fonts/metadata.md) reads names, version, and license fields.
- [Metrics](fonts/metrics.md) reads font-wide bounds, line metrics, and embedding flags.

## Where fonts come from

Fonts installed on the machine are found through [Finding](files/find.md).
Fonts bundled with an application, or obtained from a foundry or a catalog of
open-source fonts, are loaded from their files through [Reading](files/read.md).
Every font carries a license; `metadata()->license` and
`metadata()->licenseUrl` expose the fields the designer embedded. Check the
license before converting, subsetting, or redistributing a font, including as
a web font.
