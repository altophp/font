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

namespace Alto\Font\Subset;

use Alto\Font\Exception\InvalidUnicodeRangeException;
use Alto\Font\Text\UnicodeString;

/**
 * @implements \IteratorAggregate<int, int>
 */
final readonly class UnicodeSet implements \Countable, \IteratorAggregate
{
    /**
     * @param list<UnicodeRange> $ranges
     */
    private function __construct(
        public array $ranges,
        private int $size,
    ) {}

    /**
     * @param iterable<int> $codepoints
     */
    public static function fromCodepoints(iterable $codepoints): self
    {
        $values = [];

        foreach ($codepoints as $codepoint) {
            if ($codepoint < UnicodeRange::MIN_CODEPOINT || $codepoint > UnicodeRange::MAX_CODEPOINT) {
                UnicodeRange::single($codepoint);
            }

            $values[] = $codepoint;
        }

        if ([] === $values) {
            return new self([], 0);
        }

        sort($values, \SORT_NUMERIC);

        $ranges = [];
        $start = $values[0];
        $end = $start;
        $size = 1;

        $valueCount = \count($values);

        for ($index = 1; $index < $valueCount; ++$index) {
            $codepoint = $values[$index];

            if ($codepoint === $end) {
                continue;
            }

            ++$size;

            if ($codepoint === $end + 1) {
                $end = $codepoint;
                continue;
            }

            $ranges[] = new UnicodeRange($start, $end);
            $start = $end = $codepoint;
        }

        $ranges[] = new UnicodeRange($start, $end);

        return new self($ranges, $size);
    }

    /**
     * @param iterable<UnicodeRange> $ranges
     */
    public static function fromRanges(iterable $ranges): self
    {
        $normalized = [];

        foreach ($ranges as $range) {
            $normalized[] = $range;
        }

        usort($normalized, static fn(UnicodeRange $left, UnicodeRange $right): int => $left->start <=> $right->start ?: $left->end <=> $right->end);

        $merged = [];

        foreach ($normalized as $range) {
            $lastIndex = \count($merged) - 1;

            if ($lastIndex < 0 || $range->start > $merged[$lastIndex]->end + 1) {
                $merged[] = $range;
                continue;
            }

            $last = $merged[$lastIndex];
            $merged[$lastIndex] = new UnicodeRange($last->start, max($last->end, $range->end));
        }

        $size = 0;

        foreach ($merged as $range) {
            $size += $range->count();
        }

        return new self(array_values($merged), $size);
    }

    public static function fromText(string $text): self
    {
        return self::fromCodepoints(UnicodeString::codepoints($text));
    }

    public static function fromCss(string $unicodeRange): self
    {
        if ('' === trim($unicodeRange)) {
            throw new InvalidUnicodeRangeException('CSS unicode-range must not be empty.');
        }

        $ranges = [];

        foreach (explode(',', $unicodeRange) as $value) {
            $ranges[] = self::parseCssRange(trim($value));
        }

        return self::fromRanges($ranges);
    }

    public function union(self $other): self
    {
        return self::fromRanges([...$this->ranges, ...$other->ranges]);
    }

    public function intersect(self $other): self
    {
        $ranges = [];
        $leftIndex = 0;
        $rightIndex = 0;
        $leftCount = \count($this->ranges);
        $rightCount = \count($other->ranges);

        while ($leftIndex < $leftCount && $rightIndex < $rightCount) {
            $left = $this->ranges[$leftIndex];
            $right = $other->ranges[$rightIndex];
            $start = max($left->start, $right->start);
            $end = min($left->end, $right->end);

            if ($start <= $end) {
                $ranges[] = new UnicodeRange($start, $end);
            }

            if ($left->end < $right->end) {
                ++$leftIndex;
            } else {
                ++$rightIndex;
            }
        }

        return self::fromRanges($ranges);
    }

    public function without(self $other): self
    {
        if ($this->isEmpty() || $other->isEmpty()) {
            return $this;
        }

        $ranges = [];
        $excludedIndex = 0;
        $excludedCount = \count($other->ranges);

        foreach ($this->ranges as $range) {
            $cursor = $range->start;

            while ($excludedIndex < $excludedCount && $other->ranges[$excludedIndex]->end < $cursor) {
                ++$excludedIndex;
            }

            $scanIndex = $excludedIndex;

            while ($scanIndex < $excludedCount && $other->ranges[$scanIndex]->start <= $range->end) {
                $excluded = $other->ranges[$scanIndex];

                if ($excluded->start > $cursor) {
                    $ranges[] = new UnicodeRange($cursor, min($range->end, $excluded->start - 1));
                }

                if ($excluded->end >= $range->end) {
                    $cursor = $range->end + 1;
                    break;
                }

                $cursor = max($cursor, $excluded->end + 1);
                ++$scanIndex;
            }

            $excludedIndex = $scanIndex;

            if ($cursor <= $range->end) {
                $ranges[] = new UnicodeRange($cursor, $range->end);
            }
        }

        return self::fromRanges($ranges);
    }

    public function contains(int $codepoint): bool
    {
        if ($codepoint < UnicodeRange::MIN_CODEPOINT || $codepoint > UnicodeRange::MAX_CODEPOINT) {
            UnicodeRange::single($codepoint);
        }

        $low = 0;
        $high = \count($this->ranges) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $range = $this->ranges[$middle];

            if ($codepoint < $range->start) {
                $high = $middle - 1;
                continue;
            }

            if ($codepoint > $range->end) {
                $low = $middle + 1;
                continue;
            }

            return true;
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->size;
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return max(0, $this->size);
    }

    public function toCss(): string
    {
        return implode(', ', array_map(
            static fn(UnicodeRange $range): string => $range->toCss(),
            $this->ranges,
        ));
    }

    /**
     * @return \Traversable<int, int>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->ranges as $range) {
            for ($codepoint = $range->start; $codepoint <= $range->end; ++$codepoint) {
                yield $codepoint;
            }
        }
    }

    private static function parseCssRange(string $value): UnicodeRange
    {
        if (1 === preg_match('/\AU\+([0-9A-F]{1,6})(?:-([0-9A-F]{1,6}))?\z/i', $value, $matches)) {
            $start = intval($matches[1], 16);
            $end = isset($matches[2]) ? intval($matches[2], 16) : $start;

            return new UnicodeRange($start, $end);
        }

        if (1 === preg_match('/\AU\+([0-9A-F]{0,5})(\?{1,6})\z/i', $value, $matches)
            && \strlen($matches[1] . $matches[2]) <= 6
        ) {
            $start = intval($matches[1] . str_repeat('0', \strlen($matches[2])), 16);
            $end = intval($matches[1] . str_repeat('F', \strlen($matches[2])), 16);

            return new UnicodeRange($start, $end);
        }

        throw new InvalidUnicodeRangeException(\sprintf('Invalid CSS unicode-range value "%s".', $value));
    }
}
