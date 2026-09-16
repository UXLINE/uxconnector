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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\AuthenticationStrategyFactory;

/**
 * Generic, connector-agnostic HTTP client: given any AuthConfig and
 * any HttpRequest, it resolves the right authentication strategy,
 * sends the request, and returns the raw HttpResponse. No connector
 * ever needs its own HTTP client (ADR-002 : the whole point of the
 * v2.0.0 pivot).
 *
 * Built on PHP's native stream wrappers (fopen() + a stream context)
 * rather than ext-curl. A code review pointed out, correctly, that
 * GLPI itself already requires ext-curl as a core prerequisite — so
 * "no extra dependency" is a weaker argument than originally stated.
 * The choice to stay on streams for now is deliberate anyway: it
 * still works everywhere GLPI works, and this class already carries
 * the security-critical logic (redirect policy, response size limit,
 * authentication strategy resolution) — migrating the transport
 * itself to curl is a separate, purely mechanical change that does
 * not need to block hardening this class first.
 *
 * CORRECTIFS P0 (revue de code externe) appliqués dans cette classe :
 * - `follow_location` désactivé : le comportement par défaut de PHP
 *   suivait automatiquement les redirections HTTP en renvoyant les
 *   en-têtes d'authentification (Authorization, X-Api-Key) vers
 *   l'URL de destination, y compris vers un autre hôte — un risque
 *   de fuite de secret.
 * - Lecture de la réponse par blocs bornés (`fopen()`/`fread()`)
 *   plutôt que `file_get_contents()` : une API tierce mal configurée
 *   ou compromise renvoyant un corps de réponse volumineux ne peut
 *   plus épuiser la mémoire disponible.
 *
 * CORRECTIF SÉCURITÉ (SSRF, revue de code externe) : chaque appel
 * réel vérifie désormais la destination via `DestinationPolicyInterface`
 * avant d'ouvrir la moindre connexion — y compris pour un `AuthConfig`
 * créé avant l'introduction de cette politique, ou dont la résolution
 * DNS a changé depuis sa création (voir CreateAuthConfigService pour
 * la vérification, plus légère, faite à la création).
 */
final class GenericHttpEngine implements HttpEngineInterface
{
   private const TIMEOUT_SECONDS = 30;
   private const USER_AGENT = 'UXConnector/2.0 (+https://www.helpline.fr)';

    /**
     * CORRECTIF P0 (revue de code externe) : borne la mémoire
     * consommée par une réponse, quelle que soit la taille réellement
     * annoncée ou envoyée par le système tiers.
     */
   private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024; // 10 MiB
   private const READ_CHUNK_BYTES = 8192;
   private const MAX_RATE_LIMIT_RETRIES = 5;
   private const MAX_RETRY_AFTER_SECONDS = 120;

   public function __construct(
        private readonly AuthConfigSecretStoreInterface $secretStore,
        private readonly CredentialVaultInterface $vault,
        private readonly AuthenticationStrategyFactory $strategyFactory,
        private readonly DestinationPolicyInterface $destinationPolicy
    ) {
   }

   public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse {
       // Never transmit reusable credentials over clear-text HTTP. This
       // also protects AuthConfig rows created by an older plugin release
       // before the creation-time guard existed. Unauthenticated HTTP is
       // still allowed for deliberately public/internal endpoints.
      if ($authConfig->authMethod()->requiresVaultedCredentials() && !$authConfig->endpoint()->isSecure()) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::insecureCredentialTransport($authConfig->endpoint()->value());
      }

       // CORRECTIF SÉCURITÉ (SSRF) : vérifié à chaque exécution, pas
       // seulement à la création de l'AuthConfig — une résolution
       // DNS peut changer entre les deux (voir le doc-block de
       // DestinationPolicy pour la limite résiduelle : le "DNS
       // rebinding" entre cette vérification et la connexion
       // effective n'est pas couvert).
       $this->destinationPolicy->assertAllowed($authConfig->endpoint());

       $secret = $this->resolveSecret($authConfig);

       $strategy = $this->strategyFactory->create($authConfig->authMethod(), $secret);
       $authenticatedRequest = $strategy->authenticate($request);

