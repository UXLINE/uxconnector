<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Sync\Policy;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\BuiltinTemplateTargetPolicy;
use PHPUnit\Framework\TestCase;

final class BuiltinTemplateTargetPolicyTest extends TestCase
{
    public function testZendeskBootstrapTargetsRemainAllowed(): void
    {
        $policy = new BuiltinTemplateTargetPolicy();
        $policy->assertAllowed('zendesk.users.bootstrap', TargetItemtype::USER);
        $policy->assertAllowed('zendesk.groups.bootstrap', TargetItemtype::GROUP);
        $policy->assertAllowed('zendesk.organizations.bootstrap', TargetItemtype::ENTITY);
        $policy->assertAllowed('zendesk.itilcategories.bootstrap', TargetItemtype::ITIL_CATEGORY);
        $policy->assertAllowed('zendesk.tickets.nonclosed.inbound', TargetItemtype::TICKET);
        $this->addToAssertionCount(5);
    }

    public function testZendeskTicketTemplateCannotBeRepurposedToUsers(): void
    {
        $this->expectException(\DomainException::class);
        (new BuiltinTemplateTargetPolicy())->assertAllowed(
            'zendesk.tickets.nonclosed.inbound',
            TargetItemtype::USER
        );
    }

    public function testAdHocJobsKeepExistingGenericCapabilities(): void
    {
        (new BuiltinTemplateTargetPolicy())->assertAllowed(null, TargetItemtype::USER);
        $this->addToAssertionCount(1);
    }
}
