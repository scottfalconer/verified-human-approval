<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * TEST ONLY: synthetic ES256 authenticator. Real signatures exercise the production
 * WebAuthn parser and validator; these tests do not prove physical biometric interaction.
 */
final class VirtualAuthenticator
{
    private \OpenSSLAsymmetricKey $key;
    private string $rawId;
    private string $userHandle = '';
    private string $rpId = '';
    private int $counter = 0;

    public function __construct(private readonly string $origin = 'https://approval.test')
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('OpenSSL did not create the virtual authenticator key.');
        }
        $this->key = $key;
        $this->rawId = random_bytes(32);
    }

    public function id(): string
    {
        return Base64UrlSafe::encodeUnpadded($this->rawId);
    }

    /** @param array<string, mixed> $changes Signed test variations, never application overrides. */
    public function registration(array $options, array $changes = []): array
    {
        $this->rpId = $options['rp']['id'];
        $this->userHandle = Base64UrlSafe::decodeNoPadding($options['user']['id']);
        $flags = $changes['flags'] ?? 0x45; // User present + verified + attested credential data.
        $authData = hash('sha256', $changes['rpId'] ?? $this->rpId, true)
            .chr($flags).pack('N', $changes['counter'] ?? 0)
            .str_repeat("\0", 16).pack('n', strlen($this->rawId)).$this->rawId.$this->cosePublicKey();
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => $this->id(),
            'rawId' => $this->id(),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($this->clientData('webauthn.create', $options, $changes)),
                'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => (object) [],
        ];
    }

    /** @param array<string, mixed> $changes Signed test variations, never application overrides. */
    public function assertion(array $options, array $changes = []): array
    {
        $clientData = $this->clientData('webauthn.get', $options, $changes);
        $counter = $changes['counter'] ?? ++$this->counter;
        $authData = hash('sha256', $changes['rpId'] ?? $options['rpId'], true)
            .chr($changes['flags'] ?? 0x05).pack('N', $counter);
        if (!openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('OpenSSL did not sign the test assertion.');
        }

        return [
            'id' => $this->id(),
            'rawId' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => array_key_exists('userHandle', $changes)
                    ? $changes['userHandle']
                    : Base64UrlSafe::encodeUnpadded($this->userHandle),
            ],
            'clientExtensionResults' => (object) [],
        ];
    }

    private function clientData(string $type, array $options, array $changes): string
    {
        $data = [
            'type' => $changes['type'] ?? $type,
            'challenge' => $changes['challenge'] ?? $options['challenge'],
            'origin' => $changes['origin'] ?? $this->origin,
            'crossOrigin' => $changes['crossOrigin'] ?? false,
        ];
        if (isset($changes['topOrigin'])) {
            $data['topOrigin'] = $changes['topOrigin'];
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function cosePublicKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('OpenSSL did not expose the test public key.');
        }
        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)) // kty EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)) // ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)) // P-256
            // OpenSSL can omit leading zero octets; COSE ES256 coordinates are exactly 32 octets.
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)));
    }
}
