<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Engine\Http;

use RuntimeException;

/**
 * Represents the result of an HttpEngineInterface::execute() call.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
   public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body
    ) {
   }

   public function isSuccessful(): bool {
       return $this->statusCode >= 200 && $this->statusCode < 300;
   }

   public function isClientError(): bool {
       return $this->statusCode >= 400 && $this->statusCode < 500;
   }

   public function isServerError(): bool {
       return $this->statusCode >= 500 && $this->statusCode < 600;
   }

   public function header(string $name): ?string {
      foreach ($this->headers as $headerName => $value) {
         if (strcasecmp($headerName, $name) === 0) {
            return $value;
         }
      }

       return null;
   }

    /**
     * Decodes the body as JSON.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException if the body is not valid JSON.
     */
   public function json(): array {
      try {
          /** @var array<string, mixed> $decoded */
          $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
      } catch (\JsonException $exception) {
          throw new \RuntimeException(
              'HttpResponse body is not valid JSON.',
              previous: $exception
          );
      }

       return $decoded;
   }
}
