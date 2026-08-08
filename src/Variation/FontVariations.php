<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Font\Variation;

use Alto\Font\Exception\InvalidFontException;

final readonly class FontVariations
{
    /**
     * @var array<string, VariationAxis>
     */
    private array $axesByTag;

    /**
     * @param list<VariationAxis>     $axes
     * @param list<VariationInstance> $instances
     */
    public function __construct(
        public array $axes,
        public array $instances = [],
    ) {
        $axesByTag = [];

        foreach ($axes as $axis) {
            if (isset($axesByTag[$axis->tag])) {
                throw new InvalidFontException(\sprintf('Duplicate variation axis "%s".', $axis->tag));
            }

            if ($axis->minimum > $axis->default || $axis->default > $axis->maximum) {
                throw new InvalidFontException(\sprintf('Variation axis "%s" has invalid min/default/max values.', $axis->tag));
            }

            $axesByTag[$axis->tag] = $axis;
        }

        $this->axesByTag = $axesByTag;
    }

    public function hasAxis(string $tag): bool
    {
        return isset($this->axesByTag[$tag]);
    }

    public function axis(string $tag): VariationAxis
    {
        return $this->axesByTag[$tag]
            ?? throw new InvalidFontException(\sprintf('Variation axis "%s" is not defined by this font.', $tag));
    }

    public function defaults(): VariationCoordinates
    {
        $values = [];

        foreach ($this->axes as $axis) {
            $values[$axis->tag] = $axis->default;
        }

        return new VariationCoordinates($values);
    }
}
