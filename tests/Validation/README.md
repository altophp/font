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
- Legacy kerning and vertical metrics.
- Two deterministic synthetic fonts with 12,001 glyphs: one forces PairPos
  format 2 row splitting; the other forces format 1 fallback and splitting
  of glyph-pair records, with a Device adjustment at 12 ppem.
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
FontTools is not used to reconstruct transformed WOFF2 metrics. Comparisons
cover both sides of the glyph-pair split and the last glyph in the font.

Fonts are generated in temporary directories and removed after each test.
No system fonts or proprietary fixtures are required. Fixture provenance,
checksums and license locations are in
[`../Fixtures/Fonts/README.md`](../Fixtures/Fonts/README.md).

When adding a corpus font, validate the original first. A malformed source
must not be treated as evidence of a subsetting regression. Select any
composed and decomposed Unicode forms needed by the source shaper; Unicode
normalization closure is not part of `UnicodeSet::fromText()`.
