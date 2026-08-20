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

use Alto\Font\Variation\Table\AvarTable;

/**
 * Stores immutable user coordinates for variation axes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class VariationCoordinates
{
    /**
     * @param array<string, float|int> $values
     */
    public function __construct(public array $values) {}

    public static function defaults(FontVariations $variations): self
    {
        return $variations->defaults();
    }

    public function with(string $axisTag, float $value): self
    {
        return new self([...$this->values, $axisTag => $value]);
    }

    public function value(string $axisTag, FontVariations $variations): float
    {
        $axis = $variations->axis($axisTag);
        $value = $this->values[$axisTag] ?? $axis->default;

        return $axis->clamp((float) $value);
    }

    public function resolve(FontVariations $variations): self
    {
        foreach ($this->values as $tag => $_) {
            $variations->axis($tag);
        }

        $values = [];

        foreach ($variations->axes as $axis) {
            $values[$axis->tag] = $this->value($axis->tag, $variations);
        }

        return new self($values);
    }

    public function normalized(FontVariations $variations, ?AvarTable $avar = null): NormalizedCoordinates
    {
        $resolved = $this->resolve($variations);
        $values = [];

        foreach ($variations->axes as $axis) {
            $normalized = self::normalizeAxis($axis, (float) $resolved->values[$axis->tag]);
            $values[$axis->tag] = $avar?->map($axis->tag, $normalized) ?? $normalized;
        }

        return new NormalizedCoordinates($values);
    }

    private static function normalizeAxis(VariationAxis $axis, float $value): float
    {
        if ($value < $axis->default) {
            return max(-1.0, -($axis->default - $value) / ($axis->default - $axis->minimum));
        }

        if ($value > $axis->default) {
            return min(1.0, ($value - $axis->default) / ($axis->maximum - $axis->default));
        }

        return 0.0;
    }
}
