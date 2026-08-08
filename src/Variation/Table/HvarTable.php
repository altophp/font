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

namespace Alto\Font\Variation\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\ItemStore\DeltaSetIndexMap;
use Alto\Font\Variation\ItemStore\ItemVariationStore;
use Alto\Font\Variation\NormalizedCoordinates;

final readonly class HvarTable
{
    private function __construct(
        private ItemVariationStore $store,
        private ?DeltaSetIndexMap $advanceWidthMapping,
        private ?DeltaSetIndexMap $leftSideBearingMapping,
        private ?DeltaSetIndexMap $rightSideBearingMapping,
    ) {}

    public static function parse(BinaryReader $reader, FontVariations $variations): self
    {
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || 0 !== $minorVersion) {
            throw new InvalidFontException(\sprintf('Unsupported HVAR table version %d.%d.', $majorVersion, $minorVersion));
        }

        $itemVariationStoreOffset = $reader->uint32(4);
        $advanceWidthMappingOffset = $reader->uint32(8);
        $leftSideBearingMappingOffset = $reader->uint32(12);
        $rightSideBearingMappingOffset = $reader->uint32(16);

        if (0 === $itemVariationStoreOffset) {
            throw new InvalidFontException('HVAR item variation store offset must not be NULL.');
        }

        if ((0 === $leftSideBearingMappingOffset) !== (0 === $rightSideBearingMappingOffset)) {
            throw new InvalidFontException('HVAR side-bearing mappings must both be present or both be NULL.');
        }

        return new self(
            ItemVariationStore::parse(new BinaryReader($reader->string($itemVariationStoreOffset, $reader->length() - $itemVariationStoreOffset), 'HVAR#ItemVariationStore'), $variations),
            0 === $advanceWidthMappingOffset ? null : self::parseMap($reader, $advanceWidthMappingOffset, 'advanceWidth'),
            0 === $leftSideBearingMappingOffset ? null : self::parseMap($reader, $leftSideBearingMappingOffset, 'leftSideBearing'),
            0 === $rightSideBearingMappingOffset ? null : self::parseMap($reader, $rightSideBearingMappingOffset, 'rightSideBearing'),
        );
    }

    public function advanceWidthDelta(int $glyphId, NormalizedCoordinates $coordinates): float
    {
        [$outerIndex, $innerIndex] = $this->advanceWidthMapping?->deltaSetIndex($glyphId) ?? [0, $glyphId];

        return $this->store->delta($outerIndex, $innerIndex, $coordinates);
    }

    public function leftSideBearingDelta(int $glyphId, NormalizedCoordinates $coordinates): float
    {
        if (null === $this->leftSideBearingMapping) {
            return 0.0;
        }

        [$outerIndex, $innerIndex] = $this->leftSideBearingMapping->deltaSetIndex($glyphId);

        return $this->store->delta($outerIndex, $innerIndex, $coordinates);
    }

    public function rightSideBearingDelta(int $glyphId, NormalizedCoordinates $coordinates): float
    {
        if (null === $this->rightSideBearingMapping) {
            return 0.0;
        }

        [$outerIndex, $innerIndex] = $this->rightSideBearingMapping->deltaSetIndex($glyphId);

        return $this->store->delta($outerIndex, $innerIndex, $coordinates);
    }

    private static function parseMap(BinaryReader $reader, int $offset, string $name): DeltaSetIndexMap
    {
        try {
            return DeltaSetIndexMap::parse(new BinaryReader($reader->string($offset, $reader->length() - $offset), 'HVAR#' . $name . 'Mapping'));
        } catch (InvalidFontException $exception) {
            throw new InvalidFontException(\sprintf('Invalid HVAR %s mapping: %s', $name, $exception->getMessage()), previous: $exception);
        }
    }
}
