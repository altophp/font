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

namespace Alto\Font\Writer;

use Alto\Font\Font;

/**
 * Writes fonts as standalone SFNT data.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SfntWriter
{
    public function dump(Font $font): string
    {
        return $font->toSfnt();
    }

    public function write(Font $font, string|\Stringable $file): void
    {
        ExclusiveFileWriter::preflight($file);
        ExclusiveFileWriter::write($this->dump($font), $file);
    }
}
