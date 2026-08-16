# Understand fonts

A font family groups related designs such as Inter. A face is one selectable
member of that family, such as Inter Bold Italic. A file stores one face or a
collection of faces in a particular container.

## Core concepts

| Concept | Meaning | Alto Font API |
| --- | --- | --- |
| Family | Related faces sharing a family name | `metadata()->family` |
| Face | One weight, style, stretch, and set of glyph data | `face()` and `getDescriptor()` |
| File | The path loaded directly or selected by discovery | `Font::fromFile()` and `FontFinder` |
| Container | SFNT, WOFF, WOFF2, TTC, or OTC representation | `metadata()->format` |
| Outline | Geometry technology used to describe glyph shapes | TrueType `glyf` is supported |
| Character | Text supplied by an application | `UnicodeSet::fromText()` |
| Codepoint | Numeric Unicode value assigned to a character | `glyphIdForCodepoint()` |
| Glyph | Font-specific shape and metrics selected for display | `glyphMetrics()` and `glyphOutline()` |

A container does not determine every feature inside the font. An OpenType file
can contain supported TrueType `glyf` outlines or unsupported CFF outlines.
Likewise, one character maps to a font-specific glyph identifier; that
identifier is not a Unicode codepoint.

## Follow the data

1. Read [Formats](formats.md) to confirm that the container and outline are supported.
2. Use [Font files](font-files.md) to load a known path or discover a matching file.
3. Use [Font data](font-data.md) to inspect the selected face.

After loading and inspecting a face, continue with [Conversion](conversion.md),
[Compression](compression.md), or [Subsetting](subsetting.md).
