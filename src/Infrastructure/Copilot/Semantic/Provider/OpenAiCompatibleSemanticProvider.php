<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Provider;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketClassification;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketInput;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum\SemanticTicketClass;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticClassificationProviderInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;

/**
 * Semantic classification through an OpenAI-compatible Chat Completions API.
 *
 * Transport, credentials, vault access and destination security remain owned by
 * GenericHttpEngine. This provider knows only the semantic request/response
 * contract.
 *
 * The provider deliberately validates the returned JSON itself rather than
 * trusting an LLM response. A malformed, duplicate, incomplete or unknown
 * ticket classification fails the whole batch.
 */
final readonly class OpenAiCompatibleSemanticProvider implements SemanticClassificationProviderInterface
{
   private const CONNECTOR_ID = 'openai-compatible';

   public function __construct(
        private SemanticEngineConfig $config,
        private AuthConfig $connection,
        private HttpEngineInterface $http
    ) {
      if (!$this->config->isUsable()) {
          throw new \RuntimeException('The semantic engine configuration must be active before classification.');
      }

      if (!$this->connection->isUsable()) {
          throw new \RuntimeException('The semantic engine connection must be active before classification.');
      }

      if ($this->connection->connectorId() !== self::CONNECTOR_ID) {
          throw new \RuntimeException(sprintf(
              'Semantic classification requires connector type "%s"; got "%s".',
              self::CONNECTOR_ID,
              $this->connection->connectorId()
          ));
      }
   }

   public function classify(array $tickets): array {
      if ($tickets === []) {
          return [];
      }

      foreach ($tickets as $ticket) {
         if (!$ticket instanceof SemanticTicketInput) {
             throw new \InvalidArgumentException(
                 'SemanticClassificationProviderInterface::classify() expects SemanticTicketInput objects.'
             );
         }
      }

       $seenInputIds = [];
      foreach ($tickets as $ticket) {
         if (isset($seenInputIds[$ticket->ticketId])) {
             throw new \InvalidArgumentException(sprintf(
                 'Duplicate semantic input ticket id %d.',
                 $ticket->ticketId
             ));
         }
          $seenInputIds[$ticket->ticketId] = true;
      }

       $results = [];

      $batchSize = $this->config->batchSize();
      if ($batchSize < 1) {
         throw new \LogicException(
            'Semantic provider batch size must be greater than zero.'
         );
      }

      foreach (array_chunk($tickets, $batchSize) as $batch) {
         foreach ($this->classifyBatch($batch) as $classification) {
             $results[] = $classification;
         }
      }

       return $results;
   }

    /**
     * @param list<SemanticTicketInput> $tickets
     * @return list<SemanticTicketClassification>
     */
   private function classifyBatch(array $tickets): array {
       $body = $this->encodeJson([
           'model' => $this->config->model(),
           'temperature' => $this->config->temperature(),
           'messages' => [
               [
                   'role' => 'system',
                   'content' => $this->systemInstruction(),
               ],
               [
                   'role' => 'user',
                   'content' => $this->encodeJson([
                       'classifier_version' => $this->config->classifierVersion(),
                       'tickets' => array_map(
                           static fn (SemanticTicketInput $ticket): array => $ticket->toProviderArray(),
                           $tickets
                       ),
                   ]),
               ],
           ],
           // JSON mode is intentionally used for broad Chat Completions
           // compatibility. UXConnector still performs strict structural and
           // semantic validation of the returned payload below.
           'response_format' => [
               'type' => 'json_object',
           ],
       ]);

       $response = $this->http->execute(
           $this->connection,
           new HttpRequest(
               HttpMethod::POST,
               $this->config->inferencePath(),
               [
                   'Accept' => 'application/json',
                   'Content-Type' => 'application/json',
               ],
               [],
               $body
           )
       );

      if (!$response->isSuccessful()) {
          throw new \RuntimeException(sprintf(
              'Semantic inference upstream returned HTTP %d.',
              $response->statusCode
          ));
      }

       $envelope = $this->decodeJsonObject($response->body, 'upstream response');

       $content = $envelope['choices'][0]['message']['content'] ?? null;
      if (!is_string($content) || trim($content) === '') {
          throw new \RuntimeException(
              'Semantic inference response does not contain choices[0].message.content.'
          );
      }

       $payload = $this->decodeJsonObject($content, 'assistant classification payload');

       $rows = $payload['classifications'] ?? null;
      if (!is_array($rows) || !array_is_list($rows)) {
          throw new \RuntimeException(
              'Semantic classification payload must contain a classifications list.'
          );
      }

      if (count($rows) !== count($tickets)) {
          throw new \RuntimeException(sprintf(
              'Semantic classification batch returned %d rows for %d tickets.',
              count($rows),
              count($tickets)
          ));
      }

       $expected = [];
      foreach ($tickets as $ticket) {
          $expected[$ticket->ticketId] = true;
      }

       $seen = [];
       $resultByTicket = [];

      foreach ($rows as $row) {
         if (!is_array($row) || array_is_list($row)) {
             throw new \RuntimeException('Each semantic classification must be a JSON object.');
         }

         foreach (['ticket_id', 'class', 'confidence', 'reason_code'] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new \RuntimeException(sprintf(
                    'Semantic classification is missing required field "%s".',
                    $required
                ));
            }
         }

         if (!is_int($row['ticket_id']) && !(is_string($row['ticket_id']) && ctype_digit($row['ticket_id']))) {
             throw new \RuntimeException('Semantic classification ticket_id must be an integer.');
         }

          $ticketId = (int) $row['ticket_id'];

         if (!isset($expected[$ticketId])) {
             throw new \RuntimeException(sprintf(
                 'Semantic classification returned unknown ticket id %d.',
                 $ticketId
             ));
         }

         if (isset($seen[$ticketId])) {
             throw new \RuntimeException(sprintf(
                 'Semantic classification returned duplicate ticket id %d.',
                 $ticketId
             ));
         }
          $seen[$ticketId] = true;

         if (!is_string($row['class'])) {
             throw new \RuntimeException('Semantic classification class must be a string.');
         }

          $predictedClass = SemanticTicketClass::tryFrom($row['class']);
         if ($predictedClass === null) {
             throw new \RuntimeException(sprintf(
                 'Semantic classification returned unsupported class "%s".',
                 $row['class']
             ));
         }

         if (!is_int($row['confidence']) && !is_float($row['confidence'])) {
             throw new \RuntimeException(
                 'Semantic classification confidence must be a JSON number.'
             );
         }

          $confidence = (float) $row['confidence'];
         if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
             throw new \RuntimeException(
                 'Semantic classification confidence must be between 0 and 1.'
             );
         }

         if (!is_string($row['reason_code'])) {
             throw new \RuntimeException('Semantic classification reason_code must be a string.');
         }

          $reasonCode = trim($row['reason_code']);
         if ($reasonCode === ''
              || strlen($reasonCode) > 64
              || preg_match('/^[a-z0-9][a-z0-9_]*$/D', $reasonCode) !== 1
          ) {
             throw new \RuntimeException(
                 'Semantic classification reason_code must be lower snake_case and at most 64 characters.'
             );
         }

          $thresholdApplied = $confidence < $this->config->confidenceThreshold();
          $effectiveClass = $thresholdApplied
              ? SemanticTicketClass::UNCERTAIN
              : $predictedClass;

          $resultByTicket[$ticketId] = new SemanticTicketClassification(
              $ticketId,
              $effectiveClass,
              $predictedClass,
              $confidence,
              $reasonCode,
              $thresholdApplied
          );
      }

      foreach (array_keys($expected) as $ticketId) {
         if (!isset($seen[$ticketId])) {
             throw new \RuntimeException(sprintf(
                 'Semantic classification is missing ticket id %d.',
                 $ticketId
             ));
         }
      }

       // Preserve exactly the input ticket order for deterministic downstream
       // aggregation.
       return array_map(
           static fn (SemanticTicketInput $ticket): SemanticTicketClassification => $resultByTicket[$ticket->ticketId],
           $tickets
       );
   }

   private function systemInstruction(): string {
       return implode("\n", [
           'You are a deterministic ITSM ticket semantic classifier.',
           'Return only one JSON object with the key "classifications".',
           'Return exactly one classification for every supplied ticket_id and no additional ticket.',
           'Allowed class values: ' . implode(', ', SemanticTicketClass::values()) . '.',
           'Use title and description as the primary semantic evidence.',
           'declared_type and itil_category are contextual metadata only and must not be treated as the semantic result.',
           'technical_incident: a technical malfunction, outage, degradation or failure.',
           'user_assistance: the user primarily needs guidance, help, explanation or procedural assistance.',
           'access_request: request to create, grant, modify, unlock or remove access, account, permission or entitlement.',
           'usage_error: the reported issue is primarily caused by incorrect usage, manipulation or configuration by the user rather than a technical failure.',
           'other: semantically meaningful but none of the previous classes fit.',
           'uncertain: evidence is insufficient or genuinely ambiguous.',
           'confidence must be a JSON number from 0 to 1.',
           'reason_code must be a concise lower_snake_case machine code, not free prose.',
           'Do not add explanations, markdown or fields outside ticket_id, class, confidence and reason_code inside each classification.',
           'Expected shape: {"classifications":[{"ticket_id":123,"class":"technical_incident","confidence":0.95,"reason_code":"service_unavailable"}]}',
       ]);
   }

    /**
     * @param array<string, mixed> $value
     */
   private function encodeJson(array $value): string {
      try {
          return json_encode(
              $value,
              JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
          );
      } catch (\JsonException $exception) {
          throw new \RuntimeException(
              'Unable to encode semantic inference request as JSON.',
              previous: $exception
          );
      }
   }

    /**
     * @return array<string, mixed>
     */
   private function decodeJsonObject(string $json, string $label): array {
      try {
          $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
      } catch (\JsonException $exception) {
          throw new \RuntimeException(
              sprintf('Semantic %s is not valid JSON.', $label),
              previous: $exception
          );
      }

      if (!is_array($decoded) || array_is_list($decoded)) {
          throw new \RuntimeException(sprintf(
              'Semantic %s must be a JSON object.',
              $label
          ));
      }

       return $decoded;
   }
}
