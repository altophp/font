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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\SfntDocument;
use Alto\Font\OpenType\Table\TableRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SfntDocument::class)]
final class SfntDocumentTest extends TestCase
{
    public function testItExposesSourceMetadataAndTables(): void
    {
        $document = new SfntDocument(
            new BinaryReader('headname', 'test SFNT'),
            "\x00\x01\x00\x00",
            [
                'name' => new TableRecord('name', 4, 4),
                'head' => new TableRecord('head', 0, 4),
            ],
            '/fonts/example.ttf',
            1,
            3,
            true,
        );

        self::assertSame("\x00\x01\x00\x00", $document->flavor);
        self::assertSame('/fonts/example.ttf', $document->sourcePath);
        self::assertSame(1, $document->faceIndex);
        self::assertSame(3, $document->faceCount);
        self::assertSame(['head', 'name'], $document->tableTags());
        self::assertSame('head', $document->table('head'));
        self::assertSame('name', $document->table('name'));
        self::assertNull($document->table('cmap'));
        self::assertSame('headname', $document->toSfnt());
    }

    public function testWithTablesIsImmutableAndCombinesReplacementRemovalAndAddition(): void
    {
        $document = new SfntDocument(
            new BinaryReader('headname', 'test SFNT'),
            "\x00\x01\x00\x00",
            [
                'head' => new TableRecord('head', 0, 4),
                'name' => new TableRecord('name', 4, 4),
            ],
            '/fonts/example.ttf',
            0,
            1,
            true,
        );

        $updated = $document->withTables(['name' => 'ALTO', 'cmap' => 'map'], ['head']);

        self::assertSame(['head', 'name'], $document->tableTags());
        self::assertSame('head', $document->table('head'));
        self::assertSame('name', $document->table('name'));

        self::assertSame(['cmap', 'name'], $updated->tableTags());
        self::assertNull($updated->table('head'));
        self::assertSame('ALTO', $updated->table('name'));
        self::assertSame('map', $updated->table('cmap'));
        self::assertSame('/fonts/example.ttf', $updated->sourcePath);
    }

    public function testAnyTransformationInvalidatesTheDsigTable(): void
    {
        $document = new SfntDocument(
            new BinaryReader('signatureold!', 'test SFNT'),
            "\x00\x01\x00\x00",
            [
                'DSIG' => new TableRecord('DSIG', 0, 9),
                'name' => new TableRecord('name', 9, 4),
            ],
            '/fonts/example.ttf',
            0,
            1,
            true,
        );

        $updated = $document->withTables(['name' => 'new!']);

        self::assertNull($updated->table('DSIG'));
        self::assertSame(['name'], $updated->tableTags());
    }

    public function testItRebuildsAChangedDocument(): void
    {
        $document = new SfntDocument(
            new BinaryReader('old!', 'test SFNT'),
            "\x00\x01\x00\x00",
            ['name' => new TableRecord('name', 0, 4)],
            '/fonts/example.ttf',
            0,
            1,
            true,
        );

        $sfnt = $document->withTables(['name' => 'new!'])->toSfnt();

        self::assertSame("\x00\x01\x00\x00", substr($sfnt, 0, 4));
        self::assertSame("\x00\x01", substr($sfnt, 4, 2));
        self::assertSame('name', substr($sfnt, 12, 4));
        self::assertSame('new!', substr($sfnt, 28, 4));
    }

    public function testItRejectsInvalidTableTags(): void
    {
        $document = new SfntDocument(
            new BinaryReader('', 'test SFNT'),
            "\x00\x01\x00\x00",
            [],
            '/fonts/example.ttf',
            0,
            1,
            true,
        );

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('exactly 4 bytes');

        $document->table('invalid');
    }
}
