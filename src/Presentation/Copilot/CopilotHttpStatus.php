<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Copilot;

/**
 * Sends an HTTP status without relying on http_response_code().
 */
final class CopilotHttpStatus
{
   private const REASONS = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        413 => 'Payload Too Large',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

   public static function send(int $status): void {
       $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

      if (!in_array(
               $protocol,
               ['HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3'],
               true
           )
       ) {
          $protocol = 'HTTP/1.1';
      }

       $reason = self::REASONS[$status] ?? '';

       header(
           sprintf(
               '%s %d%s',
               $protocol,
               $status,
               $reason !== '' ? ' ' . $reason : ''
           ),
           true
       );
   }
}
