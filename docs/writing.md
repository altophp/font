# Writing fonts

Alto Font writes supported faces as standalone SFNT, WOFF, or WOFF2 fonts.
Every writer creates a new destination and refuses to replace an existing file.

```php
use Alto\Font\Font;
use Alto\Font\Writer\SfntWriter;

$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);

new SfntWriter()->write(
    $font,
    __DIR__.'/output/SelectedFace.ttf',
);
```

An unchanged standalone SFNT is preserved byte-for-byte. Collection faces and
webfont inputs are reconstructed with a new table directory and checksums.

Use `dump()` when the bytes belong in memory or another storage abstraction:

```php
$bytes = new SfntWriter()->dump($font);
```

Selecting variable coordinates with `withVariations()` does not yet create a
static font instance. Writing such a selected view raises
`UnsupportedFontException` rather than returning the original variable font
under a misleading name.

## WOFF and WOFF2

```php
use Alto\Font\Compression\BrotliCompressionProfile;
use Alto\Font\Compression\BrotliExtensionCompressor;
use Alto\Font\Writer\Woff2Writer;
use Alto\Font\Writer\WoffWriter;

new WoffWriter()->write($font, __DIR__.'/output/font.woff');

$brotli = new BrotliExtensionCompressor(BrotliCompressionProfile::Maximum);
new Woff2Writer($brotli)->write($font, __DIR__.'/output/font.woff2');
```

WOFF uses Zlib level 6. WOFF2 uses an injected Brotli compressor. The native
adapter requires `ext-brotli` and requests its WOFF2-specific `BROTLI_FONT`
mode. `BrotliCompressionProfile::Fast` uses quality 5 for iterative builds;
`Maximum` uses quality 11 for final assets.

`BrotliProcessCompressor` is also available when only the `brotli` executable
is installed. Both adapters avoid exposing process or extension details in the
writer API.

With the process adapter, `write()` copies table and compressed data through
temporary streams in bounded chunks. `dump()` intentionally returns one PHP
string and therefore does not provide the same memory guarantee.

## Unicode subsets

```php
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\UnicodeSet;

$latin = UnicodeSet::fromCss('U+0020-024F');
$excluded = UnicodeSet::fromText('Aabcdefghijklmnopqrstuvwxyz');

$result = $font->subset(new SubsetOptions(
    $latin->without($excluded),
    hinting: HintingPolicy::Drop,
    glyphIds: GlyphIdPolicy::Compact,
    layout: LayoutPolicy::Drop,
));

new Woff2Writer($brotli)->write(
    $result->font,
    __DIR__.'/output/font-latin.woff2',
);
```

`UnicodeSet` also provides immutable `union()`, `intersect()`, and `without()`
operations. They operate directly on normalized ranges rather than expanding
large Unicode blocks into individual values.

The default subsetter is deliberately conservative. It supports static and
variable TrueType `glyf` fonts, retains glyph IDs and axes, closes compound
components and GSUB substitutions, subsets per-glyph `gvar` data, and rebuilds
cmap format 4/12. GPOS, GDEF, and the remaining variable tables stay valid
through stable glyph IDs.

`GlyphIdPolicy::Compact` is an opt-in, fail-closed mode. It currently compacts
the core static TrueType tables, horizontal metrics, compound references, cmap,
PostScript names, and a bounded set of GSUB, GPOS, and GDEF formats. A font
containing variable, vertical, kerning, color, bitmap, mathematical, or another
glyph-indexed table that is not yet rewritten is rejected instead of producing
an invalid font.

`LayoutPolicy::Drop` explicitly removes GSUB substitutions, GPOS positioning,
and GDEF metadata. It is useful for narrowly scoped assets such as digits or
symbols that do not need ligatures, kerning, mark positioning, or complex-script
shaping. The default `Preserve` policy never removes these tables.

`LayoutPolicy::SubstitutionsOnly` compacts supported GSUB substitutions while
removing GPOS positioning. GDEF is retained and compacted because GSUB lookup
flags can depend on its glyph classes and mark sets.

Compact layout currently supports GSUB 1.0 single, multiple, ligature, and
chained-context formats 1/2/3 substitutions; GPOS single and pair positioning,
mark-to-base format 1, and chained-context format 1; and GDEF 1.0/1.2/1.3 class
definitions, attachment points, ligature carets formats 1/2, mark glyph sets,
and a final ItemVariationStore. Extension lookups and ValueRecord device or
VariationIndex offsets are relocated. Other lookup and anchor formats are
rejected until they can be rewritten safely.

For variable TrueType fonts, compact mode remaps per-glyph `gvar` blocks and
`HVAR` delta-set mappings while preserving axes, `avar`, `STAT`, `MVAR`, and
`cvar` when hinting is retained. It does not create a static instance or reduce
axis ranges. Private `meta` data is removed because its glyph references cannot
be inferred safely.

Hinting is preserved by default. `HintingPolicy::Drop` removes glyph
instructions plus `cvar`, `cvt `, `fpgm`, `prep`, `hdmx`, `LTSH`, and `VDMX`.

A selected `withVariations()` view is not a static instance and cannot be
written or subset. Use `withoutVariations()` to return to the variable source;
full axis pinning remains a separate feature.
