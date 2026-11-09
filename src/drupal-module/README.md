# Verified Human Approval for Drupal

Publish an exact Drupal node revision after the requesting user reviews the stored proposal
and approves it with a passkey. The module checks current Drupal permissions,
creates a new published default revision, and records an application-signed
receipt in the same database transaction.

This is a development preview with a deliberately small publication action. It
uses `verified-human-approval/core` and `verified-human-approval/webauthn`
directly, with Drupal services, users, routes, forms, and database storage.
The Symfony bundle and demo application are not dependencies.

## Supported installation

| Requirement | Current support |
| --- | --- |
| Drupal | `^11.4`; the local development harness uses 11.4.12. |
| PHP | `^8.3`, with Drupal's required extensions, PDO SQLite, OpenSSL, and sodium. The local harness uses PHP 8.4.24. |
| Database | SQLite 3.45 or later, using one shared Drupal database connection. MySQL and PostgreSQL are not supported by this adapter. |
| Node storage | Drupal's default `Drupal\node\NodeStorage` class. Custom storage handlers are rejected. |
| Browser identity | An active Drupal account authenticated by a cookie session. |
| Approval | WebAuthn with user verification required. The device may use a PIN, fingerprint, face recognition, or another supported local method. |

The module and VHA library packages are not available from Packagist. The
module also requires the library contracts in
[upstream portability PR #3](https://github.com/scottfalconer/verified-human-approval/pull/3).
Use a checkout that contains both this module and those library changes. An
older upstream checkout does not provide the required audit and credential
repository interfaces.

## 1. Install from a local checkout

Start with an installed Drupal 11.4 site using SQLite. Keep the VHA checkout
outside the Drupal web root. In the **Drupal project's root `composer.json`**,
add these path repositories, replacing the example absolute path. Retain the
project's existing Drupal Composer repository and any other repositories it
needs.

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/absolute/path/to/verified-human-approval/src/core",
      "options": {
        "symlink": true,
        "versions": {"verified-human-approval/core": "dev-main"}
      }
    },
    {
      "type": "path",
      "url": "/absolute/path/to/verified-human-approval/src/webauthn",
      "options": {
        "symlink": true,
        "versions": {"verified-human-approval/webauthn": "dev-main"}
      }
    },
    {
      "type": "path",
      "url": "/absolute/path/to/verified-human-approval/src/drupal-module",
      "options": {
        "symlink": true,
        "versions": {"drupal/verified_human_approval": "dev-main"}
      }
    },
    {"type": "composer", "url": "https://packages.drupal.org/8"}
  ]
}
```

Request all three development packages explicitly so their `self.version`
dependencies resolve together:

```sh
composer require \
  'verified-human-approval/core:dev-main' \
  'verified-human-approval/webauthn:dev-main' \
  'drupal/verified_human_approval:dev-main'
composer check-platform-reqs
```

With the normal Drupal Composer installer paths, the module appears at
`web/modules/contrib/verified_human_approval`. This path is a symlink in the
example above, so the checkout must remain available. Install dependencies in
the Drupal project; the VHA monorepo's root Composer project installs its
Symfony demo and is not the Drupal consumer.

For a disposable consumer and the real Drupal kernel suite, follow
[Development and testing](docs/development.md).

## 2. Provision the application signing key

Run the module's key generator with an absolute private directory path:

```sh
php web/modules/contrib/verified_human_approval/bin/generate-keys.php \
  /srv/private/verified-human-approval
