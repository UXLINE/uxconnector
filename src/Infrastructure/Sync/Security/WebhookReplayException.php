<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Security;

/** A verified signature has already reserved this delivery. */
final class WebhookReplayException extends \RuntimeException
{
}
