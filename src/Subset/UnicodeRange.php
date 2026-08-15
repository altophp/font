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

final readonly class UnicodeRange
{
    public const int MIN_CODEPOINT = 0x000000;
    public const int MAX_CODEPOINT = 0x10FFFF;

    public function __construct(
        public int $start,
        public int $end,
    ) {
        self::validateCodepoint($start);
        self::validateCodepoint($end);

        if ($start > $end) {
            throw new InvalidUnicodeRangeException(\sprintf(
                'Unicode range start U+%04X must not be greater than end U+%04X.',
                $start,
                $end,
            ));
        }
    }

    public static function single(int $codepoint): self
    {
        return new self($codepoint, $codepoint);
    }

    public static function between(int $start, int $end): self
    {
        return new self($start, $end);
    }

    public function contains(int $codepoint): bool
    {
        self::validateCodepoint($codepoint);

        return $codepoint >= $this->start && $codepoint <= $this->end;
    }

    public function count(): int
    {
        return $this->end - $this->start + 1;
    }

    public function toCss(): string
    {
        $start = self::formatCodepoint($this->start);

        if ($this->start === $this->end) {
            return 'U+' . $start;
        }

        return \sprintf('U+%s-%s', $start, self::formatCodepoint($this->end));
    }

    private static function validateCodepoint(int $codepoint): void
    {
        if ($codepoint < self::MIN_CODEPOINT || $codepoint > self::MAX_CODEPOINT) {
            throw new InvalidUnicodeRangeException(\sprintf(
                'Unicode codepoint must be between U+0000 and U+10FFFF, got %d.',
                $codepoint,
            ));
        }
    }

    private static function formatCodepoint(int $codepoint): string
    {
        return strtoupper(str_pad(dechex($codepoint), 4, '0', \STR_PAD_LEFT));
    }
}
