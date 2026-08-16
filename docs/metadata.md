# Font metadata

Alto Font exposes three related views of a loaded face. Choose the smallest
one that answers the current question.

## Face metrics

`FontFace` contains structural values used to interpret glyph geometry:

```php
$face = $font->face();

$face->unitsPerEm;
$face->ascender;
$face->descender;
$face->glyphCount;
$face->tables;
```

`tables` contains table tags such as `cmap` and `name`. It does not expose raw
table bytes or provide a public arbitrary-table parser.

Metrics and outlines use design units. For a target size of 16 pixels, a value
can be scaled with `16 / $face->unitsPerEm`.

For collections, `faceIndex` identifies the selected face and `faceCount`
reports the number of faces in the file.

## Matching descriptors

`getDescriptor()` returns the naming and CSS-like characteristics used by
font discovery:

```php
$descriptor = $font->getDescriptor();

echo $descriptor->family;
echo $descriptor->subfamily;
echo $descriptor->weight->css();
echo $descriptor->style->value;
echo $descriptor->stretch->css();
```

Weight, style, and stretch are inferred from the font's subfamily names. They
are useful for selection but do not replace a full CSS font-matching engine.

## Descriptive metadata

`metadata()` includes the descriptor and optional fields from the OpenType
`name` table:

```php
$metadata = $font->metadata();

echo $metadata->family;
echo $metadata->format->value;
echo $metadata->version;
echo $metadata->license;
echo $metadata->licenseUrl;
```

Available optional fields include full and PostScript names, copyright,
manufacturer, designer and vendor details, description, version, and license
information. A missing name-table record is returned as `null`; Alto Font does
not invent a replacement value.

Name selection is not locale-aware. Alto Font currently keeps the first
decodable record for each field instead of selecting by language. Non-ASCII
legacy Mac Roman names may not be transcoded correctly. Applications that need
localized names should treat this metadata as a best available value.

`format` identifies the loaded container signature. It does not describe every
outline or color technology stored inside that container.

The license fields describe the font. They do not grant rights beyond the
license supplied by its publisher.
