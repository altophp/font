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
 * Represents one immutable glyph contour.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Contour
{
    /**
     * @param list<PathCommand> $commands
     */
    public function __construct(public array $commands) {}

    public function transform(
        float $xx,
        float $yx,
        float $xy,
        float $yy,
        float $dx,
        float $dy,
    ): self {
        return new self(array_map(
            static fn(PathCommand $command): PathCommand => $command->transform($xx, $yx, $xy, $yy, $dx, $dy),
            $this->commands,
        ));
    }
}
