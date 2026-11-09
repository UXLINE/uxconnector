<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Exception;

/** Another worker owns the job or an overlapping target stream. */
final class SyncExecutionBusyException extends \RuntimeException {}
