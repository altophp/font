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
 * Rebuilds a font exception from its class, message and code, so the result
 * carries neither the original trace nor the input bytes that trace referenced.
 *
 * The parameters are scalars on purpose: passing the original exception
 * through this frame would keep it, and its trace, alive.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class ExceptionDetacher
{
    /**
     * @param class-string<FontExceptionInterface> $class
     */
    public static function detach(string $class, string $message, int $code): FontExceptionInterface
    {
        return new $class($message, $code);
    }
}
