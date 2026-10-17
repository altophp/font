# Layout

OpenType Layout is the group of tables that turn a sequence of glyphs into
correctly shaped text. `GSUB` substitutes glyphs: ligatures such as `fi`,
contextual forms in joining scripts, stylistic alternates. `GPOS` positions
them: kerning pairs, mark attachment for accents, cursive joins. `GDEF`
classifies glyphs for both and records ligature caret positions. The older
`kern` table holds pair kerning for renderers that predate `GPOS`.

The package does not shape text. It preserves these tables so that the
application's text engine can, and it rewrites them when a compact subset
renumbers the glyphs they reference.

## Preserve, substitutions only, or drop

`LayoutPolicy::Preserve` is the default and keeps `GSUB`, `GPOS`, and `GDEF`,
including the glyphs that supported substitutions need beyond the requested
characters. `LayoutPolicy::SubstitutionsOnly` keeps supported `GSUB`
substitutions and their required `GDEF` data while removing `GPOS`
positioning. `LayoutPolicy::Drop` removes all three:

```php
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;

$result = $font->subset(new SubsetOptions(
    $characters,
    layout: LayoutPolicy::SubstitutionsOnly,
));
```

Dropping layout data breaks prose and scripts that depend on shaping; reserve
it for controlled content such as isolated symbols. A legacy `kern` table is
preserved and remapped independently of this policy when its subtables use
format 0, so `Drop` does not remove every kerning adjustment.

## What compact mode rewrites

Compact numbering rewrites every glyph reference in these tables. GSUB lookup
types 1 to 8 and GPOS lookup types 1 to 9 are handled, extension lookups
included, as are OpenType Layout 1.1 feature variations, the Device and
VariationIndex adjustments of positioning records, GDEF ligature carets in
formats 1 to 3, and the feature parameters of `size`, `ss01` to `ss20`, and
`cv01` to `cv99`. Pair positioning data that outgrows its 16-bit offsets is
split across subtables automatically.

What cannot be rewritten raises `UnsupportedFontException` before anything is
written: an unknown feature parameter format, a single record that exceeds its
offset limits, or a lookup structure the package does not know. Keep
`GlyphIdPolicy::Preserve` for such a font. [Tables](../tables.md) lists what
each mode keeps, and [Policies](../subset/policies.md) the subset decisions.
