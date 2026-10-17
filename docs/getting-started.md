# Getting started

Read a font that is already on your machine, then check that it covers the
text you want to display with it. A dozen lines of PHP give you the family,
the style, the glyph count, and the list of missing characters, which is what
you need to know before shipping a font with an application or cutting a
subset from it.

Install the package as described in [Installation](installation.md).

## Read a font installed on your system

Every desktop system ships TrueType fonts. The script below tries the usual
locations and reads the first file that exists. Save it as `font.php` beside
`vendor` and run `php font.php`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\Font;

$candidates = [
    '/System/Library/Fonts/Supplemental/Verdana.ttf',    // macOS
    'C:\Windows\Fonts\verdana.ttf',                      // Windows
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',   // Debian, Ubuntu
    '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf', // Fedora
];

$path = array_find($candidates, static fn (string $candidate): bool => is_file($candidate))
    ?? throw new RuntimeException('No known system font found. Use the path of one of your own TrueType files.');

$font = Font::fromFile($path);
$metadata = $font->metadata();

printf("%s %s, %s, %d glyphs\n", $metadata->family, $metadata->subfamily, $metadata->format->value, $font->face()->glyphCount);
```

On macOS this prints:

```text
Verdana Regular, truetype, 913 glyphs
```

The values come from the file's own tables. Loading reads the file into
memory and never modifies it. If none of the candidates exists, replace the
list with the path of a font you have: a `.ttf` from any foundry works, while
an `.otf` with CFF outlines is refused, see [Formats](formats.md).

## Check that the font covers your text

Add this to the same script. The text is an interface string with accented
letters, an arrow, and a check mark:

```php
use Alto\Font\Subset\UnicodeSet;

$missing = [];
foreach (UnicodeSet::fromText('Résumé → Suivant ✓') as $codepoint) {
    if (null === $font->glyphIdForCodepoint($codepoint)) {
        $missing[] = sprintf('U+%04X', $codepoint);
    }
}

echo [] === $missing ? "Every character is available.\n" : 'Missing: '.implode(', ', $missing)."\n";
```

With Verdana this prints `Missing: U+2192, U+2713`: the font has the accented
letters but neither the arrow nor the check mark. A missing codepoint has no
entry in the font's character map. The check is per character; shaping and
fallback happen in the application that uses the font.

## Where to go next

| You want to | Read |
| --- | --- |
| Read the license, version, or names | [Metadata](fonts/metadata.md) |
| Find a font by family and weight instead of a path | [Finding fonts](files/find.md) |
| Keep only the characters of your application | [Subsetting](subset.md) |
| Serve the font to browsers | [Compression](compress.md) |
| Understand family, face, and glyph | [Fonts](fonts.md) |

If loading fails, [Reading files](files/read.md#when-loading-fails) lists the causes.
