# Select characters

`UnicodeSet` represents normalized Unicode ranges. It can be created from text,
codepoints, ranges, or CSS unicode-range syntax.

## Select characters from text

```php
use Alto\Font\Subset\UnicodeSet;

$characters = UnicodeSet::fromText('Hello, Alto!');
```

Repeated characters produce one set entry. The input must be valid UTF-8.

`fromText()` selects the codepoints present in the input. It does not add
Unicode normalization equivalents: `A` followed by a combining acute accent
does not also select `Á`. A text shaper may prefer the composed character when
it exists in the source font, so omitting it can change glyph selection or
positioning. Include both forms when preserving that behavior matters.

## Select codepoints and ranges

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

| Method | Result |
| --- | --- |
| `fromText(string $text)` | Select codepoints from valid UTF-8 text without adding normalization equivalents. |
| `fromCodepoints(iterable $codepoints)` | Select integer values from U+0000 through U+10FFFF. |
| `fromRanges(iterable $ranges)` | Merge overlapping or adjacent `UnicodeRange` values. |
| `fromCss(string $unicodeRange)` | Parse a non-empty CSS unicode-range list, including supported wildcard notation. |
| `union(UnicodeSet $other)` | Include values in either set. |
| `intersect(UnicodeSet $other)` | Include values present in both sets. |
| `without(UnicodeSet $other)` | Remove values present in the other set. |
| `contains(int $codepoint)` | Test whether one valid codepoint belongs to the set. |
| `toCss()` | Serialize normalized ranges as CSS unicode-range text. |

`UnicodeRange::single()` selects one codepoint. `UnicodeRange::between()` uses
inclusive bounds. Invalid bounds, codepoints, or CSS syntax raise
`InvalidUnicodeRangeException`; invalid UTF-8 raises `InvalidTextException`.
