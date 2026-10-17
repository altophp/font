# OpenType

OpenType is the specification behind every container the package reads: the
sfnt structure, the table set, layout features, and variations. In file names,
`.otf` usually means an OpenType font with CFF or CFF2 outlines, whose
signature is `OTTO`. Those outlines are not supported, and loading such a file
raises `UnsupportedFontException`:

```php
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Font;

try {
    $font = Font::fromFile(__DIR__.'/fonts/SourceSerif-Regular.otf');
} catch (UnsupportedFontException $error) {
    echo $error->getMessage(); // CFF/OpenType outlines are not supported.
}
```

## Which `.otf` files work

Only the outlines matter. A font sold as `.otf` but built with TrueType `glyf`
outlines has the `0x00010000` signature, loads normally, and is reported as
`FontFormat::TrueType`. `FontFormat::fromPath()` maps `.otf` to
`FontFormat::OpenType` because that is the usual case, but loading trusts the
signature.

Everything else in the OpenType specification applies equally to both outline
flavors and is supported where documented: the required tables, `GSUB`,
`GPOS`, `GDEF`, `kern`, and the variation tables. See [Tables](../tables.md).

## Writing and subsetting

The package never produces CFF outlines. Writing and subsetting start from a
loaded `glyf` font and produce `glyf` output; see [TrueType](truetype.md).

## Requirements and limits

- An `OTTO` signature raises `UnsupportedFontException` on load.
- A `0x00010000` signature with an `.otf` extension loads as TrueType.
- In an OTC collection with CFF faces, the selected face fails with `UnsupportedFontException`.
- Converting CFF to TrueType is not available; use a `glyf` build of the font.
