# Reading files

Load a font from a path or from bytes, then read the face it contains. Loading
leaves the source unchanged.

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

- `Font::fromFile(string|Stringable $file, int $faceIndex = 0): Font` reads a local file immediately, detects its container from the bytes, and selects a zero-based face in a TTC or OTC collection.
- `Font::fromBytes(string $data, int $faceIndex = 0): Font` loads a supported container from memory.
- `face(): FontFace` returns structural data for the loaded face: original path, container, dimensions, glyph count, and table tags.
- `metadata(): FontMetadata` returns names, version, license fields, and the detected format. See [Metadata](../fonts/metadata.md).
- `descriptor(): FontDescriptor` returns the family, weight, style, and stretch used by font discovery. It does not implement the full CSS font-matching algorithm.
- `metrics(): FontMetrics` returns declared default-instance bounds and global metrics. See [Metrics](../fonts/metrics.md).

`FontFace` is readonly. Its public properties are:

- `path` and `format` hold the source label and the detected `FontFormat`.
- `faceIndex` and `faceCount` hold the selected zero-based face and the number of faces in the source.
- `unitsPerEm`, `ascender`, and `descender` are design-unit dimensions. Divide a measurement by `unitsPerEm` and multiply by the target size to scale it.
- `glyphCount` counts glyph slots, including empty slots retained by preserve-mode subsetting.
- `tables` lists the available table tags, not raw table bytes.
- `names` holds decoded name fields keyed by name ID; `name(int $nameId): ?string` returns one value or `null`.

Some table validation occurs when the requested data is accessed, so a
successful load does not guarantee that every later operation succeeds.

## When loading fails

Missing, unreadable, empty, or malformed files and invalid face selections
raise `InvalidFontException`, and so does a WOFF2 stream that fails to
decompress. Valid but unsupported structures raise `UnsupportedFontException`:
CFF outlines, WOFF2 collections, or a WOFF2 file when neither `ext-brotli`
nor the `brotli` executable is available. An `.otf` extension does not tell
you which outlines the file holds, and renaming a file changes nothing: the
signature decides, see [Formats](../formats.md). [Errors](../errors.md)
describes each exception.

## Next

Continue with [Glyphs](../glyphs.md) to map characters and measure them,
[Variable fonts](../tables/variations.md) to select a weight, or
[Subset](../subset.md) to keep only the characters you need.
