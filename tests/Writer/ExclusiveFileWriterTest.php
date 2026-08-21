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
use Alto\Font\Writer\ExclusiveFileWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;

#[CoversClass(ExclusiveFileWriter::class)]
final class ExclusiveFileWriterTest extends TestCase
{
    public function testItWritesStringableContentExclusively(): void
    {
        $path = $this->temporaryPath();
        $destination = new class ($path) implements Stringable {
            public function __construct(private readonly string $path) {}

            public function __toString(): string
            {
                return $this->path;
            }
        };

        try {
            ExclusiveFileWriter::write('font bytes', $destination);

            self::assertSame('font bytes', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testItWritesChunksInOrder(): void
    {
        $path = $this->temporaryPath();

        try {
            ExclusiveFileWriter::writeChunks(['font', ' ', 'bytes'], $path);

            self::assertSame('font bytes', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testItRejectsAnEmptyPath(): void
    {
        $this->expectException(FontWriteException::class);
        $this->expectExceptionMessage('must not be empty');

        ExclusiveFileWriter::write('font', '');
    }

    public function testItRefusesToOverwriteAnExistingFile(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'original');

        try {
            try {
                ExclusiveFileWriter::write('replacement', $path);
                self::fail('An existing destination must be rejected.');
            } catch (FontWriteException $exception) {
                self::assertStringContainsString('already exists', $exception->getMessage());
                self::assertSame('original', file_get_contents($path));
            }
        } finally {
            @unlink($path);
        }
    }

    public function testItRemovesAPartialFileWhenChunkGenerationFails(): void
    {
        $path = $this->temporaryPath();
        $chunks = static function (): iterable {
            yield 'partial';

            throw new RuntimeException('stream failed');
        };

        try {
            ExclusiveFileWriter::writeChunks($chunks(), $path);
            self::fail('The stream failure must be propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('stream failed', $exception->getMessage());
            self::assertFileDoesNotExist($path);
        }
    }

    private function temporaryPath(): string
    {
        return sys_get_temp_dir() . '/alto-font-exclusive-write-' . bin2hex(random_bytes(8));
    }
}
