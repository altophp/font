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

namespace Alto\Font\Tests\Writer;

use Alto\Font\Exception\FontWriteException;
use Alto\Font\Font;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Writer\ExclusiveFileWriter;
use Alto\Font\Writer\SfntWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SfntWriter::class)]
#[CoversClass(ExclusiveFileWriter::class)]
#[CoversClass(FontWriteException::class)]
final class SfntWriterTest extends TestCase
{
    public function testItDumpsAndWritesAFontThatCanBeReloaded(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('destination.ttf');
        TinyTrueTypeFont::write($source);
        $font = Font::fromFile($source);
        $writer = new SfntWriter();

        self::assertSame('Atelier Tiny', Font::fromFile(self::writeDump($writer->dump($font)))->getDescriptor()->family);

        $writer->write($font, $destination);

        self::assertSame('Atelier Tiny', Font::fromFile($destination)->getDescriptor()->family);
    }

    public function testItAcceptsAStringableDestination(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('destination.ttf');
        TinyTrueTypeFont::write($source);
        $file = new class ($destination) implements \Stringable {
            public function __construct(private readonly string $path) {}

            public function __toString(): string
            {
                return $this->path;
            }
        };

        new SfntWriter()->write(Font::fromFile($source), $file);

        self::assertFileExists($destination);
    }

    public function testItRefusesToReplaceAnExistingDestination(): void
    {
        $source = self::temporaryPath('source.ttf');
        $destination = self::temporaryPath('destination.ttf');
        TinyTrueTypeFont::write($source);
        file_put_contents($destination, 'keep me');

        try {
            new SfntWriter()->write(Font::fromFile($source), $destination);
            self::fail('Expected an existing destination to be rejected.');
        } catch (FontWriteException $error) {
            self::assertStringContainsString('destination already exists', $error->getMessage());
        }

        self::assertSame('keep me', file_get_contents($destination));
    }

    public function testItRejectsAnEmptyDestination(): void
    {
        $source = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($source);

        $this->expectException(FontWriteException::class);
        $this->expectExceptionMessage('must not be empty');

        new SfntWriter()->write(Font::fromFile($source), '');
    }

    private static function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/alto-font-writer-' . bin2hex(random_bytes(4)) . '-' . $suffix;
    }

    private static function writeDump(string $data): string
    {
        $path = self::temporaryPath('dump.ttf');
        file_put_contents($path, $data);

        return $path;
    }
}
