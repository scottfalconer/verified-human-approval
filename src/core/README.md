# Verified Human Approval: core (`verified-human-approval/core`)

The framework-neutral approval engine: frozen proposals with canonical JSON digests, policies, the current-authority check, guarded single execution inside one database transaction, receipts signed at commit, the signed audit log and evidence verification. It depends only on `psr/clock` and a JSON canonicalization library.

A host supplies an `ActionAdapter` (what the exact effect is, its version, preconditions, execution and postconditions), an `Authorizer`, a `PolicyProvider`, an `ApprovalStore` and an `ApprovalVerifier`. The Symfony bundle (`verified-human-approval/symfony-bundle`) wires all of this for a Symfony application; the WebAuthn package supplies the passkey verifier.

`bin/verify-evidence.php` checks an exported evidence bundle against separately trusted application and credential keys. See the repository's `docs/` for the security model and the evidence format.
