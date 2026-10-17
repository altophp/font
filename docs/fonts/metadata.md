# Metadata

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

How the family and subfamily are chosen among the name records, and how the
weight, style, and stretch of `descriptor()` derive from them, is explained in
[Families](families.md). Dimensions are read through [Metrics](metrics.md),
structural facts such as the face index and the table tags through
[Reading](../files/read.md), and glyph counts through
[Glyph counts](../glyphs/counts.md).
