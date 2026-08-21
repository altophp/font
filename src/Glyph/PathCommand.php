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

namespace Alto\Font\Glyph;

/**
 * Represents one neutral glyph path command.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PathCommand
{
    /**
     * @param list<float> $coordinates
     */
    private function __construct(
        public string $type,
        public array $coordinates,
    ) {}

    public static function moveTo(float $x, float $y): self
    {
        return new self('M', [$x, $y]);
    }

    public static function lineTo(float $x, float $y): self
    {
        return new self('L', [$x, $y]);
    }

    public static function quadraticTo(float $controlX, float $controlY, float $x, float $y): self
    {
        return new self('Q', [$controlX, $controlY, $x, $y]);
    }

    public static function closePath(): self
    {
        return new self('Z', []);
    }

    public function transform(
        float $xx,
        float $yx,
        float $xy,
        float $yy,
        float $dx,
        float $dy,
    ): self {
        $transformed = [];

        for ($i = 0; $i < \count($this->coordinates); $i += 2) {
            $x = $this->coordinates[$i];
            $y = $this->coordinates[$i + 1];

            $transformed[] = $xx * $x + $xy * $y + $dx;
            $transformed[] = $yx * $x + $yy * $y + $dy;
        }

        return new self($this->type, $transformed);
    }
}
