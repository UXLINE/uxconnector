<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Exception;

/** A disconnected/replaced DB session must stop writing immediately. */
final class SyncExecutionOwnershipLostException extends \RuntimeException {}
