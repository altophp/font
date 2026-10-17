# Glyph names

Use `GlyphName::toUnicode()` when a glyph name is available but a Unicode
character mapping is not. It returns a UTF-8 string without loading a font.

```php
use Alto\Font\Glyph\GlyphName;

GlyphName::toUnicode('eacute');       // "é"
GlyphName::toUnicode('A.swash');      // "A"
GlyphName::toUnicode('f_f_i');        // "ffi"
GlyphName::toUnicode('uni00410301');  // "A\u{0301}"
GlyphName::toUnicode('u1F600');       // "\u{1F600}"
GlyphName::toUnicode('.notdef');      // ""
```

The conversion follows the [Adobe Glyph List specification, version 2.9](https://github.com/adobe-type-tools/agl-specification):

- Remove the first period and everything after it, then split at underscores.
- Resolve each component using the complete Adobe Glyph List.
- Interpret `uni` followed by groups of four uppercase hexadecimal digits
  as BMP scalars, or `u` followed by four to six uppercase hexadecimal digits
  as one Unicode scalar.
- Omit unknown components, malformed hexadecimal names, surrogate values,
  and values above U+10FFFF. Concatenate the remaining components.

For example, `A_unknown_B` returns `AB`. A surrogate anywhere in one `uni`
component discards that entire component: `uni0041D800_B` returns `B`.
Lowercase hexadecimal digits are not accepted. A UTF-16 surrogate pair in a
`uni` name is not a supplementary character; use a single `u` name instead.

The result is not normalized: `fi` maps to U+FB01, while `f_i` maps to two
characters. Private-use scalars and U+0000 are retained. Treat the result as
text data; escape it appropriately when displaying it in a terminal or HTML.

## Zapf Dingbats

Enable the additional ITC Zapf Dingbats mapping only for the font whose
PostScript name is `ZapfDingbats`:

```php
GlyphName::toUnicode('a1', zapfDingbats: true); // "\u{2701}"
GlyphName::toUnicode('a1');                    // ""
```

In this mode the Dingbats list takes precedence, followed by the standard
Adobe mapping and the same Unicode-name rules.

## Scope and cost

This API interprets names you provide. To look up a character in a loaded
font, use [`Font::glyphIdForCodepoint()`](../glyphs.md); PDF encoding
dictionaries and `/ToUnicode` maps belong to the PDF reader.

The two bundled mappings load once, on demand. Caller-provided names are
never cached. Processing is iterative and linear in the name length, with
memory proportional to the input and output; there is no arbitrary name
length cutoff. Callers processing untrusted files should apply their own
input-size limits.

The [bundled data provenance](https://github.com/altophp/font/blob/main/resources/glyph-list/README.md) records
the pinned Adobe revision, license, checksums, and offline regeneration command.