       return $this->sendWithRateLimitRetry(
           $this->buildUrl($authConfig, $authenticatedRequest),
           $authenticatedRequest
       );
   }

   /**
    * Retries HTTP 429 responses using Retry-After. This is deliberately kept
    * inside the generic HTTP engine so paginated calls, comment retrieval and
    * every other authenticated connector call get the same protection.
    */
   private function sendWithRateLimitRetry(string $url, HttpRequest $request): HttpResponse
   {
      $attempt = 0;

      while (true) {
         $response = $this->send($url, $request);
         if ($response->statusCode !== 429 || $attempt >= self::MAX_RATE_LIMIT_RETRIES) {
            return $response;
         }

         $attempt++;
         $retryAfter = $this->retryAfterSeconds($response);
         if ($retryAfter > 0) {
            sleep($retryAfter);
         }
      }
   }

   private function retryAfterSeconds(HttpResponse $response): int
   {
      $raw = trim((string) ($response->header('Retry-After') ?? '1'));
      if ($raw === '') {
         return 1;
      }

      if (ctype_digit($raw)) {
         return min(self::MAX_RETRY_AFTER_SECONDS, max(1, (int) $raw));
      }

      $timestamp = strtotime($raw);
      if ($timestamp === false) {
         return 1;
      }

      return min(self::MAX_RETRY_AFTER_SECONDS, max(1, $timestamp - time()));
   }

   private function resolveSecret(AuthConfig $authConfig): ?string {
      if (!$authConfig->authMethod()->requiresVaultedCredentials()) {
          return null;
      }

       $id = $authConfig->id();

      if ($id === null) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::authConfigNotPersisted();
      }

       $protectedSecret = $this->secretStore->get($id);

      if ($protectedSecret === null) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::missingCredential($authConfig->authMethod());
      }

       return $this->vault->reveal($protectedSecret);
   }

   private function buildUrl(AuthConfig $authConfig, HttpRequest $request): string {
       $url = rtrim($authConfig->endpoint()->value(), '/') . '/' . ltrim($request->path, '/');

      if ($request->queryParameters !== []) {
          $url .= '?' . http_build_query($request->queryParameters);
      }

       return $url;
   }

   private function send(string $url, HttpRequest $request): HttpResponse {
       $context = stream_context_create([
           'http' => [
               'method'        => $request->method->value,
               'header'        => $this->formatHeaders($request),
               'content'       => $request->body ?? '',
               'timeout'       => self::TIMEOUT_SECONDS,
               // We parse the status code ourselves from the
               // stream's wrapper_data, so a 4xx/5xx must not turn
               // into a stream warning/false return — it is a valid
               // HttpResponse, not a transport failure.
               'ignore_errors' => true,
               // CORRECTIF P0 : ne jamais suivre une redirection
               // automatiquement — voir le doc-block de la classe.
               'follow_location' => 0,
               'max_redirects' => 0,
           ],
       ]);

       $stream = @fopen($url, 'rb', false, $context);

      if ($stream === false) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(
              error_get_last()['message'] ?? 'unknown stream error'
          );
      }

      try {
          $body = $this->readWithSizeLimit($stream);
          $meta = stream_get_meta_data($stream);
      } finally {
          fclose($stream);
      }

       /** @var list<string> $responseHeaderLines */
       $responseHeaderLines = $meta['wrapper_data'] ?? [];

       return new HttpResponse(
           $this->parseStatusCode($responseHeaderLines),
           $this->parseHeaders($responseHeaderLines),
           $body
       );
   }

    /**
     * Reads the response body in bounded chunks rather than loading
     * it in a single unbounded call — CORRECTIF P0 (revue de code
     * externe) : sans cette limite, une API tierce renvoyant un corps
     * de réponse volumineux (mal configurée, ou compromise) pouvait
     * épuiser la mémoire disponible du processus PHP.
     *
     * @param resource $stream
     *
     * @throws HttpEngineException if the response exceeds MAX_RESPONSE_BYTES.
     */
   private function readWithSizeLimit($stream): string {
       $body = '';
       $bytesRead = 0;

      while (!feof($stream)) {
          $chunk = fread($stream, self::READ_CHUNK_BYTES);

         if ($chunk === false) {
            break;
         }

          $bytesRead += strlen($chunk);

         if ($bytesRead > self::MAX_RESPONSE_BYTES) {
             throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(sprintf(
                 'Response body exceeded the %d byte limit.',
                 self::MAX_RESPONSE_BYTES
             ));
         }

          $body .= $chunk;
      }

       return $body;
   }

   private function formatHeaders(HttpRequest $request): string {
       $lines = [];

      foreach ($request->headers as $name => $value) {
          $lines[] = "$name: $value";
      }

       $lines[] = 'User-Agent: ' . self::USER_AGENT;

       return implode("\r\n", $lines);
   }

    /**
     * With `follow_location` désactivé (voir send()), la réponse ne
     * devrait plus jamais contenir plusieurs hops — cette méthode
     * reste un filet de sécurité si ce comportement changeait un jour
     * (ex. lors de l'introduction contrôlée d'une politique de
     * redirection). Ne conserve que le dernier bloc "HTTP/..." trouvé.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
   private function lastHopLines(array $lines): array {
       $lastStatusLineIndex = 0;

      foreach ($lines as $index => $line) {
         if (str_starts_with($line, 'HTTP/')) {
            $lastStatusLineIndex = $index;
         }
      }

       return array_slice($lines, $lastStatusLineIndex);
   }

    /**
     * @param list<string> $lines
     */
   private function parseStatusCode(array $lines): int {
       $lastHop = $this->lastHopLines($lines);

      if (!isset($lastHop[0]) || preg_match('#^HTTP/\S+\s+(\d{3})#', $lastHop[0], $matches) !== 1) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure('Malformed or missing HTTP status line.');
      }

       return (int) $matches[1];
   }

    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
   private function parseHeaders(array $lines): array {
       $headers = [];

      foreach (array_slice($this->lastHopLines($lines), 1) as $line) {
          $parts = explode(':', $line, 2);

         if (count($parts) === 2) {
            $headers[trim($parts[0])] = trim($parts[1]);
         }
      }

       return $headers;
   }
}
