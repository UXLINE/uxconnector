<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Template\Transform;

use GlpiPlugin\Uxconnector\Application\Sync\Transform\FieldTransformInterface;

/** Best-effort Atlassian Document Format to plain-text transform. */
final class JiraAdfToTextTransform implements FieldTransformInterface
{
   public function name(): string {
       return 'jira_adf_to_text';
   }

   public function apply(mixed $value): string {
      if ($value === null) {
          return '';
      }

      if (is_string($value)) {
          return $value;
      }

      if (!is_array($value)) {
          return (string) $value;
      }

       return trim($this->extractNode($value));
   }

    /** @param array<string,mixed> $node */
   private function extractNode(array $node): string {
      if (($node['type'] ?? null) === 'text') {
          return (string) ($node['text'] ?? '');
      }

      if (($node['type'] ?? null) === 'hardBreak') {
          return "\n";
      }

       $parts = [];
       $content = $node['content'] ?? [];
      if (is_array($content)) {
         foreach ($content as $child) {
            if (is_array($child)) {
               $parts[] = $this->extractNode($child);
            }
         }
      }

       $text = implode('', $parts);
       $type = (string) ($node['type'] ?? '');
      if (in_array($type, ['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock', 'bulletList', 'orderedList'], true)) {
          return $text . "\n";
      }

       return $text;
   }
}
