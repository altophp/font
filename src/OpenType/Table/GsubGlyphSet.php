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

namespace Alto\Font\OpenType\Table;

use Alto\Font\Exception\InvalidFontException;

/**
 * A glyph set that can represent class zero without eagerly knowing numGlyphs.
 *
 * @internal
 */
final readonly class GsubGlyphSet
{
    /**
     * @param array<int, true>|null $glyphs
     * @param array<int, true>      $excluded
     */
    private function __construct(
        private ?array $glyphs,
        private array $excluded = [],
    ) {}

    /**
     * @param iterable<int> $glyphs
     */
    public static function explicit(iterable $glyphs): self
    {
        $set = [];

        foreach ($glyphs as $glyphId) {
            $set[$glyphId] = true;
        }

        return new self($set);
    }

    /**
     * @param array<int, int> $classesByGlyph
     * @param iterable<int>   $coverage
     */
    public static function fromClass(array $classesByGlyph, int $classId, ?iterable $coverage = null): self
    {
        if (null !== $coverage) {
            $glyphs = [];

            foreach ($coverage as $glyphId) {
                if (($classesByGlyph[$glyphId] ?? 0) === $classId) {
                    $glyphs[$glyphId] = true;
                }
            }

            return new self($glyphs);
        }

        if (0 === $classId) {
            $excluded = [];

            foreach ($classesByGlyph as $glyphId => $assignedClass) {
                if (0 !== $assignedClass) {
                    $excluded[$glyphId] = true;
                }
            }

            return new self(null, $excluded);
        }

        $glyphs = [];

        foreach ($classesByGlyph as $glyphId => $assignedClass) {
            if ($assignedClass === $classId) {
                $glyphs[$glyphId] = true;
            }
        }

        return new self($glyphs);
    }

    public function contains(int $glyphId): bool
    {
        if (null !== $this->glyphs) {
            return isset($this->glyphs[$glyphId]);
        }

        return !isset($this->excluded[$glyphId]);
    }

    /**
     * @param array<int, true> $glyphs
     */
    public function intersects(array $glyphs): bool
    {
        foreach ($glyphs as $glyphId => $_retained) {
            if ($this->contains($glyphId)) {
                return true;
            }
        }

        return false;
    }

    public function assertValid(int $glyphCount): void
    {
        foreach (null === $this->glyphs ? $this->excluded : $this->glyphs as $glyphId => $_member) {
            if ($glyphId < 0 || $glyphId >= $glyphCount) {
                throw new InvalidFontException(\sprintf(
                    'GSUB references invalid glyph ID %d for a font with %d glyphs.',
                    $glyphId,
                    $glyphCount,
                ));
            }
        }
    }
}
