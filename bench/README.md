# Benchmarks

Scripts that measure what ALTO Font costs on real fonts: wall time and memory
for loading, reading, subsetting, and writing. They are not shipped with the
package and are not part of the test suite.

## Run them

Each script takes a font path and an optional face index, and prints one line
per step: the best wall time, the peak memory above the memory in use before
the step, and the memory the step's result still holds.

```bash
php bench/load.php tests/Fixtures/Fonts/NotoNaskhArabic-Regular.ttf
php bench/all.php "/System/Library/Fonts/Supplemental/Arial Unicode.ttf"
php bench/finder.php /System/Library/Fonts/Supplemental Verdana
```

`all.php` runs every per-font script in a separate PHP process, so one
scenario's memory does not leak into the next. Raise `memory_limit` for large
fonts: `php -d memory_limit=1G bench/all.php <font>`.

| Script | Measures |
| --- | --- |
| `load.php` | `Font::fromFile()` |
| `metadata.php` | `metadata()` and `descriptor()` |
| `cmap.php` | `glyphIdForCodepoint()` over printable ASCII and Latin-1 |
| `glyph-metrics.php` | `glyphMetrics()` on every glyph |
| `glyph-outlines.php` | `glyphOutline()` on every glyph |
| `subset.php` | `subset()` of ASCII and Latin-1, four policy combinations |
| `woff.php` | `WoffWriter::dump()`, complete face and Latin subset |
| `woff2.php` | `Woff2Writer::dump()` with the `brotli` executable |
| `finder.php` | `FontFinder::find()` over a directory, first and repeated query |

A step the package refuses for a given font prints `refused` with the reason
instead of a time.

## Reference fonts

The first four ship with the test suite. The others come with macOS, so their
numbers can only be reproduced there.

| Font | Size | Glyphs |
| --- | --- | --- |
| `tests/Fixtures/Fonts/Inter-Regular-latin.woff2` | 23 KB | 518 |
| `tests/Fixtures/Fonts/NotoNaskhArabic-Regular.ttf` | 174 KB | 1,614 |
| `tests/Fixtures/Fonts/NotoSansDevanagari-Regular.ttf` | 214 KB | 954 |
| `tests/Fixtures/Fonts/AltoCorpusVariable.ttf` | 321 KB | 162 |
| `/System/Library/Fonts/Supplemental/Verdana.ttf` | 182 KB | 913 |
| `/System/Library/Fonts/Helvetica.ttc`, face 0 | 2.3 MB | 2,252 |
| `/System/Library/Fonts/Supplemental/Arial Unicode.ttf` | 22.2 MB | 50,377 |

## Baseline

Measured on 2026-10-17 at commit 93cccd3, PHP 8.5.10, Apple silicon, with the
`brotli` 1.2 executable. Time, then peak memory.

| Font | Load | Every outline | Subset, default | WOFF, complete | WOFF2, complete |
| --- | --- | --- | --- | --- | --- |
| Inter | 13 ms, 1.2 MB | 31 ms | 43 ms, 4.0 MB | 3 ms | 141 ms |
| Noto Naskh Arabic | 2 ms, 0.9 MB | 297 ms | 10 ms, 0.9 MB | 11 ms | 410 ms |
| Noto Sans Devanagari | 2 ms, 0.8 MB | 84 ms | 19 ms, 2.4 MB | 9 ms | 452 ms |
| Variable fixture | 2 ms, 1.1 MB | 18 ms | 15 ms, 3.1 MB | 14 ms | 787 ms |
| Verdana | 1 ms, 0.8 MB | 45 ms | 12 ms, 0.8 MB | 10 ms | 423 ms |
| Helvetica | 4 ms, 3.2 MB | 137 ms | refused | 27 ms | 868 ms |
| Arial Unicode | 72 ms, 32.9 MB | 5.66 s | 185 ms, 27.2 MB | 1.63 s, 86.6 MB | 54.4 s, 83.9 MB |

`FontFinder::fromDirectories('/System/Library/Fonts/Supplemental')->find('Verdana')`:
517 ms and 68.7 MB peak for the first query, 0.002 ms for the same query again.
