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

// Runs every per-font scenario on one font, each in a fresh PHP process so
// that the memory figures do not include the previous scenario.

require __DIR__ . '/bootstrap.php';

$arguments = bench_arguments();
$path = $arguments[1] ?? null;

if (null === $path || '' === $path) {
    fwrite(STDERR, "Usage: php bench/all.php <font path> [face index]\n");
    exit(1);
}

$faceIndex = $arguments[2] ?? '0';
$scenarios = ['load', 'metadata', 'cmap', 'glyph-metrics', 'glyph-outlines', 'subset', 'woff', 'woff2'];

foreach ($scenarios as $index => $scenario) {
    if ($index > 0) {
        echo "\n";
    }

    printf("== %s ==\n", $scenario);
    $command = [PHP_BINARY, __DIR__ . '/' . $scenario . '.php', $path, $faceIndex];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!\is_resource($process)) {
        fwrite(STDERR, \sprintf("Unable to run %s.\n", $scenario));
        exit(1);
    }

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    // The environment and font lines repeat on every script; keep them once.
    $lines = explode("\n", \is_string($output) ? $output : '');
    echo implode("\n", 0 === $index ? $lines : \array_slice($lines, 3));

    if (0 !== $exitCode) {
        printf("exit code %d\n%s", $exitCode, \is_string($errors) ? $errors : '');
    }
}
