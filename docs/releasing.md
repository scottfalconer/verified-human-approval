# Versions and releases

The packages are developed together in this repository and released together, under one version number. Each depends on its siblings at `self.version`, so `verified-human-approval/symfony-bundle` 0.1.0 installs `core`, `webauthn` and `mcp` 0.1.0.

## What a version promises

**Before 1.0**, a minor version (0.1 to 0.2) may change PHP interfaces, bundle configuration, routes and templates; [the changelog](../CHANGELOG.md) says what changed and how to move. A patch version (0.1.0 to 0.1.1) only fixes. Pin a minor version: `^0.1` allows 0.1.x and nothing later.

**Records outlive versions.** Every signed or stored format names itself, as `verified-human-approval/<name>/v<n>`: `proposal/v1`, `proof/v1`, `execution/v1`, `receipt/v2`, `evidence/v2`, `audit/v1`, `continuation/v1` and the key files `receipt-key/v1` and `receipt-keys/v2`. From the first release on, a change to one gets a new name, and later versions keep reading what earlier releases wrote: a receipt someone kept still checks after an upgrade, and so do the approval and audit records in your database.

**From 1.0**, versions follow semantic versioning for everything the documentation describes: the interfaces in [the integration contracts](integration-contract.md), the bundle's configuration keys, routes, Twig functions and template blocks, console commands, events and the formats above. Classes and methods marked `@internal`, or not documented, can change in any version.

## Making a release

Some of these steps are the maintainer's, because they publish: making the repository public, creating repositories, and claiming the package names.

1. **Once, before the first release:**
   - Make this repository public, and enable private vulnerability reporting (Settings → Code security), which [SECURITY.md](../SECURITY.md) points to.
   - Create one empty repository per package under one account or organization. [The split workflow](../.github/workflows/split.yml) names them `verified-human-approval-core`, `-webauthn`, `-mcp`, `-symfony-bundle`, `-doctrine` and `-store-contracts`; change the names there if you choose others.
   - Create a token that can push to those repositories only (a fine-grained token with Contents: read and write). Save it as the secret `SPLIT_TOKEN` of this repository, and set the repository variable `SPLIT_OWNER` to that account or organization. From then on, every push to `main` updates each package repository.
   - Submit each package repository to Packagist under the `verified-human-approval` vendor name, and turn on its update hook.
2. **Each release:**
   - Check that `main` passes CI, and move the changelog's "Unreleased" section under the new version and date.
   - Tag `main` as `vX.Y.Z` and push the tag. The split workflow pushes the same tag to every package repository, and Packagist picks it up.
   - The first time, replace the path-repository instructions in [the integration guide](../src/symfony-bundle/docs/index.md) with `composer require verified-human-approval/symfony-bundle:^0.1`.
