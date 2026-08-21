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

namespace Alto\Font\Exception;

/**
 * Reports a valid font feature that is not supported.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class UnsupportedFontException extends \RuntimeException implements FontExceptionInterface {}
