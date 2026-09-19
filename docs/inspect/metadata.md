# Read font metadata

Read the family, style, version, and license fields stored in a font. These
values can populate an asset list or help you identify a file before conversion.

## Print the name and license fields

Place a font at `fonts/Inter-Regular.ttf` and run this script beside `vendor`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
$metadata = $font->metadata();

printf("Family: %s\nStyle: %s\n", $metadata->family, $metadata->subfamily);
printf("Version: %s\n", $metadata->version ?? 'not provided');
printf("License: %s\n", $metadata->license ?? 'not provided');
printf("License URL: %s\n", $metadata->licenseUrl ?? 'not provided');
```

This prints the file's own values. Optional fields return `null` when absent;
the example displays `not provided` instead. The license fields describe the
font; consult the license supplied by its publisher for its terms.

Other optional fields include full and PostScript names, copyright,
manufacturer, designer, designer URL, vendor URL, and description.
`$metadata->format` identifies the loaded file container.

## Read weight and style for matching

Continue the script with:

```php
$descriptor = $font->descriptor();

printf(
    "Weight: %s, style: %s, stretch: %s\n",
    $descriptor->weight->css(),
    $descriptor->style->value,
    $descriptor->stretch->css(),
);
```

For a regular face, values typically describe weight 400, normal style, and
100% stretch. They are inferred from subfamily names and are used by
[font discovery](discovery.md); they do not implement the full CSS matching rules.

## Read dimensions and glyph counts

`$font->face()` exposes `unitsPerEm`, `ascender`, `descender`, and `glyphCount`.
Dimensions use font design units. To convert a value to a target size of 16
pixels, multiply it by `16 / $font->face()->unitsPerEm`.

For collections, `faceIndex` identifies the selected face and `faceCount`
reports the number of faces. `tables` lists OpenType table tags; it does not
expose raw table bytes. See [Glyphs](glyphs.md) for individual measurements.

## Names and languages

Name selection keeps the first decodable record for each field rather than
selecting by language. Non-ASCII legacy Mac Roman names may not be transcoded
correctly. Applications needing localized names should treat these fields as
best available values.
