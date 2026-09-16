<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

/** Converts advanced manual-job form fields into generic pagination configuration. */
final class PaginationConfigRequestParser
{
    /** @param array<string,mixed> $request */
   public function parse(array $request): PaginationConfig {
       $type = trim((string) ($request['pagination_type'] ?? 'none'));
      if ($type === '' || $type === 'none') {
          return PaginationConfig::none();
      }

       $options = [];
      foreach ([
           'cursor_response_path',
           'cursor_request_parameter',
           'last_page_response_path',
           'offset_response_path',
           'offset_request_parameter',
           'page_size_response_path',
           'page_size',
           'total_response_path',
           'next_link_response_path',
       ] as $key) {
          $value = trim((string) ($request[$key] ?? ''));
         if ($value !== '') {
             $options[$key] = $value;
         }
      }

       return PaginationConfig::fromArray($type, $options);
   }
}
