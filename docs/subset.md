# Subsetting

Subset a font when your application displays a known set of characters: the
strings of a user interface, the labels of a chart, a translated catalog. The
result is a new font that maps only those characters, which is smaller to
serve and faster to load. The source file stays unchanged.

For text you control, the subset is complete by construction. For text typed
by users, such as a comment or a product name, include every character the
content can contain, or serve the complete font for that text: a character
absent from the subset renders as the missing-glyph box or falls back to
another font.

## Subset a font for a translated interface

The characters come from two places: the printable ASCII range, so that
numbers, punctuation, and English fallbacks always work, and the translated
strings of your application, which add the accented letters and symbols your
languages need. Place a font at `fonts/Inter-Regular.ttf` beside `vendor`,
create an `output` directory, and run:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Writer\WoffWriter;

$translations = [
    'Ajouter au panier',
    'Commander',
    'Votre commande est confirmée.',
    'Livraison offerte dès 50 €',
];

$characters = UnicodeSet::fromCss('U+0020-007E')
    ->union(UnicodeSet::fromText(implode(' ', $translations)));

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$result = $font->subset(new SubsetOptions(
    $characters,
    glyphIds: GlyphIdPolicy::Compact,
));

$destination = __DIR__.'/output/inter-ui.woff';
new WoffWriter()->write($result->font, $destination);

printf("%d of %d characters mapped, %d glyphs kept\n", $result->mappedCodepointCount, count($characters), $result->retainedGlyphCount);
printf("Saved %s (%d bytes)\n", basename($destination), filesize($destination));
```

In an application, `$translations` comes from the translation files rather
than a literal. With the bundled Inter Latin sample, the script prints:

```text
98 of 98 characters mapped, 409 glyphs kept
Saved inter-ui.woff (27100 bytes)
```

The complete sample is 31,792 bytes as WOFF. The saving is small here because
the sample is already limited to Latin; a complete Inter face also carries
Greek, Cyrillic, and symbol glyphs, and a subset like this one removes them
all. The 409 kept glyphs are more than the 98 characters because the default
layout policy keeps the glyphs that substitutions can reach, such as ligatures
and alternate figures; [Glyph counts](glyphs/counts.md) explains the numbers.

## Build the character set

`UnicodeSet` holds the selection. It is built from text, from codepoints, from
ranges, or from CSS `unicode-range` syntax, and sets combine with `union()`,
`intersect()`, and `without()`. Accented text has two spellings, `é` as one
codepoint or `e` followed by a combining accent, and a subset must contain the
one your content uses. [Characters](subset/characters.md) covers the
constructors and that pitfall.

## Choose what to keep

The defaults keep the font faithful: original glyph numbers, hinting
instructions, and the layout tables that drive ligatures, kerning, and mark
placement. `GlyphIdPolicy::Compact`, used above, renumbers the glyphs and is
the one option that removes no rendering data; it is refused for a few table
structures that cannot be rewritten. Dropping hinting or layout trades
rendering quality or shaping for bytes. [Policies](subset/policies.md) shows
each option with measured sizes.

## Check the result before shipping

`subset()` returns the new font together with counts and warnings. A requested
character that the source does not map is not an error: it is silently absent
from the result, and `mappedCodepointCount` is the number to compare with
`count($characters)`. [Results](subset/results.md) shows how to list the
missing characters, read the warnings, and measure the output.

Test the generated font with real text in the browser or application that will
use it. Shaping, fallback, and rendering belong to that environment, not to
the package.

## Subset a variable font

Pass the original variable font. The output keeps every axis, with the
variation tables remapped. To subset a view selected with `withVariations()`,
call `withoutVariations()` first. See [Variable fonts](tables/variations.md).

## Subset before embedding in a PDF

```php
use Alto\Font\Font;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$result = $font->subset(new SubsetOptions(
    UnicodeSet::fromText($documentText),
    glyphIds: GlyphIdPolicy::Compact,
));

$bytes = $result->font->toSfnt();
$glyphId = $result->font->glyphIdForCodepoint(0x48);
```

Resolve glyph identifiers and widths from the returned font, because compact
numbering changes them. The PDF writer remains responsible for the subset font
name, the font dictionary, the CID mapping, and the ToUnicode map.
