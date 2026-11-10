<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

final class PublicVerificationTest extends TestCase
{
    public function testAnUnreadableRecordWithholdsTheBadgeAndLogsTheFailure(): void
    {
        $id = 'apr_'.str_repeat('a', 32);
        $error = new ApprovalException('corrupt_proposal', 'The stored proposal failed its integrity check.');
        $store = $this->createMock(ApprovalStore::class);
        $store->expects(self::once())->method('get')->with($id)->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Approval evidence could not be verified for a public badge.',
            ['requestId' => $id, 'exception' => $error],
        );
        $keys = TrustedKeys::single('test', ChallengeBinding::encode(str_repeat('k', 32)));

        self::assertNull((new PublicVerification($store, $keys, $logger))->check($id));
    }
}
