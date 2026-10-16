# Adobe glyph-name data

These files come from Adobe's [AGL & AGLFN repository](https://github.com/adobe-type-tools/agl-aglfn)
at revision [`4036a9ca80a62f64f9de4f7321a9a045ad0ecfd6`](https://github.com/adobe-type-tools/agl-aglfn/tree/4036a9ca80a62f64f9de4f7321a9a045ad0ecfd6).

- `glyphlist.txt`: Adobe Glyph List 2.0, 4,281 names, including sequences of scalars.
- `zapfdingbats.txt`: ITC Zapf Dingbats Glyph List 2.0, 201 names.
- The corresponding `.php` files contain the same mappings as UTF-8 strings.

The original text files are unmodified. Each text and generated PHP file
contains Adobe's copyright notice and BSD license. This data is distributed
under those terms, separately from ALTO Font's MIT-licensed implementation.

SHA-256 of the source files:

```text
a3b2f61ced9f3644cc0d4ecde5c59df34ca286c689d9484a43a710a81c466789  glyphlist.txt
f6394e3cb8a447e84a1dad75d4baaf2aa7f45dc104faf369f4720e1a774ef2dc  zapfdingbats.txt
```

Regenerate the PHP files offline, from the repository root:

```sh
php tools/generate-glyph-lists.php
```

The generator is deterministic and retains the source license headers.
`GlyphNameTest` checks every mapping against the original Adobe files.
The runtime loads the generated PHP arrays lazily; it does not parse the text
files or access the network. Keep this directory in distribution archives.
