<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Enum;

enum SyncErrorCategory: string
{
    case BUSINESS = 'business';
    case AUTHENTICATION = 'authentication';
    case RATE_LIMIT = 'rate_limit';
    case TRANSIENT_HTTP = 'transient_http';
    case TRANSPORT = 'transport';
    case VALIDATION = 'validation';
    case TARGET_WRITE = 'target_write';
    case POST_PROCESSING = 'post_processing';
    case INFRASTRUCTURE = 'infrastructure';
    case UNKNOWN = 'unknown';
}
