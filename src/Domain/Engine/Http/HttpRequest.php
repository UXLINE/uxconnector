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

/**
 * Represents an outgoing HTTP request to be sent through a
 * HttpEngineInterface, relative to an AuthConfig's Endpoint.
 *
 * RULE-VALUEOBJECT-001/002/005 : immutable, self-validating, and
 * every "mutation" (withHeader()) returns a new instance rather than
 * changing this one. Deliberately transport-agnostic: nothing here
 * mentions cURL, streams, or any other wire-level detail — that
 * belongs to the concrete HttpEngineInterface implementation.
 */
final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $queryParameters
     */
   public function __construct(
        public HttpMethod $method,
        public string $path,
        public array $headers = [],
        public array $queryParameters = [],
        public ?string $body = null
    ) {
      if (trim($this->path) === '') {
          throw new \InvalidArgumentException('HttpRequest path must not be empty.');
      }

      if (preg_match('/[\r\n\0]/', $this->path) === 1) {
          throw new \InvalidArgumentException('HttpRequest path must not contain control characters.');
      }

      foreach ($this->headers as $name => $value) {
         if (!is_string($name) || preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $name) !== 1) {
             throw new \InvalidArgumentException('HttpRequest contains an invalid HTTP header name.');
         }

         if (!is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
             throw new \InvalidArgumentException(sprintf(
                 'HttpRequest header "%s" contains an invalid value.',
                 $name
             ));
         }
      }

      if (!$this->method->allowsRequestBody() && $this->body !== null) {
          throw new \InvalidArgumentException(sprintf(
              'HTTP method "%s" does not allow a request body.',
              $this->method->value
          ));
      }
   }

    /**
     * Returns a copy of this request with one additional (or
     * overridden) header — HttpRequest never mutates in place.
     */
   public function withHeader(string $name, string $value): self {
       return new self(
           $this->method,
           $this->path,
           [...$this->headers, $name => $value],
           $this->queryParameters,
           $this->body
       );
   }
}
