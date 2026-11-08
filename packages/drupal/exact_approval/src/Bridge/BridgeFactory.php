<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Bridge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use ExactApproval\Core\Evidence\SodiumEvidenceSigner;
use ExactApproval\WebAuthn\Configuration;

final class BridgeFactory
{
    public static function webAuthnConfiguration(ConfigFactoryInterface $config): Configuration
    {
        $settings = $config->get('exact_approval.settings');
        return new Configuration((string) $settings->get('rp_id'), (string) $settings->get('origin'));
    }

    public static function signer(): SodiumEvidenceSigner
    {
        return SodiumEvidenceSigner::fromEncoded(
            (string) Settings::get('exact_approval_signing_key_id', ''),
            (string) Settings::get('exact_approval_signing_secret', ''),
        );
    }
}

