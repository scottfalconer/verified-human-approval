# Verified Human Approval: core (`verified-human-approval/core`)

The framework-neutral approval engine: frozen proposals with canonical JSON digests, policies, the current-authority check, guarded single execution inside one database transaction, receipts signed at commit, the signed audit log and evidence verification. It depends only on `psr/clock` and a JSON canonicalization library.

A host supplies an `ActionAdapter` (what the exact effect is, its version, preconditions, execution and postconditions), an `Authorizer`, a `PolicyProvider`, an `ApprovalStore` and an `ApprovalVerifier`. The Symfony bundle (`verified-human-approval/symfony-bundle`) wires all of this for a Symfony application; the WebAuthn package supplies the passkey verifier.

To check an exported evidence bundle offline, against separately trusted application and credential keys, use `vendor/bin/verify-evidence.php` from `verified-human-approval/webauthn`, which verifies the person's passkey signature too.
