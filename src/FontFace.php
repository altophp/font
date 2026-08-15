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

use Alto\Font\Metadata\FontFormat;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontFace
{
    /**
     * @param list<string>       $tables
     * @param array<int, string> $names
     */
    public function __construct(
        public string $path,
        public int $unitsPerEm,
        public int $ascender,
        public int $descender,
        public int $glyphCount,
        public array $tables,
        public array $names = [],
        public int $faceIndex = 0,
        public int $faceCount = 1,
        public FontFormat $format = FontFormat::Unknown,
    ) {}

    public function name(int $nameId): ?string
    {
        return $this->names[$nameId] ?? null;
    }
}
