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

// Shared helpers for the benchmark scripts. Each script measures one scenario
// and prints, for every step, the best wall time over its repetitions, the
// peak memory above the memory in use before the step, and the memory still
// held once the step has returned.

require __DIR__ . '/../vendor/autoload.php';

/**
 * Returns the command-line arguments, script name first.
 *
 * @return list<string>
 */
function bench_arguments(): array
{
    $arguments = $_SERVER['argv'] ?? [];

    if (!\is_array($arguments)) {
        return [];
    }

    return array_values(array_filter($arguments, \is_string(...)));
}

/**
 * Returns the font path and face index given on the command line.
 *
 * @return array{string, int}
 */
function bench_font(string $script): array
{
    $arguments = bench_arguments();
    $path = $arguments[1] ?? null;

    if (null === $path || '' === $path) {
        fwrite(STDERR, \sprintf("Usage: php bench/%s <font path> [face index]\n", $script));
        exit(1);
    }

    if (!is_file($path)) {
        fwrite(STDERR, \sprintf("Font file \"%s\" does not exist.\n", $path));
        exit(1);
    }

    $faceIndex = (int) ($arguments[2] ?? 0);
    $size = filesize($path);

    printf("%s\n", bench_environment());
    printf("font: %s (%s)%s\n\n", $path, bench_bytes(false === $size ? 0 : $size), 0 === $faceIndex ? '' : ' face ' . $faceIndex);

    return [$path, $faceIndex];
}

function bench_environment(): string
{
    return \sprintf(
        'php %s, ext-brotli %s, memory_limit %s',
        PHP_VERSION,
        \extension_loaded('brotli') ? 'loaded' : 'absent',
        (string) ini_get('memory_limit'),
    );
}

/**
 * Runs $step $repeat times and reports the best wall time, the peak memory
 * above the usage measured before the first run, and the memory retained by
 * the value the last run returned.
 *
 * @template T
 *
 * @param callable(): T $step
 *
 * @return T
 */
function bench_measure(string $label, callable $step, int $repeat = 1): mixed
{
    gc_collect_cycles();
    $baseline = memory_get_usage();
    memory_reset_peak_usage();
    $start = hrtime(true);
    $result = $step();
    $best = hrtime(true) - $start;

    for ($run = 1; $run < $repeat; ++$run) {
        unset($result);
        $start = hrtime(true);
        $result = $step();
        $best = min($best, hrtime(true) - $start);
    }

    $peak = memory_get_peak_usage() - $baseline;
    $retained = memory_get_usage() - $baseline;

    printf(
        "%-44s %12s   peak %10s   retained %10s\n",
        $label,
        bench_duration($best),
        bench_bytes(max(0, $peak)),
        bench_bytes(max(0, $retained)),
    );

    return $result;
}

/**
 * Runs bench_measure() and reports a font the package refuses for this step
 * instead of aborting the whole script.
 *
 * @template T
 *
 * @param callable(): T $step
 *
 * @return T|null
 */
function bench_attempt(string $label, callable $step, int $repeat = 1): mixed
{
    try {
        return bench_measure($label, $step, $repeat);
    } catch (Alto\Font\Exception\FontExceptionInterface $exception) {
        printf("%-44s %12s   %s\n", $label, 'refused', $exception->getMessage());

        return null;
    }
}

/**
 * Returns the compact Latin subset of $font, the preserve-mode subset when the
 * font holds a table that compact numbering cannot rewrite, or null when the
 * font cannot be subsetted at all.
 */
function bench_latin_subset(Alto\Font\Font $font): ?Alto\Font\Font
{
    $unicodes = Alto\Font\Subset\UnicodeSet::fromCss(bench_latin_css());

    foreach ([Alto\Font\Subset\GlyphIdPolicy::Compact, Alto\Font\Subset\GlyphIdPolicy::Preserve] as $glyphIds) {
        try {
            return $font->subset(new Alto\Font\Subset\SubsetOptions($unicodes, glyphIds: $glyphIds))->font;
        } catch (Alto\Font\Exception\UnsupportedFontException $exception) {
            printf("    %s subset refused: %s\n", $glyphIds->name, $exception->getMessage());
        }
    }

    return null;
}

function bench_duration(int $nanoseconds): string
{
    if ($nanoseconds >= 1_000_000_000) {
        return \sprintf('%.2f s', $nanoseconds / 1e9);
    }

    if ($nanoseconds >= 1_000_000) {
        return \sprintf('%.2f ms', $nanoseconds / 1e6);
    }

    return \sprintf('%.3f ms', $nanoseconds / 1e6);
}

function bench_bytes(int $bytes): string
{
    if ($bytes >= 1_048_576) {
        return \sprintf('%.1f MB', $bytes / 1_048_576);
    }

    if ($bytes >= 1024) {
        return \sprintf('%.1f KB', $bytes / 1024);
    }

    return \sprintf('%d B', $bytes);
}

/**
 * Codepoints of the printable ASCII and Latin-1 ranges (U+0020-007E, U+00A0-00FF).
 *
 * @return list<int>
 */
function bench_latin_codepoints(): array
{
    return [...range(0x20, 0x7E), ...range(0xA0, 0xFF)];
}

function bench_latin_css(): string
{
    return 'U+0020-007E, U+00A0-00FF';
}
