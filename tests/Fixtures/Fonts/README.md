# Font fixtures

`Inter-Regular-latin.woff2` uses the license in `LICENSE-INTER.txt`.

The following unmodified Noto fonts were downloaded from
[`notofonts/noto-fonts` at ffebf8c1ee449e544955a7e813c54f9b73848eac](https://github.com/notofonts/noto-fonts/tree/ffebf8c1ee449e544955a7e813c54f9b73848eac).
Their SIL Open Font License and copyright notice are in `LICENSE-NOTO.txt`,
copied from that revision's `LICENSE`. Tests do not download anything.

| Fixture | Upstream path | SHA-256 |
| --- | --- | --- |
| `NotoNaskhArabic-Regular.ttf` | `hinted/ttf/NotoNaskhArabic/NotoNaskhArabic-Regular.ttf` | `2f4b88e6ee50fa82c617e2d1d4ba18281cb1c6cd71c3af3ec64970c23995db4b` |
| `NotoSansDevanagari-Regular.ttf` | `hinted/ttf/NotoSansDevanagari/NotoSansDevanagari-Regular.ttf` | `385e78e6359a9d88a0f243d53b1209d7548361ba2194e2b9ec779bcaa7e8949d` |

Noto Naskh Arabic exercises right-to-left joining, lam-alef substitution and
combining marks. Noto Sans Devanagari exercises conjuncts, pre-base vowel
reordering and reph. These are bounded regression samples, not exhaustive
language coverage.

## Bounded CJK and production variable samples

These renamed derivatives come from
[`google/fonts` at `1ac2012c34919f5fa2675aacf723fa98edb30b5f`](https://github.com/google/fonts/tree/1ac2012c34919f5fa2675aacf723fa98edb30b5f).
They are deliberately small regression samples, not the complete upstream
fonts. Copyright and OFL notices are preserved in the font name tables and
the accompanying license files.

| Fixture | Source path | Derivation | License |
| --- | --- | --- | --- |
| `AltoCorpusCJK.ttf` | `ofl/notosansjp/NotoSansJP[wght].ttf` | 101 glyphs; weight 400 frozen; `BASE` dropped; Japanese horizontal/vertical layout retained | `LICENSE-NOTO-JP.txt` |
| `AltoCorpusVariable.ttf` | `ofl/recursive/Recursive[CASL,CRSV,MONO,slnt,wght].ttf` | 162 glyphs; all five axes, marks, HVAR, MVAR and 11 GSUB feature-variation records retained | `LICENSE-RECURSIVE.txt` |

Dropping `BASE` is an explicit fixture boundary: compact baseline-table
rewriting remains unsupported. Freezing the CJK weight is performed only by
FontTools when preparing this fixture; it does not imply ALTO static-instance
export support. The modified font family names are `Alto Corpus CJK` and
`Alto Corpus Variable`.

| File | SHA-256 |
| --- | --- |
| Upstream `NotoSansJP[wght].ttf` | `c2f3b4d463500a2ddcd3849cded1fceeb9fd6d1c32e6cbecd568453ba50fc68f` |
| Upstream `Recursive[CASL,CRSV,MONO,slnt,wght].ttf` | `653221ca467f4732fe6856ac493f6c409e9f56a7674abe36b2364acc89796f7c` |
| `AltoCorpusCJK.ttf` | `209b914f69968dafc05b12a362b63478d1f6aafb32cc81cfb944940f3fb52c5d` |
| `AltoCorpusVariable.ttf` | `79c9790a51e9ad290847f5fc2f01b641642bec489bfd4582f1005cbbd285e59e` |

To reproduce, download the two source paths from that revision into a local
directory as `NotoSansJP.ttf` and `Recursive.ttf`, install the pinned validation
requirements, then run:

```sh
/tmp/alto-font-validation/bin/python tests/Validation/corpus_recipe.py \
    /tmp/upstream-fonts /tmp/reproduced-fonts
```

The recipe checks original checksums, runs OTS on each unmodified source,
selects the exact retained text with FontTools, applies the stated CJK
restrictions, renames the families and fixes timestamps. Tests use the
checked-in files and never download fonts.
