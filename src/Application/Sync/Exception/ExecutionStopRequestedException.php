<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Exception;

/** Cooperative interruption; never classify as a failed source record. */
final class ExecutionStopRequestedException extends \RuntimeException
{
}
