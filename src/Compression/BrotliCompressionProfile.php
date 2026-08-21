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

namespace Alto\Font\Compression;

/**
 * Selects a Brotli compression quality profile.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum BrotliCompressionProfile: int
{
    case Fast = 5;
    case Maximum = 11;
}
