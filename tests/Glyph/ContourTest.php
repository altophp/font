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

namespace Alto\Font\Tests\Glyph;

use Alto\Font\Glyph\Contour;
use Alto\Font\Glyph\PathCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Contour::class)]
final class ContourTest extends TestCase
{
    public function testItTransformsCommands(): void
    {
        $contour = new Contour([PathCommand::lineTo(10.0, 20.0)]);

        $transformed = $contour->transform(1.0, 0.0, 0.0, 1.0, 5.0, 10.0);

        self::assertSame('L', $transformed->commands[0]->type);
        self::assertSame([15.0, 30.0], $transformed->commands[0]->coordinates);
    }
}
