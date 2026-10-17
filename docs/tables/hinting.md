# Hinting

Hinting is the set of instructions a TrueType font carries to adjust its
outlines to the pixel grid at small sizes. The instructions live in each
glyph's `glyf` record and in three shared tables: `fpgm` (the font program),
`prep` (the control value program), and `cvt ` (the control values). `gasp`
tells the renderer at which sizes to apply grid fitting and anti-aliasing,
and `hdmx`, `LTSH`, and `VDMX` cache device-specific metrics derived from it.

Renderers differ in how much of this they use. Some follow the instructions
closely at text sizes, others ignore most of them and rely on their own
anti-aliasing. The visible effect of removing hinting therefore depends on the
platform your users read on, not on the font alone.

## Keep or drop

`HintingPolicy::Keep` is the default and leaves every table above in place.
`HintingPolicy::Drop` removes the glyph instructions and the `cvar`, `cvt `,
`fpgm`, `prep`, `hdmx`, `LTSH`, and `VDMX` tables:

```php
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\SubsetOptions;

$result = $font->subset(new SubsetOptions(
    $characters,
    hinting: HintingPolicy::Drop,
));
```

For the bundled Inter sample, dropping hinting reduces a compact subset from
8,308 to 8,280 bytes. A saving that small does not justify a rendering change;
other fonts, with more instructions, save more. Keep hinting unless you have
compared small text on the devices your application targets.

## Where it applies

Hinting is a property of TrueType outlines and travels with them through every
container: TTF, WOFF, and WOFF2 carry the same tables. Variable fonts add
`cvar`, which varies the control values along the axes; it is dropped together
with the rest. See [Policies](../subset/policies.md) for the other
subset decisions.
