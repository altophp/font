# Font discovery

`FontFinder` searches font files and selects the closest face for a family,
weight, style, and stretch query.

## Search application directories

```php
use Alto\Font\FontFinder;

$finder = FontFinder::fromDirectories(
    __DIR__.'/fonts',
    __DIR__.'/vendor-fonts',
);

$font = $finder->get('Inter');
```

Directories are searched recursively. Invalid and unsupported files are
ignored while candidates are inspected.

Use the method matching the absence policy of your application:

```php
$finder->has('Inter');  // bool
$finder->find('Inter'); // Font|null
$finder->get('Inter');  // Font or FontNotFoundException
```

## Select a face

Build an immutable query for more control:

```php
use Alto\Font\Descriptor\FontStretch;
use Alto\Font\FontQuery;

$query = FontQuery::family('Inter')
    ->weight(700)
    ->italic()
    ->stretch(new FontStretch(100));

$font = $finder->get($query);
```

The finder prefers exact static faces. A variable font can satisfy `wght` and
`wdth` requests when it exposes those axes; the returned `Font` already
contains the selected coordinates.

For simple calls, weight and style can be passed directly:

```php
use Alto\Font\Descriptor\FontStyle;

$font = $finder->get('Inter', weight: 700, style: FontStyle::Italic);
```

## Search system fonts

```php
$finder = FontFinder::system();
$font = $finder->find('Helvetica');
```

System availability differs between machines. Do not rely on a system font in
portable tests or deterministic builds; provide a controlled font directory
instead.

## Provide another source

Implement `FontLocatorInterface` when paths come from an application index or
another non-directory source:

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

The locator yields local file paths. Loading and candidate caching remain the
finder's responsibility.
