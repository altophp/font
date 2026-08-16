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
            $markdown = file_get_contents($file);
            self::assertIsString($markdown);
            $blocks = [];
            preg_match_all('/```php\n(.*?)```/s', $markdown, $blocks);

            foreach ($blocks[1] as $index => $code) {
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

    public function testIndexSeparatesTheFourPublicWorkflows(): void
    {
        $index = file_get_contents(__DIR__ . '/../../docs/index.md');
        self::assertIsString($index);
        self::assertStringContainsString('## Inspect fonts', $index);
        self::assertStringContainsString('## Subset fonts', $index);
        self::assertStringContainsString('## Convert fonts', $index);
        self::assertStringContainsString('## Compress fonts', $index);
    }

    /**
     * @return list<string>
     */
    private static function documentationFiles(): array
    {
        $files = glob(__DIR__ . '/../../docs/*.md');
        self::assertIsArray($files);
        $files[] = __DIR__ . '/../../README.md';
        sort($files);

        return $files;
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
}
