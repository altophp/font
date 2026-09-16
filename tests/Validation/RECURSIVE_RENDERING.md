# Recursive rendering diagnosis

The original Chromium/macOS discrepancy has an independent reproducer:
removing glyph names from `post` changes small-size rasterization even when
every outline, variation block, character mapping and metric is unchanged.
The same effect occurs through a direct `CTFontDrawGlyphs` call, without a
browser, HarfBuzz shaping, ALTO or any glyph subsetting.

This establishes a CoreText rasterization dependency on glyph-name data for
this font. It does not establish Apple's internal algorithm or a universal
list of glyphs needed for pixel-identical subsetting.

## Minimal reproducer

With the pinned Python validation dependencies installed, use an empty output
directory:

```sh
/tmp/alto-font-validation/bin/python tests/Validation/recursive_rendering.py \
    /tmp/alto-recursive-reproducer
symfony server:start --dir=/tmp/alto-recursive-reproducer --port=8794 --no-tls --no-workers
```

Open the reported URL. The page compares the single character `A` and the
original phrase, at three variation locations and 16, 32 and 48 pixels.
`window.reproductionResult` contains exact pixel counts and advances. It reports
observations, with no tolerance or assumed result for another platform.

The generator copies the licensed corpus source and creates `post3.ttf` by
replacing only `post` with format 3. It verifies byte equality of every other
table, except the necessary `head.checkSumAdjustment`, and sanitizes both
files with OTS. No ALTO code is involved in generating these two fonts.

The native probe requires macOS and its command-line developer tools:

```sh
xcrun clang -Wall -Wextra -Werror tests/Validation/recursive_coretext.c \
    -framework CoreText -framework CoreGraphics -framework CoreFoundation \
    -o /tmp/alto-recursive-reproducer/coretext
/tmp/alto-recursive-reproducer/coretext \
    /tmp/alto-recursive-reproducer/source.ttf \
    /tmp/alto-recursive-reproducer/post3.ttf
```

The probe draws `A` at 16px with CoreText and counts differing pixels. Its
bitmap setup differs from Chromium's, so its pixel count need not match the
browser count. Compare each renderer against itself.

## Observed results

Local run on 2026-09-16, Chromium 153 and macOS 26.6.2 arm64:

| Operation | Different pixels from source, original phrase at 16px |
| --- | --- |
| ALTO rewrite without subsetting | 0 at the default instance |
| ALTO subset preserving glyph IDs | 855 at the default instance |
| ALTO compact subset | 586 default, 779 axis corner, 462 intermediate |
| Replace only source `post` with format 3 | 586 default, 779 axis corner, 462 intermediate |
| Preserve IDs and additionally retain `X` | 0 at all three tested instances |
| Compact IDs and additionally retain `X` | 586 default, 779 axis corner, 462 intermediate |
| Compact with `X`, then restore a format-2 `post` for its glyph order | 0 at all three tested instances |

The single-character browser reproducer changes 39 pixels for default `A` at
16px, with identical advance widths; its 32px and 48px rows match. The native
probe changes 54 pixels with maximum channel delta 130. Both native fonts have
the same `A` glyph ID and cap height; lookup of the glyph name `X` succeeds only
in the source font.

Restoring removed glyphs one at a time in the preserve-ID subset isolated `X`:
among 101 removed nonempty glyphs, only restoring `X` removed the `A` discrepancy
in this test. Restoring the original `hhea`, `cmap` or `gvar` alone did not fix it.
Keeping `X` without its glyph name is insufficient in compact mode.

The earlier five-pixel FontTools/ALTO corner discrepancy did not reproduce in
fresh controlled comparisons, including the original canvas dimensions and
baseline. Both matched the post-only control at all three tested instances.
That earlier observation is retained as non-reproduced, not attributed to a
new ALTO geometry defect or dismissed by a tolerance.

## Practical boundary

For this specific sample, `GlyphIdPolicy::Preserve` with `X` added to the
requested characters provides a verified 16px workaround. It keeps glyph
names and the glyph used by this renderer. This is not a universal workaround:
additional checks at other sizes still found small pixel differences, and
other fonts or rasterizers may depend on other data.

The production subsetter does not automatically retain `X`, and compact mode
continues to use `post` format 3. Any general option to retain glyph names and
rasterizer-specific dependencies needs a separate support policy. Existing
outline, variation and positioning comparisons stay strict. The original
source/subset browser smoke still reports its pixel differences explicitly.
