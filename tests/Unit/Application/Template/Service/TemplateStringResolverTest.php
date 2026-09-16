<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Template\Service;

use GlpiPlugin\Uxconnector\Application\Template\Service\TemplateStringResolver;
use GlpiPlugin\Uxconnector\Domain\Template\Entity\TemplateParameter;
use PHPUnit\Framework\TestCase;

final class TemplateStringResolverTest extends TestCase
{
   public function testParametersAreResolvedIntoConcreteRuntimeString(): void {
       $resolver = new TemplateStringResolver();
       $parameters = $resolver->resolveParameters(
           [new TemplateParameter('project_key', 'Project key', true)],
           ['project_key' => 'kan test']
       );

       self::assertSame(
           '/search?q=KAN%20TEST',
           $resolver->resolveString('/search?q={{project_key|upper|urlencode}}', $parameters)
       );
   }

   public function testUnknownPlaceholderIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);
       (new TemplateStringResolver())->resolveString('/{{missing}}', []);
   }
}
