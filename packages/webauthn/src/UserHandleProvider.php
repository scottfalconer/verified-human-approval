<?php

declare(strict_types=1);

namespace ExactApproval\WebAuthn;

use ExactApproval\Core\Actor;

interface UserHandleProvider
{
    /** Return the existing application's stable opaque WebAuthn user handle (1–64 bytes). */
    public function forActor(Actor $actor): string;
}
