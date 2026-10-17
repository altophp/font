# Families

A family name groups the faces of one design, such as Inter. A subfamily name
tells the faces apart within it, such as Bold Italic. Both are stored in the
font's `name` table under numeric identifiers, and `metadata()->family` and
`metadata()->subfamily` read them.

## Legacy and typographic names

Early font software grouped at most four faces under one family name: regular,
bold, italic, and bold italic. A design with more weights spreads them over
several legacy families, and each face keeps a legacy subfamily among those
four styles. Typographic names, added later as name IDs 16 and 17, keep the
whole design under one family and move every style word into the subfamily.
ALTO Font prefers the typographic names and falls back to the legacy ones. The
example below is Noto Sans Armenian Black as shipped with macOS.

| Name ID | Field | Example | Read into |
| --- | --- | --- | --- |
| 1 | Legacy family | Noto Sans Armenian Blk | `family`, when 16 is absent |
| 2 | Legacy subfamily | Regular | `subfamily`, when 17 is absent |
| 16 | Typographic family | Noto Sans Armenian | `family` |
| 17 | Typographic subfamily | Black | `subfamily` |
| 4 | Full name | Noto Sans Armenian Black | `fullName` |
| 6 | PostScript name | NotoSansArmenian-Black | `postScriptName` |

Without that preference, the face above would be reported as a regular face of
a family named "Noto Sans Armenian Blk". A face with no usable record reports
`Unknown` and `Regular`. Name IDs 21 and 22, the WWS family and subfamily, are
not read.

## Weight, style, and stretch

`descriptor()` derives a weight, a style, and a stretch from the words of the
subfamily name, compared case-insensitively. The `OS/2` weight and width
classes are not read.

| Subfamily contains | Weight |
| --- | --- |
| Thin | 100 |
| Extra Light, Ultra Light | 200 |
| Light | 300 |
| Medium | 500 |
| Semi Bold, Demi Bold | 600 |
| Bold | 700 |
| Extra Bold, Ultra Bold | 800 |
| Black, Heavy | 900 |
| none of these | 400 |

| Subfamily contains | Stretch |
| --- | --- |
| Ultra Condensed | 50% |
| Extra Condensed | 62% |
| Condensed | 75% |
| Semi Condensed | 87% |
| none of these | 100% |
| Semi Expanded | 112% |
| Expanded | 125% |
| Extra Expanded | 150% |
| Ultra Expanded | 200% |

The style is italic when the subfamily contains "Italic", oblique when it
contains "Oblique", and normal otherwise.

The keywords are matched as written above, with their space. A subfamily
written as one word matches the shorter keyword it contains: "SemiBold" is
read as 700, "ExtraLight" as 300, and "SemiCondensed" as 75%. A word that
matches no keyword, such as "Book", leaves the default.

## Print the names of a face

Place a font at `fonts/Inter-Regular.ttf` and run this script beside `vendor`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$face = $font->face();
$descriptor = $font->descriptor();

printf("Family: %s, subfamily: %s\n", $descriptor->family, $descriptor->subfamily);
printf("Legacy names: %s, %s\n", $face->name(1) ?? 'none', $face->name(2) ?? 'none');
printf("Typographic names: %s, %s\n", $face->name(16) ?? 'none', $face->name(17) ?? 'none');
printf(
    "Weight %s, style %s, stretch %s\n",
    $descriptor->weight->css(),
    $descriptor->style->value,
    $descriptor->stretch->css(),
);
```

The first line shows the names the package chose, the next two show the raw
records they come from, and the last one shows the derived values.

## Match a family

`FontFinder` keeps the candidates whose family equals the requested one,
compared case-insensitively, then picks the closest weight, style, and stretch
among them. On a variable font with a `wght` or `wdth` axis, the requested
weight and stretch select the coordinates of the returned view. See
[Finding](../files/find.md).

## Names and languages

Name selection keeps the first decodable record for each field rather than
selecting by language. Non-ASCII legacy Mac Roman names may not be transcoded
correctly. Applications needing localized names should treat these fields as
best available values.
