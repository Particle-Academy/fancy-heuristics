<?php

declare(strict_types=1);

namespace FancyHeuristics\Exceptions;

use RuntimeException;

/**
 * A page fetch was refused: the URL (or a redirect hop) is not a public
 * http(s) address, or the response broke a limit.
 */
class UnsafeUrlException extends RuntimeException {}
