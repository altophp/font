<?php

declare(strict_types=1);

namespace Alto\Font\Tests\Fixtures;

use Alto\Font\Glyph\Contour;

/**
 * alto/font no longer renders path data (that moved to the SVG-drawing
 * package) - this test-only helper turns a Contour's raw commands into a
 * readable string so existing geometry expectations stay easy to assert on.
 */
trait ContourAssertions
{
    private static function describeContour(Contour $contour): string
    {
        return implode(' ', array_map(
            static function ($command): string {
                $format = static function (float $value): string {
                    $rounded = round($value, 4);
                    if (0.0 === $rounded) {
                        return '0';
                    }

                    $formatted = rtrim(rtrim(sprintf('%.4F', $rounded), '0'), '.');

                    return '-0' === $formatted ? '0' : $formatted;
                };

                return match ($command->type) {
                    'M', 'L' => $command->type.' '.$format($command->coordinates[0]).' '.$format($command->coordinates[1]),
                    'Q' => 'Q '.$format($command->coordinates[0]).' '.$format($command->coordinates[1]).' '.$format($command->coordinates[2]).' '.$format($command->coordinates[3]),
                    'Z' => 'Z',
                    default => throw new \LogicException('Unsupported command type in test helper.'),
                };
            },
            $contour->commands,
        ));
    }
}
