<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/** The bundle's receipt_keys configuration: the signer's key and the retired ones, each with its window. */
final class ReceiptKeysConfigurationTest extends TestCase
{
    private SodiumEvidenceSigner $signer;
    private string $retired;

    protected function setUp(): void
    {
        $this->signer = new SodiumEvidenceSigner('application-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->retired = ChallengeBinding::encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }

    public function testRetiredKeysInConfigurationJoinTheSignersKeyWithTheirWindows(): void
    {
        $config = $this->process(['receipt_signer' => 'app.receipt_signer', 'receipt_keys' => [
            'valid_from' => '2026-11-09T12:00:00Z',
            'retired' => [['key_id' => 'application-1', 'public_key' => $this->retired, 'valid_from' => '2026-01-05T09:00:00+01:00', 'valid_until' => '2026-11-09T12:05:00Z']],
        ]]);
        $keys = TrustedKeys::forSigner($this->signer, $config['receipt_keys']['valid_from'], $config['receipt_keys']['retired']);
        self::assertSame([
            ['keyId' => 'application-2', 'publicKey' => $this->signer->publicKey(), 'validFrom' => '2026-11-09T12:00:00.000000Z', 'validUntil' => null],
            ['keyId' => 'application-1', 'publicKey' => $this->retired, 'validFrom' => '2026-01-05T08:00:00.000000Z', 'validUntil' => '2026-11-09T12:05:00.000000Z'],
        ], $keys->toArray());

        // Unconfigured: the signer's key alone, without a window, as before.
        $config = $this->process(['receipt_signer' => 'app.receipt_signer']);
        self::assertSame([null, []], [$config['receipt_keys']['valid_from'], $config['receipt_keys']['retired']]);
        self::assertFalse(TrustedKeys::forSigner($this->signer, null, [])->get('application-2')?->bounded());
        // An environment variable, read with the json: processor, gives the same list; empty means not recorded.
        $fromEnvironment = json_decode((string) json_encode([['key_id' => 'application-1', 'public_key' => $this->retired, 'valid_until' => '2026-11-09T12:05:00Z']]), true);
        self::assertNull(TrustedKeys::forSigner($this->signer, '', $fromEnvironment)->get('application-1')?->validFrom);
    }

    public function testTheConfigurationRefusesWhatCannotBeAListOfRetiredKeys(): void
    {
        foreach ([
            'an unknown field' => ['receipt_signer' => 'app.receipt_signer', 'receipt_keys' => ['retired' => [['key_id' => 'application-1', 'public_key' => $this->retired, 'valid_until' => '2026-11-09T12:00:00Z', 'secret_key' => 'never here']]]],
            'a map of keys' => ['receipt_signer' => 'app.receipt_signer', 'receipt_keys' => ['retired' => ['application-1' => ['public_key' => $this->retired]]]],
            'keys without a signer' => ['receipt_keys' => ['retired' => [['key_id' => 'application-1', 'public_key' => $this->retired, 'valid_until' => '2026-11-09T12:00:00Z']]]],
        ] as $case => $extra) {
            try {
                $this->process($extra);
                self::fail('The configuration accepted '.$case.'.');
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testARetiredKeyMustHaveClosedAndCannotReuseTheSignersId(): void
    {
        foreach ([
            'Retired key 1 needs valid_until.' => [['key_id' => 'application-1', 'public_key' => $this->retired]],
            'The key id "application-2" is listed more than once.' => [['key_id' => 'application-2', 'public_key' => $this->retired, 'valid_until' => '2026-11-09T12:00:00Z']],
            '"9 November" is not an RFC 3339 time with an offset, such as 2026-11-09T12:00:00Z.' => [['key_id' => 'application-1', 'public_key' => $this->retired, 'valid_until' => '9 November']],
            'The trusted key "application-1" needs a base64url Ed25519 public key.' => [['key_id' => 'application-1', 'public_key' => 'not-a-key', 'valid_until' => '2026-11-09T12:00:00Z']],
        ] as $message => $retired) {
            try {
                TrustedKeys::forSigner($this->signer, null, $retired);
                self::fail('The keys were built: '.$message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function process(array $extra): array
    {
        $configuration = (new VerifiedHumanApprovalBundle())->getContainerExtension()?->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$extra + [
            'public_origin' => 'https://approval.test', 'actor_provider' => 'app.actors', 'policy_provider' => 'app.policies',
            'authorizer' => 'app.authorizer', 'store' => 'app.store', 'verifier' => 'app.verifier',
        ]]);
    }
}
