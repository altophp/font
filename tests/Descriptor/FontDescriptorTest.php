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

namespace Alto\Font\Tests\Descriptor;

use Alto\Font\Descriptor\FontDescriptor;
use Alto\Font\Descriptor\FontStyle;
use Alto\Font\FontFace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontDescriptor::class)]
final class FontDescriptorTest extends TestCase
{
    public function testItBuildsDescriptorFromFaceNames(): void
    {
        $descriptor = FontDescriptor::fromFace(new FontFace('/tmp/font.ttf', 1000, 800, -200, 1, [], [
            1 => 'Fallback Family',
            2 => 'Regular',
            4 => 'Full Name',
            6 => 'PostScriptName',
            16 => 'Preferred Family',
            17 => 'Semi Bold Italic Condensed',
        ]));

        self::assertSame('Preferred Family', $descriptor->family);
        self::assertSame('Semi Bold Italic Condensed', $descriptor->subfamily);
        self::assertSame('Full Name', $descriptor->fullName);
        self::assertSame('PostScriptName', $descriptor->postScriptName);
        self::assertSame(600, $descriptor->weight->value);
        self::assertSame(FontStyle::Italic, $descriptor->style);
        self::assertSame(75, $descriptor->stretch->percentage);
    }

    public function testItFallsBackToRequiredNames(): void
    {
        $descriptor = FontDescriptor::fromFace(new FontFace('/tmp/font.ttf', 1000, 800, -200, 1, [], []));

        self::assertSame('Unknown', $descriptor->family);
        self::assertSame('Regular', $descriptor->subfamily);
    }
}