```

It creates `signing.key` and `public-key.json` and prints the settings to add.
The signing key is private Ed25519 key material. Keep it outside the web root,
source control, Drupal configuration exports, and the database. Give the PHP
process the file access it needs and keep the directory private. The generator
refuses to overwrite existing keys.

The public trust file contains only `keyId` and `publicKey`. It is safe to share
that public information through a trusted channel. The application signing key
is separate from each user's passkey; users' passkey private keys stay with
their authenticators.

Back up the key files separately from the database. Losing the private key
prevents new signatures, while an existing receipt can still be checked with
the retained public key. The server currently trusts one key and has no rotating
keyring or rotation/recovery workflow. Retain historical public keys offline
with their receipts. Changing the configured key can make existing server audit
verification fail; arbitrary key replacement is not a migration of signed records.

## 3. Configure the trusted site and content type

Create a simple content type, for example `approval_article`, with its normal
node base fields and, optionally, one body field. If present, the body must be a
single-value `text_with_summary` field and nonempty content must use the
`plain_text` format. Additional stored fields such as images, taxonomy terms,
links, paragraphs, or comments are not accepted by this action. Drupal's
standard Article content type commonly needs a simpler replacement for this
example.

Add the following to the site's `settings.php`, using the real hostname and
absolute key paths:

```php
$settings['verified_human_approval'] = [
  'rp_id' => 'cms.example.com',
  'origin' => 'https://cms.example.com',
  'protected_bundles' => ['approval_article'],
  'lifetime_seconds' => 600,
  'signing_key_file' => '/srv/private/verified-human-approval/signing.key',
  'public_key_file' => '/srv/private/verified-human-approval/public-key.json',
];
```

| Setting | Meaning |
| --- | --- |
| `rp_id` | The trusted WebAuthn relying party host, without a scheme or port. |
| `origin` | The exact browser origin, including scheme and any nondefault port. Use HTTPS for a deployed site. |
| `protected_bundles` | Content type machine names whose published saves require this approval flow. The default is an empty list. |
| `lifetime_seconds` | Proposal lifetime, from 30 to 3,600 seconds; default 600. |
| `signing_key_file` | Absolute readable path to the private signing key, outside the web root. |
| `public_key_file` | Absolute readable path to the matching public trust JSON file. |
| `site_id` | Optional stable tenant identifier, at most 128 bytes. When omitted, the module uses `system.site`'s UUID. |

Keep the site identifier stable after enrolling credentials. Configure Drupal's
normal trusted-host and proxy settings for the deployed origin. The module
does not derive its WebAuthn trust settings from request headers.

Enable the module and rebuild caches:

```sh
drush en verified_human_approval -y
drush cr
```

Drush is optional: enable the module through Drupal's Extend page if it is not
installed. The module installs its own approval, ceremony, credential, audit,
receipt, and enrollment-grant tables through Drupal's schema API.

Drupal's standard installation preflight checks the SQLite database, sodium,
and required library interfaces. A custom programmatic installer must run
Drupal's requirements preflight too; calling the low-level module installer
alone does not run these checks.

Visit `/admin/config/people/verified-human-approval` to inspect setup. That page
reports configuration presence; it does not certify a working passkey or signing
flow. Complete a test publication to exercise the configured system.

## 4. Assign permissions and enroll a passkey

Assign permissions through Drupal's normal role administration:

| Permission | Allows |
| --- | --- |
| `administer verified human approval` | Inspect setup and issue enrollment grants for named accounts. |
| `request verified human approval` | Request publication of a supported revision. Current node update and published-status field access are also required. |
| `use verified human approval` | Manage personal approval passkeys and review, approve, cancel, or download owned requests. |

A person who will request and approve publication needs both request and use
permissions, the relevant Drupal node editing access, and Drupal's native
`administer node published status` permission. Drupal's `administer nodes`
permission also grants status-field editing access. The module checks that
field access again before publication; its own permissions do not grant it.
Administrator permission does not turn off the approval requirement.

1. An authorized administrator opens
   `/admin/config/people/verified-human-approval/enrollment` and selects the
   active Drupal account.
2. The administrator issues a one-time enrollment link and shares it privately
   with that person after checking the recipient.
3. The person signs in to Drupal as that account, opens the link, names the
   passkey, and completes the browser's registration prompt.
4. The person can inspect or revoke their approval passkeys at
   `/verified-human-approval/passkeys`.

An enrollment link expires after ten minutes, belongs to one account and site,
and registers one passkey. It does not log the person in. Each additional
passkey needs another administrator-issued grant. The enrollment ceremony has
a separate two-minute expiry. Registration, its signed enrollment anchor, and
grant consumption commit together.

The default identity integration requires a Drupal cookie session and rejects
bearer authorization for enrollment and approval. A passkey proves use of the
enrolled credential with user verification; the server does not receive
biometric data or establish a person's civil identity.

## 5. Request and approve publication

1. Save a supported node as **unpublished**. Its requested revision must be both
   the latest revision and the default revision, with exactly one language.
2. Open the node's **Request verified publication** tab, or
   `/node/NODE_ID/request-verified-publication`.
3. Choose **Review this revision**. This stores a proposal; it does not publish
   the node.
4. Review the stored title, body, summary, source revision, language, and
   publication details. Approve with the enrolled passkey, or cancel.
5. On success, Drupal creates a new published default revision and the review
   page offers the original receipt for download.

The requesting account is also the approving account in this initial browser
flow. This is not a separate reviewer assignment or multi-approver workflow.
An edit to the source draft, lost authority, an expired request, an unsupported
field, or a mismatching passkey prevents completion. Start a new request when
the source draft changes.

**Editing already published protected content:** an ordinary save that leaves
the node published is blocked. First save an unpublished default draft, then
request publication again. The default node is therefore taken offline until
approval. This release does not keep an old published version online while a
new draft goes through moderation. Content Moderation and Workspaces are not
supported publication workflows here.

## Keep and verify the original receipt

The completed review page downloads an evidence bundle from
`/verified-human-approval/request/REQUEST_ID/receipt`. The bundle contains the
canonical proposal, WebAuthn proof, execution report, and the original receipt
signed during completion. Export verifies the stored receipt before returning
it. Export does not sign a replacement or execute the action again; a missing
original receipt is an error.

The public endpoint `/verified-human-approval/receipt-key.json` supplies the
application's `keyId`, `publicKey`, `rpId`, and `origin`. Obtain this information
through a channel you trust and retain it independently of the receipt and
database. Do not accept a verification key merely because the same untrusted
evidence file supplied it.

After saving the owner's download as `receipt.json` and the trusted application
information as `site-key.json`, run the installed verifier from the Drupal
project root:

```sh
php vendor/bin/verify-evidence.php receipt.json site-key.json
```

A successful application receipt check reports
`applicationSignedExecutionReport: true` and
`applicationReportSignedAt: "commit"`. Without a separately trusted credential
record, `credentialApprovedExactProposal` is `null`: that invocation has not
independently verified the passkey signature.

To verify the passkey signature too, provide the credential's original public
registration record, obtained through a trusted enrollment/audit source:

```sh
php vendor/bin/verify-evidence.php receipt.json site-key.json trusted-credential.json
```

The credential file needs `id`, `userId`, `tenantId`, and the original
`sourceJson` string. An unchecked credential row from a database suspected of
tampering is not an independent trust source. This preview does not provide a
credential-trust export UI. See the library's
[evidence guide](../../docs/evidence.md) for the trust model and verifier
format; its demo routes and demo credential-export script are not Drupal
installation instructions.

An independently retained receipt and public key can reveal changes to covered
fields in the historical record. They do not prove that no records were
deleted, that the whole database was not restored to an earlier valid snapshot,
or that the live node has never changed since. A valid signature does not
independently witness database execution, what was displayed, or the person's
understanding. Application or private-key compromise is outside this
protection.

## Transaction and publication boundaries

The SQLite adapter acquires the database writer reservation before reading
authoritative state. It rejects an unrelated ambient transaction. Current
accounts, permissions, the source revision, and the resulting stored effect
are checked inside the transaction; authority is checked again after the node
save hooks. The node change, credential counter update, completion record,
ceremony retirement, and receipt share that transaction. A failure before
commit rolls them back. An ambiguous commit failure is checked against stored
state before a result or retry decision is made.

For configured content types, the module checks ordinary node saves before
persistence and checks the stored publication status during insert/update
hooks. The supported action also reloads and verifies the saved content and
revision before completing. These checks make the normal Drupal entity save
path enforce the approval rule, including a later presave hook that tries to
publish a draft.

This guarantee covers supported database changes on the shared connection. It
does not roll back email, remote HTTP calls, external caches or queues, or other effects
that a site's hooks may perform. Direct SQL, custom storage, trusted code that
disables the gate, and changes to the trusted settings are outside the guarded
publication path. Storage restore operations that skip save hooks are also
outside this path. Unpublishing and deletion remain governed by Drupal's normal
permissions. Preserve the database, retained receipts, and trusted public key
when planning uninstall or retention policies.

## Developer API and MCP integration

Inject `verified_human_approval.approval_manager` into a Drupal service. Its
`requestPublish($nodeId, $revisionId, $langcode, $requestKey)` method returns an
`ApprovalState`; `get($requestId)` reads an owned request, and `receipt($requestId)`
exports completed evidence. Identity comes from the configured actor provider,
not caller-supplied user or tenant identifiers.

The module does not install an MCP endpoint or OAuth issuer. The provided
identity path is the Drupal cookie session. There is no tested bearer/MCP
adapter in this preview. The
[MCP integration notes](docs/mcp-integration.md) show how a host can wrap this
service through existing Tool API and MCP Server modules, including the
required delegation, scope, expiry, and revocation checks. That sample is not
an enabled feature of this module.

- [Development, independent installation, and kernel tests](docs/development.md)
- [Existing MCP endpoint integration and its current limits](docs/mcp-integration.md)
- [Library security model](../../docs/security-model.md)

The Drupal module is licensed under GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).
The reusable VHA library packages retain their MIT license.
