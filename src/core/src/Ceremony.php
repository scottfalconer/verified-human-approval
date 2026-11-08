<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class Ceremony
{
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

    public function toArray(): array
    {
        return ['ceremonyId' => $this->id, 'options' => $this->options, 'expiresAt' => $this->expiresAt];
    }
}
