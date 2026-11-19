<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class Ceremony
{
    /** @param array<string, mixed> $options the browser's publicKey options */
    public function __construct(
        public string $id,
        public string $requestId,
        public string $nonce,
        public string $challenge,
        public string $expiresAt,
        public array $options,
        public bool $consumed = false,
    ) {
    }

    /**
     * Reads a ceremony back from the parts a store keeps, the options as a JSON object. A part that does not read back
     * as it was written (options that are not a JSON object, a nonce that is not 32 bytes of base64url, an expiry
     * that is not a UTC time as the approval service writes one) leaves a ceremony nobody can use, refused before
     * anything relies on it.
     *
     * @throws ApprovalException with the reason corrupt_ceremony, and what failed as its previous exception
     */
    public static function fromStorage(string $id, string $requestId, string $nonce, string $challenge, string $expiresAt, string $options, bool $consumed): self
    {
        try {
            // Each part is read here as the approval service uses it, so a ceremony that loads can always be used.
            if (32 !== \strlen(ChallengeBinding::decode($nonce))) {
                throw new \UnexpectedValueException('The nonce is not 32 bytes.');
            }
            // An exact time, never one PHP would read relative to now, such as "+1 day", which would never come.
            $expiry = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $expiresAt, new \DateTimeZone('UTC'));
            if (false === $expiry || ApprovalRequest::timestamp($expiry) !== $expiresAt) {
                throw new \UnexpectedValueException('The expiry is not a UTC time as the approval service writes one.');
            }

            return new self($id, $requestId, $nonce, $challenge, $expiresAt, CanonicalJson::map(CanonicalJson::decode($options)), $consumed);
        } catch (\Exception $error) {
            throw new ApprovalException('corrupt_ceremony', 'The stored passkey ceremony cannot be read back. Start a fresh review.', $error);
        }
    }

    /** @return array{ceremonyId: string, options: array<string, mixed>, expiresAt: string} */
    public function toArray(): array
    {
        return ['ceremonyId' => $this->id, 'options' => $this->options, 'expiresAt' => $this->expiresAt];
    }
}
