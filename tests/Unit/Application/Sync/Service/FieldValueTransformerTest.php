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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Service\FieldValueTransformer;
use GlpiPlugin\Uxconnector\Infrastructure\Template\Transform\JiraAdfToTextTransform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldValueTransformer::class)]
final class FieldValueTransformerTest extends TestCase
{
   public function testNullTransformReturnsValueUnchanged(): void {
       $transformer = new FieldValueTransformer();

       self::assertSame('  raw  ', $transformer->apply('  raw  ', null));
   }

   public function testTrimTransform(): void {
       $transformer = new FieldValueTransformer();

       self::assertSame('hello', $transformer->apply('  hello  ', 'trim'));
   }

   public function testUppercaseTransform(): void {
       $transformer = new FieldValueTransformer();

       self::assertSame('HELLO', $transformer->apply('hello', 'uppercase'));
   }

   public function testLowercaseTransform(): void {
       $transformer = new FieldValueTransformer();

       self::assertSame('hello', $transformer->apply('HELLO', 'lowercase'));
   }

   public function testDateIso8601TransformFromString(): void {
       $transformer = new FieldValueTransformer();

       $result = $transformer->apply('2026-01-15T10:30:00+00:00', 'date_iso8601');

       self::assertSame('2026-01-15T10:30:00+00:00', $result);
   }

   public function testDateIso8601TransformRejectsUnparsableString(): void {
       $transformer = new FieldValueTransformer();

       $this->expectException(\RuntimeException::class);

       $transformer->apply('not-a-date', 'date_iso8601');
   }

   public function testUnsupportedTransformNameIsRejected(): void {
       $transformer = new FieldValueTransformer();

       $this->expectException(\InvalidArgumentException::class);

       $transformer->apply('value', 'reverse');
   }

   public function testNonStringValueIsLeftUnchangedByTrimUppercaseLowercase(): void {
       $transformer = new FieldValueTransformer();

       self::assertSame(42, $transformer->apply(42, 'trim'));
       self::assertSame(42, $transformer->apply(42, 'uppercase'));
       self::assertSame(42, $transformer->apply(42, 'lowercase'));
   }
   public function testGlpiDateTimeTransform(): void {
       self::assertSame(
           '2026-08-18 13:42:15',
           (new FieldValueTransformer())->apply('2026-08-18T13:42:15+00:00', 'datetime_glpi')
       );
   }

   public function testConfigurableValueMapReturnsTypedScalar(): void {
       $transformer = new FieldValueTransformer();
       self::assertSame(6, $transformer->apply('Highest', 'map:Highest=6|High=5|*=3'));
       self::assertSame(3, $transformer->apply('Unknown', 'map:Highest=6|High=5|*=3'));
   }

   public function testJiraAdfDocumentIsFlattenedToText(): void {
       $adf = [
           'type' => 'doc',
           'version' => 1,
           'content' => [
               ['type' => 'paragraph', 'content' => [
                   ['type' => 'text', 'text' => 'Hello '],
                   ['type' => 'text', 'text' => 'Jira'],
               ]],
               ['type' => 'paragraph', 'content' => [
                   ['type' => 'text', 'text' => 'Second line'],
               ]],
           ],
       ];

       self::assertSame("Hello Jira\nSecond line", (new FieldValueTransformer([new JiraAdfToTextTransform()]))->apply($adf, 'jira_adf_to_text'));
   }

}
