# Loading font files

Start with the location of the font. Load a known path directly, or ask
`FontFinder` to select the closest file for a family and style request.

## Load a known file

```php
use Alto\Font\Font;

$font = Font::fromFile(__DIR__.'/fonts/Inter-Regular.ttf');
```

ALTO Font detects the container from its signature instead of trusting the
file extension. A TTC or OTC file can contain several faces; pass a zero-based
`faceIndex` to select one.

Read [Formats](../formats.md) for supported containers, outlines, collections, and
optional WOFF2 requirements.

## Find a matching font

```php
use Alto\Font\FontFinder;
use Alto\Font\FontQuery;

$finder = FontFinder::fromDirectories(__DIR__.'/fonts');
$font = $finder->get(
    FontQuery::family('Inter')->weight(700)->italic(),
);
```

The finder can search application directories, system fonts, or paths supplied
by a custom locator. It returns the best matching `Font`; it does not expose a
public catalog of every discovered file.

Read [Discovery](discovery.md) for matching rules, absence policies, system
fonts, and custom locators.

## Read the loaded face

Once a file or collection face is loaded, continue with [Metadata](metadata.md),
[Glyph metrics](glyphs.md), or [Variable fonts](variations.md). Use the
[Font API reference](../inspect.md) to look up an exact signature or return type.

## When a font will not load

Check the path and read permissions, then the [supported formats](../formats.md).
An `.otf` extension does not identify its outlines: CFF/CFF2 outlines and WOFF2
collections are unsupported. Renaming a file does not make it compatible.

`InvalidFontException` indicates malformed data or an invalid collection face
index. `UnsupportedFontException` indicates an unsupported feature. Select an
existing face or use a supported source font, according to the exception.
