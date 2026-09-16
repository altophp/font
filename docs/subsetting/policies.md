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

GSUB compaction handles lookup types 1 through 8, including extension lookups.
GPOS compaction handles lookup types 1 through 9, with type 9 used for extension
lookups. Support for every lookup type does not imply support for every valid
font: the restrictions below still apply. OpenType Layout 1.1 feature
variations are preserved. GPOS positioning records retain valid Device and
VariationIndex data while their offsets are relocated. Non-NULL feature
parameters are supported for `size`, `ss01` through `ss20`, and `cv01` through
`cv99`; unknown parameter formats are rejected explicitly.

Oversized PairPos format 1 data is split across lookup subtables, including
within an existing PairSet at glyph-pair record boundaries. Both value records
and their Device or VariationIndex adjustments are retained. PairPos
format 2 removes unused classes and splits class rows when its internal
16-bit offsets would overflow. A row that still cannot fit is expanded into
format 1 glyph-pair records, split across subtables as needed. Implicit class
0 and Device or VariationIndex adjustments are preserved. A single pair record
with its adjustment data, or other layout structures, can still exceed their
offset limits and be rejected.

GDEF ligature caret values support formats 1, 2 and 3. Format 3 retains Device
or VariationIndex adjustments while relocating their offsets. The shared
variation store is rebuilt from its referenced structures, so it need not be
the final top-level subtable in the source GDEF table.

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
`ItemVariationStore` delta sets and their indexes are preserved. Their physical
layout is rebuilt without unrelated bytes or padding; unused delta sets are
not removed. This is not static instancing or axis-range reduction.

HVAR and VVAR mappings can precede or follow the variation store, including
gaps between its referenced structures. Shared mapping and variation-data
references are supported; overlapping structures are rejected. The store
copier preserves both ordinary and long-word delta encodings. This does not
extend support to other tables such as COLR that use long-word deltas.

A view created with `withVariations()` cannot be subsetted. Return to the
variable source with `withoutVariations()` first.

Compact mode still rejects `VARC`, `BASE`, color, bitmap, mathematical tables,
unsupported legacy kerning, and other glyph-indexed structures it cannot
remap. Private `meta` data is removed because its glyph references cannot be
remapped safely. Supporting `vhea`, `vmtx`, and VVAR does not imply general
vertical-layout support while those companion tables remain unsupported.

Always inspect `SubsetResult::$warnings` and validate the final output in the
environment that will shape and render it.

Preserving outlines and positioning does not guarantee identical rasterized
pixels. In the Recursive/CoreText validation sample, removing `post` glyph
names changes small-size rendering even when all other font data is retained.
Removing glyphs used by that renderer can also affect retained glyphs.
