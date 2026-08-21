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

/**
 * Selects which OpenType layout data is retained.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum LayoutPolicy
{
    case Preserve;
    case SubstitutionsOnly;
    case Drop;
}
