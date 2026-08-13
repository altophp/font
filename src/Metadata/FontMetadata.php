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

use Alto\Font\Descriptor\FontDescriptor;
use Alto\Font\FontFace;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontMetadata
{
    public function __construct(
        public string $family,
        public string $subfamily,
        public FontFormat $format,
        public FontDescriptor $descriptor,
        public ?string $fullName = null,
        public ?string $postScriptName = null,
        public ?string $copyright = null,
        public ?string $license = null,
        public ?string $licenseUrl = null,
        public ?string $manufacturer = null,
        public ?string $designer = null,
        public ?string $designerUrl = null,
        public ?string $vendorUrl = null,
        public ?string $description = null,
        public ?string $version = null,
    ) {}

    public static function fromFace(FontFace $face): self
    {
        $descriptor = FontDescriptor::fromFace($face);

        return new self(
            family: $descriptor->family,
            subfamily: $descriptor->subfamily,
            format: FontFormat::fromPath($face->path),
            descriptor: $descriptor,
            fullName: $descriptor->fullName,
            postScriptName: $descriptor->postScriptName,
            copyright: $face->name(0),
            license: $face->name(13),
            licenseUrl: $face->name(14),
            manufacturer: $face->name(8),
            designer: $face->name(9),
            designerUrl: $face->name(12),
            vendorUrl: $face->name(11),
            description: $face->name(10),
            version: $face->name(5),
        );
    }
}
