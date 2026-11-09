<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use DateTimeImmutable;
use DateTimeZone;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

final class JobScheduleRequestParser
{
    /** @param array<string,mixed> $request */
    public function apply(PaginationConfig $config, array $request): PaginationConfig
    {
        if (!isset($request['schedule_options_present'])) {
            return $config;
        }
        $raw = $request['schedule_interval_minutes'] ?? '60';
        if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 43200]]) === false) {
            throw new \InvalidArgumentException('La périodicité doit être comprise entre 5 et 43200 minutes.');
        }
        $start = $request['schedule_start_at'] ?? '';
        if (!is_string($start)) {
            throw new \InvalidArgumentException('Date de premier passage invalide.');
        }
        $iso = null;
        if ($start !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $start, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i') !== $start) {
                throw new \InvalidArgumentException('Date de premier passage UTC invalide.');
            }
            $iso = $date->format('Y-m-d\TH:i:s\Z');
        }
        $options = $config->options();
        $options['zendesk_download_attachments'] = ($request['zendesk_download_attachments'] ?? '') === '1';
        $options['schedule_enabled'] = ($request['schedule_enabled'] ?? '') === '1';
        $options['schedule_interval_minutes'] = (int) $raw;
        $options['schedule_start_at'] = $iso;
        $options['schedule_retry_after_failure'] = ($request['schedule_retry_after_failure'] ?? '') === '1';
        return new PaginationConfig($config->type(), $options);
    }
}
