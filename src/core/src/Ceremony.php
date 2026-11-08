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

    /** @return array{ceremonyId: string, options: array<string, mixed>, expiresAt: string} */
    public function toArray(): array
    {
        return ['ceremonyId' => $this->id, 'options' => $this->options, 'expiresAt' => $this->expiresAt];
    }
}
