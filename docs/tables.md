# Tables

Read this section when a subset is refused, when the output lacks something
the source had, or when you want to know what `HintingPolicy` and
`LayoutPolicy` remove. Inside a TrueType, OpenType, WOFF, or WOFF2 file the
data is split into tables, each named by a four-letter tag: `cmap` maps
characters to glyphs, `glyf` holds the outlines, `GPOS` positions them, `fvar`
declares variation axes. Reading leaves every table untouched. Subsetting
rewrites some, keeps others as they are, and refuses a few; which is which
decides whether compact numbering works on your font and what the output
loses.

- [Hinting](tables/hinting.md): what the instruction tables do at small sizes, and what `HintingPolicy::Drop` removes.
- [Layout](tables/layout.md): the tables behind ligatures, kerning, and marks, and what each `LayoutPolicy` keeps.
- [Variable fonts](tables/variations.md): what makes one file a whole family, and how to pick a weight.

## What every font contains

Every font the package loads has the eight tables the specification requires:
`cmap`, `head`, `hhea`, `hmtx`, `maxp`, `name`, `OS/2`, and `post`. They
provide the character map, the dimensions, the horizontal metrics, the names,
and the glyph names.

Outlines must be TrueType: `glyf`, indexed by `loca`. `CFF ` and `CFF2` are
rejected on load, and `SVG `, `COLR`, `CPAL`, `sbix`, `CBDT`, `CBLC`, `EBDT`,
and `EBLC` glyph data is not rendered; see [Formats](formats.md).

## What subsetting keeps, rewrites, or refuses

Writing an unchanged standalone font preserves every table byte for byte.
Subsetting with the default `GlyphIdPolicy::Preserve` keeps glyph slots in
place and rewrites only what the selection requires. `Compact` renumbers the
retained glyphs, so every table that stores glyph identifiers is rewritten,
dropped, or refused:

- `cmap`, `hmtx`, `hhea`, `maxp`, `OS/2`, and `post` are rewritten for the selection in both modes. Compact mode switches `post` to format 3 when glyph names must go.
- `glyf` and `loca` keep unselected glyphs as empty slots in preserve mode. Compact mode renumbers them and remaps compound references.
- `cvt `, `fpgm`, `prep`, `gasp`, `hdmx`, `LTSH`, `VDMX`, and `cvar` are kept with `HintingPolicy::Keep` and dropped with `Drop`, in both modes. See [Hinting](tables/hinting.md).
- `GSUB`, `GPOS`, and `GDEF` are kept, reduced, or dropped by `LayoutPolicy`. Compact mode also remaps them. See [Layout](tables/layout.md).
- `kern` format 0 is kept. Compact mode remaps it independently of `LayoutPolicy`.
- `fvar`, `avar`, `gvar`, `HVAR`, `vhea`, `vmtx`, and `VVAR` are kept. Compact mode remaps them. See [Variable fonts](tables/variations.md).
- `DSIG` is removed whenever any data is rebuilt.
- `meta` is kept in preserve mode and removed in compact mode, because its glyph references cannot be remapped safely.
- `VARC`, `BASE`, `MATH`, color, bitmap, and unsupported `kern` formats are kept as opaque bytes in preserve mode and refused with `UnsupportedFontException` in compact mode.

A refusal happens in `subset()`, before anything is written. Keep `Preserve`
for that font, or remove the data with `HintingPolicy` or `LayoutPolicy` when
it belongs to those groups. Remapping `vhea`, `vmtx`, and `VVAR` does not make
vertical layout supported while the other vertical tables are not. Read
`SubsetResult::$warnings` after every subset, and validate the output where
it will be shaped and rendered.
