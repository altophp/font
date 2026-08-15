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

namespace Alto\Font\Tests\Metadata;

use Alto\Font\FontFace;
use Alto\Font\Metadata\FontFormat;
use Alto\Font\Metadata\FontMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontMetadata::class)]
final class FontMetadataTest extends TestCase
{
    public function testItBuildsMetadataFromFace(): void
    {
        $metadata = FontMetadata::fromFace(new FontFace(
            '/tmp/font.woff2',
            1000,
            800,
            -200,
            1,
            [],
            [
                0 => 'Copyright',
                1 => 'Family',
                2 => 'Black Italic',
                4 => 'Full Name',
                5 => 'Version 1.0',
                6 => 'PostScriptName',
                8 => 'Manufacturer',
                9 => 'Designer',
                10 => 'Description',
                11 => 'https://vendor.example',
                12 => 'https://designer.example',
                13 => 'License',
                14 => 'https://license.example',
            ],
            format: FontFormat::Woff2,
        ));

        self::assertSame('Family', $metadata->family);
        self::assertSame('Black Italic', $metadata->subfamily);
        self::assertSame(FontFormat::Woff2, $metadata->format);
        self::assertSame('Full Name', $metadata->fullName);
        self::assertSame('PostScriptName', $metadata->postScriptName);
        self::assertSame('Copyright', $metadata->copyright);
        self::assertSame('License', $metadata->license);
        self::assertSame('https://license.example', $metadata->licenseUrl);
        self::assertSame('Manufacturer', $metadata->manufacturer);
        self::assertSame('Designer', $metadata->designer);
        self::assertSame('https://designer.example', $metadata->designerUrl);
        self::assertSame('https://vendor.example', $metadata->vendorUrl);
        self::assertSame('Description', $metadata->description);
        self::assertSame('Version 1.0', $metadata->version);
    }

    public function testItUsesTheDetectedFaceFormatInsteadOfThePathExtension(): void
    {
        $face = new FontFace('/tmp/misleading.woff2', 1000, 800, -200, 1, [], format: FontFormat::TrueType);

        self::assertSame(FontFormat::TrueType, FontMetadata::fromFace($face)->format);
    }
}
