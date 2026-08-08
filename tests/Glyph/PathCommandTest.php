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

use Alto\Font\Glyph\PathCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathCommand::class)]
final class PathCommandTest extends TestCase
{
    public function testItCreatesMoveLineQuadraticAndCloseCommands(): void
    {
        $move = PathCommand::moveTo(1.0, 2.0);
        self::assertSame('M', $move->type);
        self::assertSame([1.0, 2.0], $move->coordinates);

        $line = PathCommand::lineTo(3.0, 4.0);
        self::assertSame('L', $line->type);
        self::assertSame([3.0, 4.0], $line->coordinates);

        $quad = PathCommand::quadraticTo(1.0, 2.0, 3.0, 4.0);
        self::assertSame('Q', $quad->type);
        self::assertSame([1.0, 2.0, 3.0, 4.0], $quad->coordinates);

        $close = PathCommand::closePath();
        self::assertSame('Z', $close->type);
        self::assertSame([], $close->coordinates);
    }

    public function testItTransformsCoordinates(): void
    {
        $command = PathCommand::lineTo(10.0, 20.0)->transform(
            xx: 2.0,
            yx: 0.0,
            xy: 0.0,
            yy: 3.0,
            dx: 5.0,
            dy: 7.0,
        );

        self::assertSame('L', $command->type);
        self::assertSame([25.0, 67.0], $command->coordinates);
    }
}
