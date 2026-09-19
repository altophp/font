# Find a font

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
font's metadata, not its filename.

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

**A variable result with selected coordinates cannot be written or subsetted.**
Use `withoutVariations()` to work with its original variable source; that
retains all axes rather than exporting only the requested weight. See
[Variable fonts](variations.md).

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

```php
use Alto\Font\FontFinder;

$font = FontFinder::system()->find('Helvetica');

echo null === $font ? "Helvetica is unavailable.\n" : "Helvetica is available.\n";
```

Results depend on the machine. Use a controlled font directory for portable
tests and repeatable builds.

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
