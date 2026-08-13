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

namespace Alto\Font\Loader;

use Alto\Font\Font;
use Alto\Font\OpenType\SfntFont;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontLoader implements FontLoaderInterface
{
    public function load(string|\Stringable $file, int $faceIndex = 0): Font
    {
        return new Font(SfntFont::open((string) $file, $faceIndex));
    }
}
