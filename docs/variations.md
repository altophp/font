# Variable fonts

A variable font exposes axes such as weight (`wght`) or width (`wdth`). Alto
Font can inspect these axes and return a new `Font` instance at selected
coordinates.

## Inspect the axes

```php
$variations = $font->variations();

if (null === $variations) {
    echo "This is a static font.\n";
} else {
    foreach ($variations->axes as $axis) {
        printf(
            "%s: %g to %g, default %g\n",
            $axis->tag,
            $axis->minimum,
            $axis->maximum,
            $axis->default,
        );
    }
}
```

An axis also exposes its optional human-readable name and whether the font
marks it as hidden. Named instances are available through
`$variations->instances`.

## Select coordinates

```php
$boldCondensed = $font->withVariations([
    'wght' => 700,
    'wdth' => 85,
]);

$coordinates = $boldCondensed->variationCoordinates();
$metrics = $boldCondensed->metrics('A');
```

`withVariations()` is immutable: the original font remains at its default
coordinates. Values outside an axis range are clamped. Unknown axes and calls
on static fonts raise `InvalidFontException`.

The returned object is a read-only variable-font view, not a static instance.
It cannot be written or subsetted. Call `withoutVariations()` to return to the
variable source before those workflows.

You can also build coordinates incrementally:

```php
use Alto\Font\Variation\VariationCoordinates;

$coordinates = VariationCoordinates::defaults($variations)
    ->with('wght', 650)
    ->with('wdth', 90);

$selected = $font->withVariations($coordinates);
```

## Observable results

Selected coordinates affect the data returned by `glyphMetrics()` and
`glyphOutline()` when the corresponding font tables provide variations. Alto
Font applies `avar`, `gvar`, and `HVAR` data for supported `glyf`-based fonts.

The package resolves font data only. It does not select optical sizes from a
CSS context or shape a run of text at the chosen coordinates.
