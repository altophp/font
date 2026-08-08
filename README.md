# alto/font

Font file reading and metadata for PHP: parse OpenType/TrueType/WOFF/WOFF2,
discover installed fonts, and expose per-glyph facts.

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.4-777bb4.svg)](composer.json)

`alto/font` answers "what is this font, and what are the facts about this
glyph" -- metadata, discovery, per-glyph metrics and outlines. It does not
shape text, does not apply kerning, and does not draw anything. Turning a
string of text into positioned, drawn glyphs is a different package's job
(`alto/svg-font` and friends); this one only reads and reports facts.

```php
use Alto\Font\Font;
use Alto\Font\FontFinder;
use Alto\Font\FontQuery;

$font = Font::fromFile(__DIR__.'/fonts/Inter.ttf');

$font->getDescriptor()->family;      // 'Inter'
$font->face()->unitsPerEm;           // 1000
$font->face()->ascender;             // 968

$glyphId = $font->glyphIdForCodepoint(mb_ord('A'));
$font->glyphMetrics($glyphId)->advanceWidth;
$font->glyphOutline($glyphId);       // raw Contour/PathCommand geometry

$finder = FontFinder::fromDirectories(__DIR__.'/fonts');
$bold = $finder->get(FontQuery::family('Inter')->weight(700));
```

## Features

- `Font::fromFile()` -- load an OpenType/TrueType/WOFF/WOFF2 font file.
- `FontFace` -- `unitsPerEm`, `ascender`, `descender`, `glyphCount`, raw table
  access.
- `FontDescriptor` -- family, subfamily, full name, PostScript name, weight,
  style, stretch, inferred from the `name`/subfamily strings.
- `FontMetadata` -- copyright, manufacturer, designer, version, license
  description and URL, read from the `name` table.
- Per-glyph facts: `glyphIdForCodepoint()`, `glyphMetrics()` (advance width,
  side bearings), `glyphOutline()` (contours as generic move/line/quad
  commands, transformable, not tied to any output format).
- Variable fonts: `withVariations()` resolves `fvar`/`avar`/`gvar`/`HVAR` axes
  and instances into concrete per-instance metrics and outlines.
- Discovery: `FontFinder::system()` / `::fromDirectories($dirs)` / `::fromLocator()`,
  resolving a `FontQuery` (family/weight/style/stretch) to the best-matching `Font`
  via `has()` / `find()` / `get()`. `Locator\FontLocatorInterface` is the one
  injectable seam, for tests or custom/non-directory font sources.

## Format support

| Format | Extension(s) | Support |
| --- | --- | --- |
| TrueType (`glyf` outlines) | `.ttf` | Supported, including compound glyphs |
| OpenType with `glyf` outlines | `.otf` | Supported |
| WOFF v1 | `.woff` | Supported (`ext-zlib`, always available) |
| WOFF2, null-transform tables | `.woff2` | Supported **only** with `ext-brotli` or the `brotli` CLI binary on `PATH` -- without either, throws `UnsupportedFontException` rather than failing silently or partially |
| WOFF2 with transformed `glyf`/`loca` tables | `.woff2` | Not supported yet -- rejects cleanly |
| CFF/CFF2 outlines (Type 2 charstrings) | `.otf` | Not supported yet -- rejects cleanly |
| TrueType/OpenType collections | `.ttc`, `.otc` | Supported: `Font::fromFile($path, faceIndex: $n)` selects a face; `FontFace::$faceIndex`/`$faceCount` report the file's shape |
| Compressed (WOFF2) font collections | `.woff2` | Not supported yet -- rejects cleanly |
| Color glyph tables (`COLR`/`CPAL`, `sbix`, `SVG `, `CBDT`/`CBLC`) | `.ttf`, `.otf` | Not supported yet |
| Variable fonts (`fvar`/`avar`/`gvar`/`HVAR`) | `.ttf`, `.otf`, `.woff`, `.woff2` | Supported for `glyf`-based outlines |

Every unsupported case raises a typed exception (`UnsupportedFontException` or
`InvalidFontException`) instead of degrading silently.

See `ARCHITECTURE.md` for how this package's scope was decided and what moved
where.

## Installation

```bash
composer require alto/font
```

## Development

```bash
composer install
composer check       # phpstan + cs-check + test
composer test        # phpunit only
composer cs-fix       # apply coding-standard fixes
```
