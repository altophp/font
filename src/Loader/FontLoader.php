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

use Alto\Font\Exception\ExceptionDetacher;
use Alto\Font\Exception\FontExceptionInterface;
use Alto\Font\Font;
use Alto\Font\OpenType\SfntFont;

/**
 * Loads font files into the public font API.
 *
 * A failure is rethrown as a fresh exception of the same class, message and
 * code, so the file contents never travel in a trace argument.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontLoader implements FontLoaderInterface
{
    public function load(string|\Stringable $file, int $faceIndex = 0): Font
    {
        try {
            return new Font(SfntFont::open((string) $file, $faceIndex));
        } catch (FontExceptionInterface $exception) {
            throw ExceptionDetacher::detach($exception::class, $exception->getMessage(), $exception->getCode());
        }
    }
}
