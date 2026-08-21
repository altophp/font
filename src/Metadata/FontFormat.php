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

namespace Alto\Font\Metadata;

/**
 * Identifies a loaded font container format.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum FontFormat: string
{
    case TrueType = 'truetype';
    case OpenType = 'opentype';
    case Woff = 'woff';
    case Woff2 = 'woff2';
    case TrueTypeCollection = 'truetype-collection';
    case Unknown = 'unknown';

    /**
     * @internal
     */
    public static function fromSignature(string $signature): self
    {
        return match ($signature) {
            "\x00\x01\x00\x00", 'true' => self::TrueType,
            'OTTO' => self::OpenType,
            'wOFF' => self::Woff,
            'wOF2' => self::Woff2,
            'ttcf' => self::TrueTypeCollection,
            default => self::Unknown,
        };
    }

    public static function fromPath(string $path): self
    {
        return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'ttf' => self::TrueType,
            'otf' => self::OpenType,
            'woff' => self::Woff,
            'woff2' => self::Woff2,
            'ttc', 'otc' => self::TrueTypeCollection,
            default => self::Unknown,
        };
    }
}
