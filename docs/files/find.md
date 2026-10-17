# Finding fonts

Search a directory when you know the family and style you want but not its
filename. `FontFinder` returns the closest matching face within that family.

## Find a family in your application

Put your font files in `fonts`, then run this script beside `vendor`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Font\FontFinder;

$finder = FontFinder::fromDirectories(__DIR__.'/fonts');
$font = $finder->find('Inter');

if (null === $font) {
    echo "Inter was not found in fonts.\n";
} else {
    printf("Found %s %s\n", $font->metadata()->family, $font->metadata()->subfamily);
}
```

With Inter Regular present, this prints `Found Inter Regular`. Otherwise the
script reports that the family is absent. The family name comes from the
font's metadata, not its filename, and is compared case-insensitively; see
[Families](../fonts/families.md).

Directories are searched recursively. Pass several paths to
`fromDirectories()` to search multiple application directories.

## Request a weight and style

Continue the script with:

```php
use Alto\Font\FontQuery;

$font = $finder->find(FontQuery::family('Inter')->weight(700)->italic());

if (null !== $font) {
    printf("Selected %s %s\n", $font->descriptor()->family, $font->descriptor()->subfamily);
}
```

The result is the closest match, so check its descriptor if you require an
exact style. The finder prefers exact static faces. A variable font can satisfy
weight or width requests through its axes.

A variable result with selected coordinates cannot be written or subsetted.
Use `withoutVariations()` to work with its original variable source; that
retains all axes rather than exporting only the requested weight. See
[Variable fonts](../tables/variations.md).

For more query options, use `stretch(new FontStretch(100))`, or pass weight and
style directly to `get()` or `find()` with `FontStyle` values.

## Choose how absence is handled

| Method | Result |
| --- | --- |
| `has('Inter')` | A boolean |
| `find('Inter')` | A `Font`, or `null` when no family matches |
| `get('Inter')` | A `Font`, or `FontNotFoundException` |

Invalid and unsupported candidate files are skipped. Inspect the failures
after searching if an expected font was not found:

```php
foreach ($finder->diagnostics() as $path => $exception) {
    printf("Skipped %s: %s\n", $path, $exception->getMessage());
}
```

## Search system fonts

`FontFinder::system()` searches the standard font directories of the current
operating system. A family name alone returns its regular face; add a weight
or a style to the query for another one.

```php
use Alto\Font\FontFinder;

$font = FontFinder::system()->find('Verdana');

echo null === $font ? "Verdana is unavailable.\n" : "Found {$font->face()->path}\n";
```

The first query parses every candidate file once and keeps only its names and
axes; later queries on the same finder answer from that cache. On a Mac with
1,249 font files in the four directories, the first query took about 5
seconds, with a memory peak set by the largest file parsed (200 MB with the
Apple Color Emoji collection). Use it in a one-off script or a console
command, not in a web request, and prefer a directory you control for builds
and tests.

### Scanned directories

`FontLocator::standardSystemDirectories()` returns the directories below that
exist on the current machine. Each is scanned recursively for `.ttf`, `.otf`,
`.woff`, `.woff2`, `.ttc`, and `.otc` files.

- macOS: `/System/Library/Fonts`, `/System/Library/Fonts/Supplemental`, `/Library/Fonts`, `~/Library/Fonts`, and `/Network/Library/Fonts`.
- Linux: `/usr/share/fonts`, `/usr/local/share/fonts`, `~/.local/share/fonts`, and `~/.fonts`.
- Windows: `%WINDIR%\Fonts` and `%LOCALAPPDATA%\Microsoft\Windows\Fonts`.

The package does not know which fonts a system ships, only where to look.
Fonts bundled with an application belong in its own directory, passed to
`FontFinder::fromDirectories()`, so that builds do not depend on the machine.

## Provide paths from another source

Implement `FontLocatorInterface` when your application already has a list of
local font paths:

```php
use Alto\Font\FontFinder;
use Alto\Font\Locator\FontLocatorInterface;

$locator = new class implements FontLocatorInterface {
    public function fonts(): iterable
    {
        yield __DIR__.'/fonts/Inter-Regular.ttf';
        yield __DIR__.'/fonts/Inter-Bold.ttf';
    }
};

$finder = FontFinder::fromLocator($locator);
```

The finder loads and caches candidates. It searches the first face of each
TTC/OTC file; load another face explicitly with
`Font::fromFile($path, faceIndex: 1)`.
The finder selects a match rather than exposing a public catalog of every file.
