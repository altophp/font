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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontWeight
{
    public function __construct(public int $value) {}

    public static function normal(): self
    {
        return new self(400);
    }

    public static function bold(): self
    {
        return new self(700);
    }

    public static function fromSubfamily(string $subfamily): self
    {
        $normalized = strtolower($subfamily);

        return match (true) {
            str_contains($normalized, 'thin') => new self(100),
            str_contains($normalized, 'extra light'), str_contains($normalized, 'ultra light') => new self(200),
            str_contains($normalized, 'light') => new self(300),
            str_contains($normalized, 'medium') => new self(500),
            str_contains($normalized, 'semi bold'), str_contains($normalized, 'demi bold') => new self(600),
            str_contains($normalized, 'extra bold'), str_contains($normalized, 'ultra bold') => new self(800),
            str_contains($normalized, 'black'), str_contains($normalized, 'heavy') => new self(900),
            str_contains($normalized, 'bold') => self::bold(),
            default => self::normal(),
        };
    }

    public function css(): string
    {
        return (string) $this->value;
    }
}
