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

namespace Alto\Font\OpenType;

use Alto\Font\Exception\InvalidFontException;

/**
 * Maps source glyph identifiers to compact subset identifiers.
 *
 * The immutable mapping supports both forward lookup and source-order
 * iteration while retaining the subset's dense glyph order.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GlyphIdMap implements \Countable
{
    private const int MISSING_GLYPH = 0xFFFF;

    /**
     * @param list<int> $oldIdsByNewId
     */
    private function __construct(
        private int $sourceGlyphCount,
        private string $newIdsByOldId,
        private array $oldIdsByNewId,
    ) {}

    /**
     * @param array<int, true> $retainedGlyphs
     */
    public static function fromRetained(int $sourceGlyphCount, array $retainedGlyphs): self
    {
        if ($sourceGlyphCount < 1 || $sourceGlyphCount > self::MISSING_GLYPH) {
            throw new InvalidFontException(\sprintf(
                'TrueType source glyph count must be between 1 and 65535, got %d.',
                $sourceGlyphCount,
            ));
        }

        $retainedGlyphs[0] = true;
        $oldIds = array_keys($retainedGlyphs);
        sort($oldIds, \SORT_NUMERIC);
        $newIds = array_fill(0, $sourceGlyphCount, self::MISSING_GLYPH);

        foreach ($oldIds as $newId => $oldId) {
            if (!\is_int($oldId) || $oldId < 0 || $oldId >= $sourceGlyphCount) {
                throw new InvalidFontException(\sprintf('Retained glyph ID %s is outside the source font.', (string) $oldId));
            }

            $newIds[$oldId] = $newId;
        }

        return new self(
            $sourceGlyphCount,
            pack('n*', ...$newIds),
            $oldIds,
        );
    }

    public function newId(int $oldId): ?int
    {
        if ($oldId < 0 || $oldId >= $this->sourceGlyphCount) {
            return null;
        }

        $offset = $oldId * 2;
        $newId = (ord($this->newIdsByOldId[$offset]) << 8)
            | ord($this->newIdsByOldId[$offset + 1]);

        return self::MISSING_GLYPH === $newId ? null : $newId;
    }

    public function oldId(int $newId): int
    {
        if (!isset($this->oldIdsByNewId[$newId])) {
            throw new InvalidFontException(\sprintf('Subset glyph ID %d does not exist.', $newId));
        }

        return $this->oldIdsByNewId[$newId];
    }

    public function sourceGlyphCount(): int
    {
        return $this->sourceGlyphCount;
    }

    /**
     * @return \Generator<int, int>
     */
    public function pairs(): \Generator
    {
        foreach ($this->oldIdsByNewId as $newId => $oldId) {
            yield $oldId => $newId;
        }
    }

    public function count(): int
    {
        return \count($this->oldIdsByNewId);
    }
}
