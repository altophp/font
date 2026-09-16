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

namespace Alto\Font\Tests\Validation;

use PHPUnit\Framework\Assert;

final class FontValidationTools
{
    /**
     * @param list<string> $command
     */
    public static function run(array $command): string
    {
        $output = tmpfile();
        $error = tmpfile();
        Assert::assertIsResource($output);
        Assert::assertIsResource($error);

        try {
            $process = proc_open($command, [1 => $output, 2 => $error], $pipes);
            Assert::assertIsResource($process, 'Unable to start ' . implode(' ', $command));
            $exitCode = proc_close($process);
            rewind($output);
            rewind($error);
            $stdout = stream_get_contents($output);
            $stderr = stream_get_contents($error);
            Assert::assertIsString($stdout);
            Assert::assertIsString($stderr);
            Assert::assertSame(0, $exitCode, implode(' ', $command) . "\n" . $stdout . $stderr);

            return $stdout;
        } finally {
            fclose($output);
            fclose($error);
        }
    }

    public static function python(): string
    {
        $python = getenv('OTS_PYTHON');

        return \is_string($python) && '' !== trim($python) ? $python : 'python3';
    }

    /**
     * @return list<string>
     */
    public static function otsCommand(): array
    {
        $sanitizer = getenv('OTS_SANITIZER');

        if (\is_string($sanitizer) && '' !== trim($sanitizer)) {
            return [$sanitizer];
        }

        return [
            self::python(),
            '-c',
            <<<'PYTHON'
import ots
import sys

if '--version' in sys.argv:
    print(ots.__version__)
    raise SystemExit(0)

raise SystemExit(ots.sanitize(sys.argv[1], sys.argv[2]).returncode)
PYTHON,
        ];
    }

    public static function sanitize(string $source, string $destination): void
    {
        self::run([...self::otsCommand(), $source, $destination]);
    }
}
