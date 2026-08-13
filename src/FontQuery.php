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

namespace Alto\Font;

use Alto\Font\Descriptor\FontStretch;
use Alto\Font\Descriptor\FontStyle;
use Alto\Font\Descriptor\FontWeight;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontQuery
{
    public function __construct(
        public string $family,
        public ?FontWeight $weight = null,
        public ?FontStyle $style = null,
        public ?FontStretch $stretch = null,
    ) {}

    public static function family(string $family): self
    {
        return new self($family);
    }

    public function weight(int|FontWeight $weight): self
    {
        return new self(
            family: $this->family,
            weight: $weight instanceof FontWeight ? $weight : new FontWeight($weight),
            style: $this->style,
            stretch: $this->stretch,
        );
    }

    public function style(FontStyle $style): self
    {
        return new self($this->family, $this->weight, $style, $this->stretch);
    }

    public function italic(): self
    {
        return $this->style(FontStyle::Italic);
    }

    public function stretch(FontStretch $stretch): self
    {
        return new self($this->family, $this->weight, $this->style, $stretch);
    }

    public function key(): string
    {
        return implode('|', [
            strtolower($this->family),
            null === $this->weight ? '*' : $this->weight->value,
            null === $this->style ? '*' : $this->style->value,
            null === $this->stretch ? '*' : $this->stretch->percentage,
        ]);
    }
}
