# Metrics

Use `Font::metrics()` to read a font's declared dimensions: units per em,
line metrics, bounds, and embedding flags. Use
[`glyphMetrics()`](../glyphs/metrics.md) for one glyph's advance and side
bearing. Both return unscaled font design units.

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$metrics = $font->metrics();
$scale = 1000 / $metrics->unitsPerEm;

$bounds = [
    $metrics->bounds->xMin * $scale,
    $metrics->bounds->yMin * $scale,
    $metrics->bounds->xMax * $scale,
    $metrics->bounds->yMax * $scale,
];
```

Validate that `unitsPerEm` is positive before scaling untrusted font data.
`FontMetrics` and `Geometry\BoundingBox` are immutable value objects.

## Values and missing information

| Property | Source | Meaning |
| --- | --- | --- |
| `unitsPerEm` | `head` | Units defining one em |
| `ascender`, `descender`, `lineGap` | `hhea` | Declared horizontal line metrics; descender is signed |
| `bounds` | `head` | `xMin`, `yMin`, `xMax`, `yMax` in font units |
| `capHeight`, `xHeight` | `OS/2` version 2 or newer | Declared heights, or `null` for missing/zero values |
| `italicAngle` | `post` | Signed degrees from vertical; zero means upright |
| `isFixedPitch` | `post` | Whether the declared fixed-pitch value is nonzero |
| `embeddingFlags` | `OS/2.fsType` | Raw unsigned bit field, including any reserved bits |

Absent optional tables produce `null` values. A zero angle, a proportional
font (`false`), and zero embedding flags remain distinguishable from missing
data. OS/2 versions 0 and 1 do not supply cap/x heights, even if trailing bytes
are present. Truncated fields throw `InvalidFontException` when read.

Every value is the font's own declaration, read from fixed table prefixes of
at most 176 bytes: bounds are the declared control-point bounds, heights come
from `OS/2` when it provides them, and embedding flags are the raw `fsType`
bits for your workflow to interpret. After subsetting, read the metrics of
the resulting font.

The metrics describe the default font instance. `metrics()` on a view
selected with `withVariations()` throws `UnsupportedFontException`: instance
bounds and MVAR-adjusted metrics are not implemented. `withoutVariations()`
returns access to the original default-instance values.

Field definitions: OpenType [head](https://learn.microsoft.com/en-us/typography/opentype/spec/head),
[OS/2](https://learn.microsoft.com/en-us/typography/opentype/spec/os2), and
[post](https://learn.microsoft.com/en-us/typography/opentype/spec/post).

To load the font from bytes instead of a file, see [Read](../files/read.md#load-from-memory).
