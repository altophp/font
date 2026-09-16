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

namespace Alto\Font\OpenType\Layout;

use Alto\Font\Exception\UnsupportedFontException;

/**
 * Builds a layout lookup list with an extension fallback for large payloads.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class LookupListTable
{
    /**
     * @param list<LookupTable> $lookups
     */
    public static function build(array $lookups, int $extensionLookupType, string $tag): string
    {
        if (\count($lookups) > 0xFFFF) {
            throw new UnsupportedFontException(\sprintf('Compacted %s lookup count exceeds OpenType limits.', $tag));
        }

        $direct = self::buildDirect($lookups, $extensionLookupType, $tag);

        return $direct ?? self::buildExtensions($lookups, $extensionLookupType, $tag);
    }

    /**
     * @param list<LookupTable> $lookups
     */
    private static function buildDirect(array $lookups, int $extensionLookupType, string $tag): ?string
    {
        $serialized = [];

        foreach ($lookups as $lookup) {
            $data = $lookup->extension
                ? self::buildExtensionLookup($lookup, $extensionLookupType, $tag)
                : self::buildLookup($lookup);

            if (null === $data) {
                return null;
            }

            $serialized[] = $data;
        }

        $header = self::uint16(\count($serialized));
        $data = '';
        $cursor = 2 + \count($serialized) * 2;

        foreach ($serialized as $lookup) {
            if ($cursor > 0xFFFF) {
                return null;
            }

            $header .= self::uint16($cursor);
            $data .= $lookup;
            $cursor += \strlen($lookup);
        }

        return $header . $data;
    }

    private static function buildLookup(LookupTable $lookup): ?string
    {
        $headerLength = 6 + \count($lookup->subtables) * 2 + (null === $lookup->markFilteringSet ? 0 : 2);
        $header = self::uint16($lookup->type)
            . self::uint16($lookup->flag)
            . self::uint16(\count($lookup->subtables));
        $data = '';
        $cursor = $headerLength;

        foreach ($lookup->subtables as $subtable) {
            if ($cursor > 0xFFFF) {
                return null;
            }

            $header .= self::uint16($cursor);
            $data .= $subtable;
            $cursor += \strlen($subtable);
        }

        if (null !== $lookup->markFilteringSet) {
            $header .= self::uint16($lookup->markFilteringSet);
        }

        return $header . $data;
    }

    private static function buildExtensionLookup(
        LookupTable $lookup,
        int $extensionLookupType,
        string $tag,
    ): ?string {
        $headerLength = 6 + \count($lookup->subtables) * 2 + (null === $lookup->markFilteringSet ? 0 : 2);
        $header = self::uint16($extensionLookupType)
            . self::uint16($lookup->flag)
            . self::uint16(\count($lookup->subtables));
        $wrapperCursor = $headerLength;

        foreach ($lookup->subtables as $_subtable) {
            if ($wrapperCursor > 0xFFFF) {
                return null;
            }

            $header .= self::uint16($wrapperCursor);
            $wrapperCursor += 8;
        }

        if (null !== $lookup->markFilteringSet) {
            $header .= self::uint16($lookup->markFilteringSet);
        }

        $wrappers = '';
        $payloads = '';
        $payloadCursor = $wrapperCursor;

        foreach ($lookup->subtables as $index => $subtable) {
            $currentWrapper = $headerLength + $index * 8;
            $extensionOffset = $payloadCursor - $currentWrapper;

            self::assertOffset32($extensionOffset, $tag);
            $wrappers .= self::uint16(1)
                . self::uint16($lookup->type)
                . self::uint32($extensionOffset);
            $payloads .= $subtable;
            $payloadCursor += \strlen($subtable);
        }

        return $header . $wrappers . $payloads;
    }

    /**
     * @param list<LookupTable> $lookups
     */
    private static function buildExtensions(array $lookups, int $extensionLookupType, string $tag): string
    {
        $records = [];
        $lookupCursor = 2 + \count($lookups) * 2;

        foreach ($lookups as $index => $lookup) {
            if ($lookupCursor > 0xFFFF) {
                throw new UnsupportedFontException(\sprintf(
                    'Compacted %s lookup headers exceed a 16-bit OpenType offset.',
                    $tag,
                ));
            }

            $headerLength = 6 + \count($lookup->subtables) * 2 + (null === $lookup->markFilteringSet ? 0 : 2);
            $lastWrapperOffset = $headerLength + (\count($lookup->subtables) - 1) * 8;

            if ($lastWrapperOffset > 0xFFFF) {
                throw new UnsupportedFontException(\sprintf(
                    'Compacted %s lookup %d extension wrappers exceed a 16-bit OpenType offset.',
                    $tag,
                    $index,
                ));
            }

            $records[] = [
                'lookup' => $lookup,
                'offset' => $lookupCursor,
                'headerLength' => $headerLength,
            ];
            $lookupCursor += $headerLength + \count($lookup->subtables) * 8;
        }

        $header = self::uint16(\count($records));
        $blocks = '';
        $payloads = '';
        $payloadCursor = $lookupCursor;

        foreach ($records as $record) {
            $lookup = $record['lookup'];
            $header .= self::uint16($record['offset']);
            $block = self::uint16($extensionLookupType)
                . self::uint16($lookup->flag)
                . self::uint16(\count($lookup->subtables));
            $wrapperCursor = $record['headerLength'];

            foreach ($lookup->subtables as $_subtable) {
                $block .= self::uint16($wrapperCursor);
                $wrapperCursor += 8;
            }

            if (null !== $lookup->markFilteringSet) {
                $block .= self::uint16($lookup->markFilteringSet);
            }

            foreach ($lookup->subtables as $subtable) {
                $wrapperOffset = $record['offset'] + \strlen($block);
                $extensionOffset = $payloadCursor - $wrapperOffset;

                self::assertOffset32($extensionOffset, $tag);
                $block .= self::uint16(1)
                    . self::uint16($lookup->type)
                    . self::uint32($extensionOffset);
                $payloads .= $subtable;
                $payloadCursor += \strlen($subtable);
            }

            $blocks .= $block;
        }

        return $header . $blocks . $payloads;
    }

    private static function assertOffset32(int $offset, string $tag): void
    {
        if ($offset < 8 || $offset > 0xFFFFFFFF) {
            throw new UnsupportedFontException(\sprintf(
                'Compacted %s extension payload exceeds OpenType limits.',
                $tag,
            ));
        }
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
