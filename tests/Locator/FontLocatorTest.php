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

namespace Alto\Font\Tests\Locator;

use Alto\Font\Locator\FontLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontLocator::class)]
final class FontLocatorTest extends TestCase
{
    private string $temporaryRoot = '';

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/alto-font-locator-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryRoot, 0700));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->temporaryRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->temporaryRoot);
    }

    public function testItFindsFilesRecursivelyAcrossNestedDirectories(): void
    {
        $root = $this->temporaryDirectory('recursive');
        mkdir($root . '/nested/deeper', 0777, true);
        file_put_contents($root . '/top.ttf', '');
        file_put_contents($root . '/nested/mid.otf', '');
        file_put_contents($root . '/nested/deeper/bottom.woff2', '');

        $found = iterator_to_array(FontLocator::in($root)->fonts(), false);
        sort($found);

        self::assertSame([
            $root . '/nested/deeper/bottom.woff2',
            $root . '/nested/mid.otf',
            $root . '/top.ttf',
        ], $found);
    }

    public function testItFiltersByExtensionCaseInsensitively(): void
    {
        $root = $this->temporaryDirectory('extensions');
        file_put_contents($root . '/font.TTF', '');
        file_put_contents($root . '/notes.txt', '');
        file_put_contents($root . '/archive.zip', '');

        self::assertSame([$root . '/font.TTF'], iterator_to_array(FontLocator::in($root)->fonts(), false));
    }

    public function testItScansMultipleDirectories(): void
    {
        $first = $this->temporaryDirectory('multi-a');
        $second = $this->temporaryDirectory('multi-b');
        file_put_contents($first . '/a.ttf', '');
        file_put_contents($second . '/b.otf', '');

        $found = iterator_to_array(FontLocator::in($first, $second)->fonts(), false);
        sort($found);

        self::assertSame([$first . '/a.ttf', $second . '/b.otf'], $found);
    }

    public function testItIgnoresMissingDirectories(): void
    {
        self::assertSame([], iterator_to_array(FontLocator::in('/path/that/does/not/exist')->fonts(), false));
    }

    public function testItIgnoresPathsThatAreFiles(): void
    {
        $root = $this->temporaryDirectory('file-path');
        $path = $root . '/font.ttf';
        file_put_contents($path, '');

        self::assertSame([], iterator_to_array(FontLocator::in($path)->fonts(), false));
    }

    public function testItYieldsNothingForAnEmptyDirectoryList(): void
    {
        self::assertSame([], iterator_to_array((new FontLocator([]))->fonts(), false));
    }

    public function testItSkipsUnreadableSubdirectoriesInsteadOfFailing(): void
    {
        if (\function_exists('posix_getuid') && 0 === posix_getuid()) {
            self::markTestSkipped('Running as root bypasses directory permissions.');
        }

        $root = $this->temporaryDirectory('unreadable');
        mkdir($root . '/locked', 0000);
        file_put_contents($root . '/readable.ttf', '');

        try {
            $found = iterator_to_array(FontLocator::in($root)->fonts(), false);
        } finally {
            chmod($root . '/locked', 0755);
        }

        self::assertSame([$root . '/readable.ttf'], $found);
    }

    public function testItSkipsNonFileLeavesLikeSymlinksToDirectories(): void
    {
        $root = $this->temporaryDirectory('symlink-to-dir');
        mkdir($root . '/target');
        file_put_contents($root . '/readable.ttf', '');
        symlink($root . '/target', $root . '/link-to-dir');

        self::assertSame([$root . '/readable.ttf'], iterator_to_array(FontLocator::in($root)->fonts(), false));
    }

    public function testItReturnsExistingStandardSystemDirectories(): void
    {
        self::assertContainsOnlyString(FontLocator::standardSystemDirectories());
    }

    public function testItCreatesLocatorForCurrentSystem(): void
    {
        self::assertInstanceOf(FontLocator::class, FontLocator::system());
    }

    public function testItCombinesCustomAndSystemDirectories(): void
    {
        $root = $this->temporaryDirectory('combined');
        file_put_contents($root . '/custom.ttf', '');

        $locator = new FontLocator([$root, ...FontLocator::standardSystemDirectories()]);

        self::assertContains($root . '/custom.ttf', iterator_to_array($locator->fonts(), false));
    }

    private function temporaryDirectory(string $name): string
    {
        $directory = $this->temporaryRoot . '/' . $name;

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(\sprintf('Could not create "%s".', $directory));
        }

        return $directory;
    }
}
