# Read a font

Load a font from a path or from bytes, then read the face it contains. Loading
never modifies the source.

## Load a file

Pass the path of a TrueType, OpenType, WOFF, or WOFF2 file:

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
```

ALTO Font detects the container from its signature instead of trusting the
file extension. A TTC or OTC collection contains several faces; pass a
zero-based `faceIndex` to select one:

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Collection.ttc', faceIndex: 1);
```

Standalone files accept only index zero. Read [Formats](../formats.md) for the
supported containers and outlines, and [Find](find.md) to select a file by
family, weight, and style instead of a path.

## Load from memory

When the font bytes come from a database, an upload, or another document,
load them without writing a temporary file:

```php
use Alto\Font\Font;

$font = Font::fromBytes($bytes);
```

`Font::fromBytes(string $data, int $faceIndex = 0)` uses the same parser and
the same signature detection as `fromFile()`. `face()->path` is `<memory>`.
WOFF2 bytes keep their Brotli requirement and may use the configured
process-backed decompressor.

## Read the loaded face

A `Font` represents one loaded face. These methods expose its data:

| Signature | Contract |
| --- | --- |
| `Font::fromFile(string\|Stringable $file, int $faceIndex = 0): Font` | Read a local file immediately. Detect its container from the bytes. Select a zero-based face in a TTC or OTC collection. |
| `Font::fromBytes(string $data, int $faceIndex = 0): Font` | Load a supported container from memory. |
| `face(): FontFace` | Structural data for the loaded face: original path, container, dimensions, glyph count, and table tags. |
| `metadata(): FontMetadata` | Names, version, license fields, and detected format. See [Metadata](../fonts/metadata.md). |
| `descriptor(): FontDescriptor` | Family, weight, style, and stretch used by font discovery. This does not implement the full CSS font-matching algorithm. |
| `metrics(): FontMetrics` | Declared default-instance bounds and global metrics. See [Metrics](../fonts/metrics.md). |

`FontFace` is readonly. Its public properties are:

| Properties | Meaning |
| --- | --- |
| `path: string`, `format: FontFormat` | Source label and detected container. |
| `faceIndex: int`, `faceCount: int` | Selected zero-based face and number of faces in the source. |
| `unitsPerEm: int`, `ascender: int`, `descender: int` | Design-unit dimensions. Divide a measurement by `unitsPerEm` and multiply by the target size to scale it. |
| `glyphCount: int` | Number of glyph slots, including empty slots retained by preserve-mode subsetting. |
| `tables: list<string>` | Available table tags, not raw table bytes. |
| `names: array<int, string>` | Decoded name fields keyed by name ID. `name(int $nameId): ?string` returns a value or `null`. |

Some table validation occurs when the requested data is accessed, so a
successful load does not guarantee that every later operation succeeds.

## When loading fails

Missing, unreadable, empty, or malformed files and invalid face selections
raise `InvalidFontException`. Valid but unsupported structures, such as CFF
outlines, raise `UnsupportedFontException`. WOFF2 decompression problems raise
`CompressionException`. [Errors](../errors.md) describes each exception;
[Troubleshooting](../troubleshooting.md#the-font-will-not-load) covers the
usual causes.

## Next

Continue with [Glyphs](../glyphs.md) to map characters and measure them,
[Variable fonts](../tables/variations.md) to select a weight, or
[Subset](subset.md) to keep only the characters you need.
