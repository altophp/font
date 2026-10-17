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

use Alto\Font\Descriptor\FontStretch;
use Alto\Font\Descriptor\FontStyle;
use Alto\Font\Descriptor\FontWeight;
use Alto\Font\Exception\FontExceptionInterface;
use Alto\Font\Exception\FontNotFoundException;
use Alto\Font\Loader\FontLoader;
use Alto\Font\Loader\FontLoaderInterface;
use Alto\Font\Locator\FontLocator;
use Alto\Font\Locator\FontLocatorInterface;
use Alto\Font\Metadata\FontMetadata;
use Alto\Font\Variation\FontVariations;

/**
 * Finds the closest matching font from configured locations.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FontFinder
{
    /**
     * Parsed fonts kept for repeated queries; older entries are evicted first.
     */
    private const int FONT_CACHE_SIZE = 8;

    /**
     * @var list<string>|null
     */
    private ?array $candidates = null;

    /**
     * What scoring needs from each candidate, without the parsed font.
     *
     * @var array<string, array{FontMetadata, FontVariations|null}>
     */
    private array $descriptions = [];

    /**
     * @var array<string, Font> most recently used last
     */
    private array $fonts = [];

    /**
     * @var array<string, string|null>
     */
    private array $queryPathCache = [];

    /**
     * @var array<string, FontExceptionInterface>
     */
    private array $diagnostics = [];

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

    /**
     * Returns font files skipped because they could not be loaded.
     *
     * @return array<string, FontExceptionInterface>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
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
        $bestFont = null;

        foreach ($this->candidates() as $path) {
            if (isset($this->diagnostics[$path])) {
                continue;
            }

            $font = null;

            if (!isset($this->descriptions[$path])) {
                $font = $this->describe($path);

                if (null === $font) {
                    continue;
                }
            }

            [$metadata, $variations] = $this->descriptions[$path];

            if (!$this->familyMatches($metadata, $query)) {
                continue;
            }

            $score = $this->score($variations, $metadata, $query);

            if ($score > $bestScore) {
                $bestPath = $path;
                $bestScore = $score;
                $bestFont = $font;
            }
        }

        if (null !== $bestPath && null !== $bestFont) {
            $this->remember($bestPath, $bestFont);
        }

        $this->queryPathCache[$key] = $bestPath;

        return $bestPath;
    }

    /**
     * Parses a candidate once and keeps only its metadata and axes.
     */
    private function describe(string $path): ?Font
    {
        try {
            $font = $this->loader->load($path);
        } catch (FontExceptionInterface $exception) {
            $this->diagnostics[$path] = self::detach($exception::class, $exception->getMessage(), $exception->getCode());

            return null;
        }

        $this->descriptions[$path] = [$font->metadata(), $font->variations()];

        return $font;
    }

    /**
     * Rebuilds an exception from scalars only: the original trace holds the whole
     * file contents of the rejected candidate in its call arguments, and passing
     * the exception itself here would keep it alive through this frame.
     *
     * @param class-string<FontExceptionInterface> $class
     */
    private static function detach(string $class, string $message, int $code): FontExceptionInterface
    {
        return new $class($message, $code);
    }

    private function loadFont(string $path): Font
    {
        $font = $this->fonts[$path] ?? $this->loader->load($path);
        $this->remember($path, $font);

        return $font;
    }

    private function remember(string $path, Font $font): void
    {
        unset($this->fonts[$path]);
        $this->fonts[$path] = $font;

        while (\count($this->fonts) > self::FONT_CACHE_SIZE) {
            $oldest = array_key_first($this->fonts);

            if (null === $oldest) {
                break;
            }

            unset($this->fonts[$oldest]);
        }
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

    /**
     * Explicit criteria score in the hundreds. An unset criterion only breaks
     * ties, in tens at most, so that a family-only query prefers the regular face.
     */
    private function score(?FontVariations $variations, FontMetadata $metadata, FontQuery $query): int
    {
        $score = 1000;
        $descriptor = $metadata->descriptor;

        if (null === $query->weight) {
            $score -= intdiv(abs($descriptor->weight->value - FontWeight::normal()->value), 10);
        } elseif ($descriptor->weight->value === $query->weight->value) {
            $score += 200;
        } elseif (null !== $variations && $variations->hasAxis('wght')) {
            $score += 150;
        } else {
            $score -= abs($descriptor->weight->value - $query->weight->value);
        }

        if (null === $query->style) {
            $score -= FontStyle::Normal === $descriptor->style ? 0 : 10;
        } else {
            $score += $descriptor->style === $query->style ? 100 : -100;
        }

        if (null === $query->stretch) {
            $score -= intdiv(abs($descriptor->stretch->percentage - FontStretch::normal()->percentage), 10);
        } elseif ($descriptor->stretch->percentage === $query->stretch->percentage) {
            $score += 200;
        } elseif (null !== $variations && $variations->hasAxis('wdth')) {
            $score += 150;
        } else {
            $score -= abs($descriptor->stretch->percentage - $query->stretch->percentage);
        }

        return $score;
    }
}
