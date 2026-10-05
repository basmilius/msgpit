<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

use RuntimeException;

/** A transient DNS problem: a timeout or a SERVFAIL. The evaluation ends in temperror. */
final class ResolverFailure extends RuntimeException {}
