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

namespace Alto\Font\Locator;

/**
 * Recursively lists supported font files from configured directories.
 *
 * Application and system locations can be combined. Unreadable directories
 * are skipped so discovery can continue through the remaining locations.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontLocator implements FontLocatorInterface
{
    private const array EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2', 'ttc', 'otc'];

    /**
     * @param list<string> $directories
     */
    public function __construct(private array $directories) {}

    public static function in(string ...$directories): self
    {
        return new self(array_values($directories));
    }

    public static function system(): self
    {
        return new self(self::standardSystemDirectories());
    }

    /**
     * @return list<string>
     */
    public static function standardSystemDirectories(): array
    {
        $home = getenv('HOME');
        $localAppData = getenv('LOCALAPPDATA');
        $windowsRoot = getenv('WINDIR') ?: getenv('SystemRoot') ?: 'C:\\Windows';

        return array_values(array_filter([
            '/System/Library/Fonts',
            '/System/Library/Fonts/Supplemental',
            '/Library/Fonts',
            \is_string($home) ? $home . '/Library/Fonts' : null,
            '/Network/Library/Fonts',
            '/usr/share/fonts',
            '/usr/local/share/fonts',
            \is_string($home) ? $home . '/.local/share/fonts' : null,
            \is_string($home) ? $home . '/.fonts' : null,
            $windowsRoot . '\\Fonts',
            \is_string($localAppData) ? $localAppData . '\\Microsoft\\Windows\\Fonts' : null,
        ], static fn(?string $directory): bool => \is_string($directory) && is_dir($directory)));
    }

    /**
     * @return iterable<string>
     */
    public function fonts(): iterable
    {
        $directories = array_values(array_filter($this->directories, static fn(string $directory): bool => is_dir($directory)));

        foreach ($directories as $directory) {
            yield from self::scanDirectory($directory);
        }
    }

    /**
     * @return iterable<string>
     */
    private static function scanDirectory(string $directory): iterable
    {
        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (\UnexpectedValueException) {
            return;
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $extension = strtolower($entry->getExtension());

            if (\in_array($extension, self::EXTENSIONS, true)) {
                yield $entry->getPathname();
            }
        }
    }
}
