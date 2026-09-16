# Font validation

These integration tests use OpenType Sanitizer (OTS) and HarfBuzz to check
generated fonts independently of ALTO's parser. They are excluded from the
default unit suite and run in the `opentype-validation` CI job.

## Run locally

Install `hb-shape` (Homebrew's `harfbuzz` or Debian/Ubuntu's
`libharfbuzz-bin`) and enable PHP's Brotli extension. Install the Python
dependencies in a virtual environment outside the checkout:

```sh
python3 -m venv /tmp/alto-font-validation
/tmp/alto-font-validation/bin/python -m pip install -r tests/Validation/requirements.txt
OTS_PYTHON=/tmp/alto-font-validation/bin/python composer validate-opentype
```

`OTS_PYTHON` selects Python, `OTS_SANITIZER` optionally selects a standalone
OTS executable, and `HB_SHAPE` optionally selects the HarfBuzz executable.
Missing tools fail the validation suite instead of silently skipping it.
Python packages and external executables are test dependencies only.
Caret-position checks also load the HarfBuzz shared library installed by those
packages, using Python's standard-library `ctypes` module.

## Coverage

- Complete Inter fonts and subsets with preserved or compact glyph IDs,
  preserved layout, substitutions only, or dropped layout.
- Noto Naskh Arabic and Noto Sans Devanagari compact subsets: right-to-left
  joining and marks, conjuncts and vowel reordering. The source must apply
  script substitutions and contain no missing glyphs for the test text.
- A bounded Japanese sample checks horizontal and vertical layout, kana
  marks and vertical punctuation. It excludes `BASE` and uses a frozen weight.
- A bounded production Recursive sample retains all five axes and GSUB
  feature variations. Checks cover defaults, all-axis extremes, intermediate
  coordinates and each axis independently, including conditional substitutions
  and combining marks. FontTools evaluates variable outlines at each tested
  location; a negative oracle removes `gvar` and must change the result.
- Legacy kerning and vertical metrics.
- Two deterministic synthetic fonts with 12,001 glyphs: one forces PairPos
  format 2 row splitting; the other forces format 1 fallback and splitting
  of glyph-pair records, with a Device adjustment at 12 ppem.
- Two additional PairPos format 1 fixtures exercise splitting an existing
  oversized PairSet and a near-limit Device-bearing PairSet. They check both
  value records, first-match precedence and fallback to later source subtables
  at 0, 12 and 13 ppem, with kerning enabled and disabled.
- A small layout font exercises contextual, reverse, multiple, alternate and
  ligature substitutions, pair and contextual positioning, cursive attachment,
  and mark-to-base, mark-to-ligature and mark-to-mark attachment.
- A variable vertical font shares VVAR mapping and variation-data tables.
  Top-to-bottom shaping is compared at four weights, including an intermediate
  coordinate, after unused glyphs are removed.
- GDEF format 3 caret coordinates are checked directly through HarfBuzz's
  ligature-caret API, at three ppem sizes and four variable-font weights.
  Fixtures share Device/VariationIndex data and place the GDEF store before
  the other subtables and the HVAR mapping before its store. OTS validates
  the sources and every output container before caret comparison.
- HarfBuzz comparisons of clusters, glyphs, advances and offsets at 0, 12,
  and 13 ppem, with layout features enabled and disabled. The overflow fonts
  retain all glyphs, so IDs are compared directly. Other comparisons use
  outline geometry because compaction renumbers their glyphs.

The synthetic source fonts are built with FontTools, using explicit GPOS bytes
for the overflow cases, independently of the compactor. Every source must
pass OTS before its outputs are checked. The shaping oracle verifies that
source layout features, vertical variation and the Device adjustment actually
apply, so an inactive fixture cannot silently pass.

The class-row fixture uses an explicit second-glyph class because HarfBuzz
8.3, shipped by Ubuntu 24.04, ignores second-glyph class 0. It still forces
PairPos format 2 row splitting; the fallback fixture exercises first-glyph
class 0. Source/output comparisons and source-activity assertions remain
strict on both older and current HarfBuzz versions.

Each generated TTF, WOFF and WOFF2 file must pass OTS. HarfBuzz reads the
sanitized SFNT output: it need not support webfont containers directly, and
FontTools is not used to reconstruct transformed WOFF2 shaping metrics.
Separately, direct FontTools decoding compares every emitted outline and its
flags against the source, including exact cmap associations, before OTS
normalization. This catches loss of `OVERLAP_SIMPLE`: OTS 9.2.0 preserves that
flag in TTF input but drops it when decoding WOFF2, even when the WOFF2 bitmap
is correct. The shaping geometry oracle therefore uses decomposed drawing
commands; the independent raw-outline check retains the flags comparison.
Comparisons
cover both sides of the glyph-pair split and the last glyph in the font.

Fonts are generated in temporary directories and removed after each test.
No system fonts or proprietary fixtures are required. Fixture provenance,
checksums and license locations are in
[`../Fixtures/Fonts/README.md`](../Fixtures/Fonts/README.md).

When adding a corpus font, validate the original first. A malformed source
must not be treated as evidence of a subsetting regression. Select any
composed and decomposed Unicode forms needed by the source shaper; Unicode
normalization closure is not part of `UnicodeSet::fromText()`.

## Local rendering and measurements

Generate a browser page, source/compact fonts and a machine-readable report:

```sh
php tests/Validation/prepare_corpus_smoke.php /tmp/alto-font-browser-smoke
python3 -m http.server 8793 --bind 127.0.0.1 --directory /tmp/alto-font-browser-smoke
```

Open `http://127.0.0.1:8793/` in Chromium. The page exposes
`window.smokeResult` and fails explicitly on font loading errors, empty
rendering, different source/output canvas pixels or inactive variable
coordinates. It compares CJK, Arabic, Devanagari and three Recursive instances
across TTF, WOFF and WOFF2. Each canvas contains 16, 32 and 48 pixel rows.
The reported count is canvas comparisons, not a count of independent fonts.
This is local loading/rasterization evidence, separate from HarfBuzz shaping
and vertical-layout checks; it is not yet a cross-browser CI gate.

On Chromium 153/macOS in the local validation run, CJK, Arabic and Devanagari
source/output canvases were identical. All three compact Recursive containers
matched one another but differed from their source. At the default instance,
586 alpha pixels differed only in the 16-pixel row; the 32/48-pixel rows,
advances and bounds matched. An independently generated FontTools subset
reproduced the default and intermediate differences exactly. At the tested
corner, the FontTools and ALTO subset canvases differed in five pixels. These
observations do not establish an ALTO-specific regression or explain the
rasterizer's internal behavior. The smoke report keeps all differences visible
and reports `failed`; it does not relax pixel equality or claim identical rasterization.
The internal rasterizer cause has not been established.

`manifest.json` records three sequential runs per sample: source loading,
compaction, writing each container, output sizes and peak PHP process
allocation. Peak allocation includes the PHP baseline and allocator retention.
Measurements have no pass/fail thresholds and are not a performance claim for
complete CJK fonts or all variable fonts. Generated files stay in the selected
temporary directory.
