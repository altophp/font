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

namespace Alto\Font;

use Alto\Font\Descriptor\FontStyle;
use Alto\Font\Exception\FontExceptionInterface;
use Alto\Font\Exception\FontNotFoundException;
use Alto\Font\Loader\FontLoader;
use Alto\Font\Loader\FontLoaderInterface;
use Alto\Font\Locator\FontLocator;
use Alto\Font\Locator\FontLocatorInterface;
use Alto\Font\Metadata\FontMetadata;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class FontFinder
{
    /**
     * @var list<string>|null
     */
    private ?array $candidates = null;

    /**
     * @var array<string, Font>
     */
    private array $fontsByPath = [];

    /**
     * @var array<string, string|null>
     */
    private array $queryPathCache = [];

    public function __construct(
        private readonly FontLocatorInterface $locator,
        private readonly FontLoaderInterface $loader = new FontLoader(),
    ) {}

    public static function fromDirectories(string ...$directories): self
    {
        return new self(FontLocator::in(...$directories));
    }

    public static function system(): self
    {
        return new self(FontLocator::system());
    }

    public static function fromLocator(FontLocatorInterface $locator): self
    {
        return new self($locator);
    }

    public function has(
        string|FontQuery $query,
        ?int $weight = null,
        ?FontStyle $style = null,
    ): bool {
        return null !== $this->find($query, $weight, $style);
    }

    public function find(
        string|FontQuery $query,
        ?int $weight = null,
        ?FontStyle $style = null,
    ): ?Font {
        $query = $this->normalizeQuery($query, $weight, $style);
        $path = $this->findPath($query);

        return null === $path ? null : $this->withQueryVariations($this->loadFont($path), $query);
    }

    public function get(
        string|FontQuery $query,
        ?int $weight = null,
        ?FontStyle $style = null,
    ): Font {
        $font = $this->find($query, $weight, $style);

        if (null === $font) {
            $query = $this->normalizeQuery($query, $weight, $style);

            throw new FontNotFoundException(\sprintf('Font "%s" was not found.', $query->family));
        }

        return $font;
    }

    private function normalizeQuery(string|FontQuery $query, ?int $weight, ?FontStyle $style): FontQuery
    {
        if (\is_string($query)) {
            $query = FontQuery::family($query);
        }

        if (null !== $weight) {
            $query = $query->weight($weight);
        }

        if (null !== $style) {
            $query = $query->style($style);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function candidates(): array
    {
        return $this->candidates ??= array_values(iterator_to_array($this->locator->fonts(), false));
    }

    private function findPath(FontQuery $query): ?string
    {
        $key = $query->key();

        if (\array_key_exists($key, $this->queryPathCache)) {
            return $this->queryPathCache[$key];
        }

        $bestPath = null;
        $bestScore = PHP_INT_MIN;

        foreach ($this->candidates() as $path) {
            $font = $this->loadCandidate($path);
            $metadata = $font?->metadata();

            if (null === $metadata || !$this->familyMatches($metadata, $query)) {
                continue;
            }

            $score = $this->score($font, $metadata, $query);

            if ($score > $bestScore) {
                $bestPath = $path;
                $bestScore = $score;
            }
        }

        $this->queryPathCache[$key] = $bestPath;

        return $bestPath;
    }

    private function loadCandidate(string $path): ?Font
    {
        try {
            return $this->loadFont($path);
        } catch (FontExceptionInterface) {
            return null;
        }
    }

    private function loadFont(string $path): Font
    {
        return $this->fontsByPath[$path] ??= $this->loader->load($path);
    }

    private function withQueryVariations(Font $font, FontQuery $query): Font
    {
        $variations = $font->variations();

        if (null === $variations) {
            return $font;
        }

        $coordinates = [];

        if (null !== $query->weight && $variations->hasAxis('wght')) {
            $coordinates['wght'] = $query->weight->value;
        }

        if (null !== $query->stretch && $variations->hasAxis('wdth')) {
            $coordinates['wdth'] = $query->stretch->percentage;
        }

        return [] === $coordinates ? $font : $font->withVariations($coordinates);
    }

    private function familyMatches(FontMetadata $metadata, FontQuery $query): bool
    {
        return strtolower($metadata->family) === strtolower($query->family);
    }

    private function score(Font $font, FontMetadata $metadata, FontQuery $query): int
    {
        $score = 1000;
        $variations = $font->variations();

        if (null !== $query->weight) {
            if ($metadata->descriptor->weight->value === $query->weight->value) {
                $score += 200;
            } elseif (null !== $variations && $variations->hasAxis('wght')) {
                $score += 150;
            } else {
                $score -= abs($metadata->descriptor->weight->value - $query->weight->value);
            }
        }

        if (null !== $query->style) {
            $score += $metadata->descriptor->style === $query->style ? 100 : -100;
        }

        if (null !== $query->stretch) {
            if ($metadata->descriptor->stretch->percentage === $query->stretch->percentage) {
                $score += 200;
            } elseif (null !== $variations && $variations->hasAxis('wdth')) {
                $score += 150;
            } else {
                $score -= abs($metadata->descriptor->stretch->percentage - $query->stretch->percentage);
            }
        }

        return $score;
    }
}
