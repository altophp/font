# Characters

Build the set of characters a subset keeps. The set comes from what your
application displays: its translated strings, the ranges a language needs, or
a `unicode-range` you already use in CSS. `UnicodeSet` holds it as normalized
ranges, and `SubsetOptions` takes it as its first argument.

## Select the characters of your strings

Start from the text itself. Repeated characters produce one entry, and the
input must be valid UTF-8:

```php
use Alto\Font\Subset\UnicodeSet;

$translations = ['Ajouter au panier', 'Votre commande est confirmée.'];

$characters = UnicodeSet::fromText(implode(' ', $translations));
```

`fromText()` selects exactly the codepoints present. It does not add
normalization equivalents: `e` followed by a combining acute accent does not
also select `é`. A text shaper may prefer the composed character when the font
has it, so include both forms when your content can contain either.

## Select ranges

Ranges cover what a literal corpus cannot predict, such as every digit and
punctuation mark, or a whole script block. Write them as codepoints, as
`UnicodeRange` values, or in the CSS `unicode-range` syntax, the same text you
would put in an `@font-face` rule:

```php
use Alto\Font\Subset\UnicodeRange;
use Alto\Font\Subset\UnicodeSet;

$codepoints = UnicodeSet::fromCodepoints([0x20, 0x41, 0x42]);
$ranges = UnicodeSet::fromRanges([
    UnicodeRange::between(0x30, 0x39),
    UnicodeRange::between(0x41, 0x5A),
]);
$css = UnicodeSet::fromCss('U+0020-007E, U+00A0-00FF');
```

Values outside U+0000-U+10FFFF, reversed ranges, and malformed CSS syntax raise
`InvalidUnicodeRangeException`. `fromText()` additionally requires valid UTF-8.

## Compose sets

```php
$latin = UnicodeSet::fromCss('U+0020-024F');
$required = UnicodeSet::fromText('Alto 0123456789');
$excluded = UnicodeSet::fromText('xyz');

$combined = $latin->union($required);
$shared = $latin->intersect($required);
$filtered = $combined->without($excluded);
```

These operations work on normalized ranges without first expanding every
codepoint.

## Inspect a set

```php
if ($filtered->contains(0x41)) {
    echo $filtered->toCss();
}

echo count($filtered);
```

`count()` returns the number of represented codepoints. `isEmpty()` reports
whether the set contains none. Iterating over a `UnicodeSet` yields individual
codepoints and therefore expands the selected ranges during iteration.

Pass the finished set to `SubsetOptions` as shown in
[Create a font subset](../subset.md).

## Unicode set contract

`UnicodeSet` is immutable, countable, and iterable. Its factories normalize
repeated or overlapping codepoints into ordered ranges.

- `fromText(string $text)` selects codepoints from valid UTF-8 text without adding normalization equivalents.
- `fromCodepoints(iterable $codepoints)` selects integer values from U+0000 through U+10FFFF.
- `fromRanges(iterable $ranges)` merges overlapping or adjacent `UnicodeRange` values.
- `fromCss(string $unicodeRange)` parses a non-empty CSS unicode-range list, including supported wildcard notation.
- `union(UnicodeSet $other)` includes values in either set.
- `intersect(UnicodeSet $other)` includes values present in both sets.
- `without(UnicodeSet $other)` removes values present in the other set.
- `contains(int $codepoint)` tests whether one valid codepoint belongs to the set.
- `toCss()` serializes normalized ranges as CSS unicode-range text.

`UnicodeRange::single()` selects one codepoint. `UnicodeRange::between()` uses
inclusive bounds. Invalid bounds, codepoints, or CSS syntax raise
`InvalidUnicodeRangeException`; invalid UTF-8 raises `InvalidTextException`.
