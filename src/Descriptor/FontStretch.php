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

namespace Alto\Font\Descriptor;

final readonly class FontStretch
{
    public function __construct(public int $percentage) {}

    public static function normal(): self
    {
        return new self(100);
    }

    public static function fromSubfamily(string $subfamily): self
    {
        $normalized = strtolower($subfamily);

        return match (true) {
            str_contains($normalized, 'ultra condensed') => new self(50),
            str_contains($normalized, 'extra condensed') => new self(62),
            str_contains($normalized, 'semi condensed') => new self(87),
            str_contains($normalized, 'condensed') => new self(75),
            str_contains($normalized, 'semi expanded') => new self(112),
            str_contains($normalized, 'extra expanded') => new self(150),
            str_contains($normalized, 'ultra expanded') => new self(200),
            str_contains($normalized, 'expanded') => new self(125),
            default => self::normal(),
        };
    }

    public function css(): string
    {
        return $this->percentage . '%';
    }
}
