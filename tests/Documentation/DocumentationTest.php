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

namespace Alto\Font\Tests\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DocumentationTest extends TestCase
{
    public function testInternalMarkdownLinksResolve(): void
    {
        foreach (self::documentationFiles() as $file) {
            $markdown = file_get_contents($file);
            self::assertIsString($markdown);
            $matches = [];
            preg_match_all('/\[[^]]+]\((?!https?:|mailto:|#)([^)\s]+\.md)(?:#[^)]+)?\)/', $markdown, $matches);

            foreach ($matches[1] as $target) {
                self::assertFileExists(
                    dirname($file) . '/' . rawurldecode($target),
                    \sprintf('Broken documentation link "%s" in %s.', $target, $file),
                );
            }
        }
    }

    public function testPhpExamplesAreSyntacticallyValidAndReferenceExistingTypes(): void
    {
        foreach (self::documentationFiles() as $file) {
            foreach (self::phpExamples($file) as $index => $code) {
                $imports = [];
                preg_match_all('/^use ([A-Za-z\\\\]+);$/m', $code, $imports);

                foreach ($imports[1] as $type) {
                    self::assertTrue(
                        class_exists($type) || interface_exists($type) || enum_exists($type),
                        \sprintf('Unknown type "%s" in PHP example %d from %s.', $type, $index + 1, $file),
                    );
                }

                $source = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code;
                self::assertPhpSyntax($source, $file, $index + 1);
            }
        }
    }

    public function testStandalonePhpExamplesExecuteAgainstTheInstalledApi(): void
    {
        $examples = [
            __DIR__ . '/../../docs/discovery.md' => [6],
            __DIR__ . '/../../docs/subsetting/unicode-sets.md' => [0, 1],
        ];

        foreach ($examples as $file => $indexes) {
            $blocks = self::phpExamples($file);

            foreach ($indexes as $index) {
                self::assertArrayHasKey($index, $blocks);
                self::assertPhpExecution($blocks[$index], $file, $index + 1);
            }
        }
    }

    public function testIndexSeparatesThePublicDocumentationDomains(): void
    {
        $index = file_get_contents(__DIR__ . '/../../docs/index.md');
        self::assertIsString($index);
        self::assertStringContainsString('## Fonts', $index);
        self::assertStringContainsString('## Conversion', $index);
        self::assertStringContainsString('## Compression', $index);
        self::assertStringContainsString('## Subsetting', $index);
        self::assertMatchesRegularExpression(
            '/## Fonts.*## Conversion.*## Compression.*## Subsetting/s',
            $index,
        );
    }

    /**
     * @return list<string>
     */
    private static function documentationFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                __DIR__ . '/../../docs',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && 'md' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        $files[] = __DIR__ . '/../../README.md';
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function phpExamples(string $file): array
    {
        $markdown = file_get_contents($file);
        self::assertIsString($markdown);
        $blocks = [];
        preg_match_all('/```php\n(.*?)```/s', $markdown, $blocks);

        return $blocks[1];
    }

    private static function assertPhpSyntax(string $code, string $file, int $example): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alto-font-docs-');
        self::assertIsString($path);

        try {
            self::assertIsInt(file_put_contents($path, $code));
            $process = proc_open(
                [PHP_BINARY, '-l', $path],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(
                0,
                $exitCode,
                \sprintf(
                    "Invalid PHP syntax in example %d from %s.\n%s%s",
                    $example,
                    $file,
                    \is_string($output) ? $output : '',
                    \is_string($error) ? $error : '',
                ),
            );
        } finally {
            @unlink($path);
        }
    }

    private static function assertPhpExecution(string $code, string $file, int $example): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alto-font-docs-exec-');
        self::assertIsString($path);
        $autoload = var_export(__DIR__ . '/../../vendor/autoload.php', true);
        $source = "<?php\nrequire " . $autoload . ";\n" . $code;

        try {
            self::assertIsInt(file_put_contents($path, $source));
            $process = proc_open(
                [PHP_BINARY, $path],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(
                0,
                $exitCode,
                \sprintf(
                    "PHP example %d from %s is not executable.\n%s%s",
                    $example,
                    $file,
                    \is_string($output) ? $output : '',
                    \is_string($error) ? $error : '',
                ),
            );
        } finally {
            @unlink($path);
        }
    }
}
