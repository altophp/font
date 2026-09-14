# Choose subset policies

`SubsetOptions` controls three independent decisions. The defaults preserve
the source font's structure and are recommended for the first result.

| Decision | Default | Advanced choices | Main trade-off |
| --- | --- | --- | --- |
| Glyph IDs | `GlyphIdPolicy::Preserve` | `Compact` | Smaller glyph space, but more tables must be rewritten |
| Hinting | `HintingPolicy::Keep` | `Drop` | Smaller output, but low-resolution rendering may change |
| Layout | `LayoutPolicy::Preserve` | `SubstitutionsOnly`, `Drop` | Smaller output, but positioning, ligatures, or shaping may change |

## Preserve the source behavior

```php
$result = $font->subset(new SubsetOptions($characters));
```

Preserve mode keeps original glyph IDs and leaves unused slots empty. Compound
components and GSUB dependencies are retained. GPOS and GDEF remain valid
because glyph IDs do not change.

## Compact glyph IDs

```php
use Alto\Font\Subset\GlyphIdPolicy;

$result = $font->subset(new SubsetOptions(
    $characters,
    glyphIds: GlyphIdPolicy::Compact,
));
```

Compact mode renumbers retained glyphs and removes PostScript glyph names by
writing `post` format 3 when required. It rewrites supported `cmap`, horizontal
and vertical metrics, compound, GSUB, GPOS, GDEF, legacy `kern` format 0,
`gvar`, HVAR, and VVAR mapping structures. Any glyph-indexed table that cannot
be rewritten causes an explicit rejection.

GSUB compaction covers lookup types 1 through 8, including extension lookups.
GPOS compaction covers lookup types 1 through 9, with type 9 used for extension
lookups. OpenType Layout 1.1 feature variations are preserved. Positioning
records retain valid Device and VariationIndex data while their offsets are
relocated. Non-NULL feature parameters are supported for `size`, `ss01`
through `ss20`, and `cv01` through `cv99`; unknown parameter formats are
rejected explicitly. Oversized PairPos format 1 data is split across lookup
subtables. A PairPos format 2 class matrix that cannot fit its internal 16-bit
offsets is still rejected.

## Remove hinting

```php
use Alto\Font\Subset\HintingPolicy;

$result = $font->subset(new SubsetOptions(
    $characters,
    hinting: HintingPolicy::Drop,
));
```

This removes glyph instructions and the related `cvar`, `cvt `, `fpgm`,
`prep`, `hdmx`, `LTSH`, and `VDMX` tables.

## Reduce layout data

`LayoutPolicy::SubstitutionsOnly` keeps supported GSUB substitutions and their
required GDEF data while removing GPOS positioning. `LayoutPolicy::Drop`
removes GSUB, GPOS, and GDEF entirely.

This policy controls OpenType Layout tables. A legacy `kern` table is preserved
and remapped independently when its subtables use supported format 0.

Dropping layout is appropriate only for controlled content such as digits,
icons, or isolated symbols. It is unsafe for general prose or scripts that
depend on shaping and mark positioning.

## Variable fonts

Supported variable TrueType subsets preserve all axes. Compact mode remaps
per-glyph `gvar` blocks and HVAR mappings while preserving supported axis data.
When `vhea` and `vmtx` are present, their glyph metrics are remapped together;
VVAR mappings are also remapped when present. The HVAR and VVAR
`ItemVariationStore` data is preserved rather than reduced. This is not static
instancing or axis-range reduction.

A view created with `withVariations()` cannot be subsetted. Return to the
variable source with `withoutVariations()` first.

Compact mode still rejects `VARC`, `BASE`, color, bitmap, mathematical tables,
unsupported legacy kerning, and other glyph-indexed structures it cannot
remap. Private `meta` data is removed because its glyph references cannot be
remapped safely. Supporting `vhea`, `vmtx`, and VVAR does not imply general
vertical-layout support while those companion tables remain unsupported.

Always inspect `SubsetResult::$warnings` and validate the final output in the
environment that will shape and render it.
