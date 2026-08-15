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

namespace Alto\Font\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Table\TableRecord;

/**
 * Immutable SFNT table set shared by parsed views and transformations.
 *
 * @internal
 */
final readonly class SfntDocument
{
    /**
     * @param array<string, TableRecord> $records
     * @param array<string, string>      $replacements
     * @param array<string, true>        $removed
     */
    public function __construct(
        private BinaryReader $reader,
        public string $flavor,
        private array $records,
        public string $sourcePath,
        public int $faceIndex,
        public int $faceCount,
        private bool $standalone,
        private array $replacements = [],
        private array $removed = [],
    ) {}

    public function table(string $tag): ?string
    {
        self::validateTag($tag);

        if (isset($this->removed[$tag])) {
            return null;
        }

        if (isset($this->replacements[$tag])) {
            return $this->replacements[$tag];
        }

        $record = $this->records[$tag] ?? null;

        return null === $record ? null : $this->reader->string($record->offset, $record->length);
    }

    /**
     * @return list<string>
     */
    public function tableTags(): array
    {
        $tags = array_fill_keys(array_keys($this->records), true);

        foreach ($this->replacements as $tag => $_data) {
            $tags[$tag] = true;
        }

        foreach ($this->removed as $tag => $_removed) {
            unset($tags[$tag]);
        }

        $result = array_keys($tags);
        sort($result);

        return $result;
    }

    /**
     * @param array<string, string> $replacements
     * @param iterable<string>      $removed
     */
    public function withTables(array $replacements, iterable $removed = []): self
    {
        $nextReplacements = $this->replacements;
        $nextRemoved = $this->removed;

        foreach ($replacements as $tag => $data) {
            self::validateTag($tag);
            $nextReplacements[$tag] = $data;
            unset($nextRemoved[$tag]);
        }

        foreach ($removed as $tag) {
            self::validateTag($tag);
            $nextRemoved[$tag] = true;
            unset($nextReplacements[$tag]);
        }

        if (isset($this->records['DSIG']) || isset($nextReplacements['DSIG'])) {
            $nextRemoved['DSIG'] = true;
            unset($nextReplacements['DSIG']);
        }

        return new self(
            $this->reader,
            $this->flavor,
            $this->records,
            $this->sourcePath,
            $this->faceIndex,
            $this->faceCount,
            false,
            $nextReplacements,
            $nextRemoved,
        );
    }

    public function toSfnt(): string
    {
        if ($this->standalone && [] === $this->replacements && [] === $this->removed) {
            return $this->reader->bytes();
        }

        $tables = [];

        foreach ($this->tableTags() as $tag) {
            if ('DSIG' === $tag) {
                continue;
            }

            $table = $this->table($tag);

            if (null === $table) {
                throw new InvalidFontException(\sprintf('SFNT table "%s" disappeared while building the document.', $tag));
            }

            $tables[$tag] = $table;
        }

        return SfntBuilder::build($this->flavor, $tables);
    }

    private static function validateTag(string $tag): void
    {
        if (4 !== \strlen($tag)) {
            throw new InvalidFontException(\sprintf('SFNT table tag "%s" must contain exactly 4 bytes.', $tag));
        }
    }
}
