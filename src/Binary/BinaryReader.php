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

namespace Alto\Font\Binary;

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Table\TableRecord;

/**
 * Reads typed values from bounded binary font data.
 *
 * A reader is a window over a byte string: an offset and a length. Table
 * readers created with table() share the bytes of the reader they come from
 * instead of copying them, so a window costs one small object whatever the
 * size of the table it exposes. Every read is bounds-checked against the
 * window; offsets are relative to the window start.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BinaryReader
{
    private int $length;

    public function __construct(
        private string $data,
        private string $source,
        private int $offset = 0,
        ?int $length = null,
    ) {
        $available = \strlen($data) - $offset;
        $length ??= $available;

        if ($offset < 0 || $length < 0 || $length > $available) {
            throw new InvalidFontException(\sprintf('Font data window out of bounds in %s at offset %d for %d bytes.', $source, $offset, $length));
        }

        $this->length = $length;
    }

    public function length(): int
    {
        return $this->length;
    }

    /**
     * @internal
     */
    public function bytes(): string
    {
        if (0 === $this->offset && $this->length === \strlen($this->data)) {
            return $this->data;
        }

        return substr($this->data, $this->offset, $this->length);
    }

    public function uint8(int $offset): int
    {
        $this->assertRange($offset, 1);

        return \ord($this->data[$this->offset + $offset]);
    }

    public function int8(int $offset): int
    {
        $value = $this->uint8($offset);

        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    public function uint16(int $offset): int
    {
        $this->assertRange($offset, 2);
        $offset += $this->offset;

        return (\ord($this->data[$offset]) << 8) | \ord($this->data[$offset + 1]);
    }

    public function int16(int $offset): int
    {
        $value = $this->uint16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function int32(int $offset): int
    {
        $value = $this->uint32($offset);

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function uint32(int $offset): int
    {
        $this->assertRange($offset, 4);
        $offset += $this->offset;

        return (\ord($this->data[$offset]) << 24)
            | (\ord($this->data[$offset + 1]) << 16)
            | (\ord($this->data[$offset + 2]) << 8)
            | \ord($this->data[$offset + 3]);
    }

    public function fixed2Dot14(int $offset): float
    {
        return $this->int16($offset) / 16384.0;
    }

    public function fixed16Dot16(int $offset): float
    {
        $value = $this->uint32($offset);

        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }

        return $value / 65536.0;
    }

    public function string(int $offset, int $length): string
    {
        $this->assertRange($offset, $length);

        return substr($this->data, $this->offset + $offset, $length);
    }

    /**
     * Returns a reader over the record's byte range, sharing this reader's bytes.
     */
    public function table(TableRecord $record): self
    {
        $this->assertRange($record->offset, $record->length);

        return new self($this->data, $this->source . '#' . $record->tag, $this->offset + $record->offset, $record->length);
    }

    private function assertRange(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->length) {
            throw new InvalidFontException(\sprintf('Font data read out of bounds in %s at offset %d for %d bytes.', $this->source, $offset, $length));
        }
    }
}
