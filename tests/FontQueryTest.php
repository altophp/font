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

namespace Alto\Font\Tests;

use Alto\Font\Descriptor\FontStretch;
use Alto\Font\Descriptor\FontStyle;
use Alto\Font\Descriptor\FontWeight;
use Alto\Font\FontQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontQuery::class)]
final class FontQueryTest extends TestCase
{
    public function testItBuildsFamilyQueries(): void
    {
        $query = FontQuery::family('Inter');

        self::assertSame('Inter', $query->family);
        self::assertSame('inter|*|*|*', $query->key());
    }

    public function testItBuildsWeightedStyledStretchedQueries(): void
    {
        $query = FontQuery::family('Inter')
            ->weight(800)
            ->style(FontStyle::Italic)
            ->stretch(new FontStretch(87));

        self::assertSame(800, $query->weight?->value);
        self::assertSame(FontStyle::Italic, $query->style);
        self::assertSame(87, $query->stretch?->percentage);
        self::assertSame('inter|800|italic|87', $query->key());
    }

    public function testItAcceptsWeightValueObjectsAndItalicShortcut(): void
    {
        $query = FontQuery::family('Inter')->weight(new FontWeight(500))->italic();

        self::assertSame(500, $query->weight?->value);
        self::assertSame(FontStyle::Italic, $query->style);
    }
}
