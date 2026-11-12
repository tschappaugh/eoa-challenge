<?php

declare(strict_types=1);

namespace App\Eoa;

use RuntimeException;

/** The EOA API could not be reached or returned something unusable. */
final class UpstreamUnavailable extends RuntimeException
{
}
