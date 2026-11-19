# Approved publishing on Doctrine

A small newsroom built on Symfony and Doctrine ORM, to copy from. Editors write drafts freely. Every change to what visitors see waits for the editor's passkey: **publishing** a draft, **editing a live article**, **archiving** (one article or a batch, under one approval) and **deleting**. Each change is reviewed as visitors will see it, approved once, recorded with a receipt the site signs as it commits, and requested the same way from the editor's browser and from their agent over MCP. The public page marks each article whose live text is exactly what a person approved.

It uses the [Doctrine storage](../../src/doctrine/README.md) through the bundle's `persistence: { driver: doctrine }`: the article, the approval, the passkey's counter and the receipt commit in one transaction on the application's own connection. It runs on SQLite by default, and on PostgreSQL or MySQL by `DATABASE_URL`.

## Run it

You need PHP 8.3 or later with the extensions the repository's `composer.json` lists (among them `pdo_sqlite`, `sodium` and `openssl`), Composer, and a browser with passkey support. From the repository root:

```bash
composer install
php examples/doctrine-publishing/bin/setup
```

Setup picks a random port on `localhost` (never the demo's), creates the schema, an **editor** and a **reader**, and three drafts, and prints, once, each account's password, agent token and one-time passkey link. Keep that output private. It writes the settings (origin, database URL and keys) to `examples/doctrine-publishing/var/settings.php`, which is ignored by Git. To choose the port, set `APP_ORIGIN=http://localhost:28417` before running setup.

Start the site with the command setup printed:

```bash
php -S localhost:PORT -t examples/doctrine-publishing/public examples/doctrine-publishing/public/index.php
```

Open `http://localhost:PORT/login` in that browser, **at `localhost`, not `127.0.0.1`**: a passkey belongs to its exact origin, port included. Any server works that serves `public/` and sends every other request to `public/index.php` on that origin, such as the Symfony CLI's; this repository's checks run `php -S`.

1. **Sign in** as `editor` with its password.
2. **Add your passkey**: open the editor's passkey link, while signed in, then press *Register a passkey*. The link works once, for seven days; a password alone never adds a passkey.
3. **Publish**: open a draft under *Editing* and press *Ask to publish the saved draft*. The review page shows the article exactly as visitors will see it. Approve with your passkey, and it is live on the public page with *Approved with a passkey*.
4. **Edit live content**: on the live article, change the text and press *Ask to change the live article*. The review shows the live text and yours side by side; the public page keeps the live text until you approve.
5. **Archive**: on *Editing*, tick several live articles and press *Ask to archive the selected articles*: one review lists each with its live revision, and one approval archives all of them.
6. **Delete**: on any article, *Ask to delete for good*. The review shows everything that disappears.

To start again, stop the server and delete `examples/doctrine-publishing/var/`.

### Connect an agent

The MCP endpoint is `http://localhost:PORT/mcp`. Each account's agent token is a bearer token for it, for example with Claude Code:

```bash
claude mcp add --transport http newsroom http://localhost:PORT/mcp --header "Authorization: Bearer EDITOR_AGENT_TOKEN"
```

Then ask the agent to list the articles and publish one. Its call returns `pending` with a review link: open it in your signed-in browser and approve there. The agent can never approve, and nothing changes until you do; it reads the outcome with `approval_status`. The reader's token can list articles and ask for nothing.

### On PostgreSQL or MySQL

Give setup an empty database with its charset in the URL, as Symfony's recipe writes it:

```bash
DATABASE_URL='postgresql://app:secret@127.0.0.1:5432/newsroom?charset=utf8' php examples/doctrine-publishing/bin/setup
DATABASE_URL='mysql://app:secret@127.0.0.1:3306/newsroom?charset=utf8mb4' php examples/doctrine-publishing/bin/setup
```

Setup saves the URL with the other settings. Use MySQL 8.0.17 or later, or MariaDB, with strict SQL mode.

## The four changes

| Change | In the browser | Agent tool | The review shows | It stops, and nothing changes, when |
| --- | --- | --- | --- | --- |
| Publish a draft (`article.publish`) | *Ask to publish the saved draft* | `publish_article` | The article as visitors will see it, from the frozen draft | The draft, or what visitors see, changed after the request |
| Edit live content (`article.edit_live`) | *Ask to change the live article* | `edit_live_article` | The live text and the proposed text side by side; the live text stays until approval | What visitors see changed after the request: it never overwrites newer text |
| Archive, one or a batch (`article.archive`) | *Ask to archive* (tick several on *Editing*) | `archive_articles` | Every article and its live revision | Any one of them changed: none is archived |
| Delete (`article.delete`) | *Ask to delete for good* | `delete_article` | Everything that disappears: draft, live or archived text | The article changed after the request |

Each one also stops when the person is no longer an editor, or their agent's connection was revoked, by the time they approve, and when the request expires (ten minutes). Reading (`list_articles`) and drafts (`save_draft`, *Save draft*) need no approval: visitors never see a draft.

## How it is built

Read it in this order. Everything is in `src/`, `config/` and `templates/`.

- **[`Entity/Article.php`](src/Entity/Article.php)**: a draft (`draftTitle`, `draftBody`), free to edit, beside what visitors see: `status`, `liveTitle`, `liveBody`, `revision` and `liveRequestId`, the approval that last changed it. Those five are `#[RequiresApproval]`, so the bundle's `ProtectedEntityListener` refuses any flush that changes them, or inserts or deletes an article holding them, unless an approved action is executing for that article: a form, a console command or a script is stopped with `approval_required`. They stay null until something is approved, which is why a draft, never published, has a null `status`.
- **[`Action/`](src/Action/)**: the four approved changes, each an [`EntityAction`](../../src/doctrine/src/Orm/EntityAction.php). `prepare()` freezes the exact change and what it depends on; `assertPreconditions()` compares the article as stored now with what was frozen (`stale_resource` otherwise); `apply()` changes the locked entities and writes the request id (`EntityAction::currentApproval()`) on them; `assertPostconditions()` reads the flushed rows back. They read the articles with `current()`, which locks them `FOR UPDATE` on PostgreSQL and MySQL, in one order, so nothing changes them between the checks and the commit. Publishing and live edits include the digest of the article template in their version, so a deploy that changes how an article looks stops requests reviewed before it.
- **[`Approval/EditorialPermissions.php`](src/Approval/EditorialPermissions.php)**: the authorizer. It re-reads the account's role and the agent's connection `FOR SHARE` (`DbalTransaction::forShare()`) each time: at the request, when the person begins approving, and inside the transaction that executes the change. A passkey adds no permission.
- **[`Approval/EditorialPolicy.php`](src/Approval/EditorialPolicy.php)**: every change needs a passkey; the statements the person approves.
- **[`Security/`](src/Security/)**: who is asking, from the signed-in account or the agent's token, never from a form field or a tool argument. The agent token is **example-grade**: one static bearer token per person, stored as a hash, checked against its stored connection on every request, with no expiry or OAuth. Replace it with your own token service.
- **[`Controller/EditorController.php`](src/Controller/EditorController.php)** and **[`Mcp/ArticleTools.php`](src/Mcp/ArticleTools.php)**: the browser and the agent. Both ask with the same `ApprovalService::request()` call, the same action ids and the same input, so neither can do what the other cannot; drafts go through the same `Editing/Drafts.php`. The browser then opens the bundle's review page; the agent gets its link.
- **[`templates/bundles/VerifiedHumanApprovalBundle/review.html.twig`](templates/bundles/VerifiedHumanApprovalBundle/review.html.twig)**: the review page's preview for each change, drawn from the frozen proposal through the same [`_article.html.twig`](templates/public/_article.html.twig) the public page uses, never from today's draft or live text.
- **[`templates/public/index.html.twig`](templates/public/index.html.twig)**: the public page. `verified_human_approval_badge(article.liveRequestId, article.id, {title: article.liveTitle, body: article.liveBody})` shows *Approved with a passkey* only while the live text is exactly the approved title and body, the request completed with a passkey and its signed receipt still verifies. It links to the public verification page.
- **[`Account/PasskeyEnrollment.php`](src/Account/PasskeyEnrollment.php)**: the passkey, the one-time link it uses up and its signed enrollment entry in the audit log, in one transaction; only a passkey with that entry approves.
- **[`config/packages/verified_human_approval.php`](config/packages/verified_human_approval.php)**: the bundle on Doctrine, with a receipt signer and the public verification page. The configuration is PHP, so the example runs without `symfony/yaml`; the [Symfony guide](../../src/symfony-bundle/docs/index.md#storage-on-doctrine) shows the bundle's own settings as YAML.
- **[`Setup/`](src/Setup/)**: the schema (the entities' tables, and the approval tables the bundle's schema listener adds, as `doctrine:migrations:diff` would), the accounts, their tokens and links, and three drafts. Nothing starts live: setup cannot put an article live either.

## What the tests prove

[`tests/Example/PublishingTestCase.php`](../../tests/Example/PublishingTestCase.php) runs this application's own kernel, firewalls, forms and MCP tools, and the real WebAuthn verification with the repository's synthetic `VirtualAuthenticator`. Wherever something must change, or must not, it compares the whole articles table, as another connection reads it, before and after. It runs as `SqlitePublishingTest`, `PostgresPublishingTest` and `MysqlPublishingTest`:

| What | How |
| --- | --- |
| The approved change is exactly what was reviewed, and nothing changes before approval | Each of the four, from the browser and from the agent: the table is unchanged after the request, and afterwards differs by exactly the frozen change |
| A change after the request stops it | Each of the four, from both: a draft edit, or a newer live edit approved first, between the person beginning to approve and completing: `invalidated`, `stale_resource`, the newer text kept |
| Revoking permission between request and approval stops it | Each of the four: the editor made a reader (browser), or the agent's connection revoked after the person began (agent): `denied`, nothing changed |
| A batch is all or nothing | One article of a batch changes after the request: none is archived. Approved, all of them are, under one request id |
| Delete removes only what was frozen | Only the named article disappears; a changed draft is not deleted; its approvals stay on record |
| The tripwire | Changing live text, archiving, deleting or inserting a live article through the ORM, outside an approval: `approval_required`, nothing written; a draft still saves |
| The badge | Shown for approved live text; gone after a plain SQL edit of the live text, after archiving, and for an article put back live by SQL |
| The locks | While an approval's checks are open, another connection cannot change the role, the connection or the article it read |
| One path | The browser and the agent make the identical proposal; a retry with the same key returns the same request and publishes once; the agent reads the signed receipt; a reader's token, and a reader with a write scope by mistake, ask for nothing |

```bash
vendor/bin/phpunit --testsuite example                                    # SQLite; the others skip
VERIFIED_HUMAN_APPROVAL_POSTGRES_URL='pdo-pgsql://postgres:postgres@127.0.0.1:5432/postgres' vendor/bin/phpunit --testsuite example --filter Postgres --fail-on-skipped
VERIFIED_HUMAN_APPROVAL_MYSQL_URL='pdo-mysql://root:root@127.0.0.1:3306/mysql?charset=utf8mb4' vendor/bin/phpunit --testsuite example --filter Mysql --fail-on-skipped
```

Each server run creates and drops its own throwaway database. The repository's CI workflow runs the SQLite class with the rest of the suite, and the server classes on PostgreSQL 14 and 17, MySQL 8.0 and 8.4, and MariaDB 11.4.

## What it simplifies

This is an example to learn from and copy, not a finished newsroom:

- **Accounts.** Two accounts with passwords, from setup. There is no account management, password reset or recovery; a person's first passkey comes from a link setup prints, and adding another, or revoking one, is left to your account process (the [demo](../../demo/README.md) shows both). Registration has no rate limit of its own: the one-time link, the signed-in session and CSRF gate it. Approvals do have one, the bundle's.
- **Agent tokens.** Static bearer tokens, one per account, with no expiry, rotation, OAuth or page to revoke them; revoking means setting `revoked_at` on the connection. They are labeled example-grade where they are defined.
- **Permissions.** One role per account, editor or reader, and one tenant. A multi-tenant application keeps the tenant on the article and checks it.
- **Drafts.** Not versioned. A live article's text changes only through an approved live edit, which also updates its draft; there is no forward draft of a live article.
- **Deleting a draft that was never published.** It still asks for approval here. The tripwire guards what holds approved state, so plain ORM code could delete such a draft, as it could edit it: it holds nothing a visitor saw or a person approved.
- **What the tripwire sees.** It checks ORM flushes. Plain SQL and DQL `UPDATE` or `DELETE` go around it, as the tests show with the badge; keep those away from the protected columns. The badge still tells visitors when live text no longer matches its approval.
- **Deployment.** Local HTTP on `localhost` only. Before you deploy: an HTTPS origin chosen before anyone enrolls a passkey, `framework.trusted_hosts`, a shared cache and lock store if several servers run it, the receipt key in your secret store with its public key kept separately, migrations instead of setup's schema creation, and checkpoints of the audit log's head (`verified-human-approval:audit-head`). See [Before deployment](../../src/symfony-bundle/docs/index.md#before-deployment).
- **What a passkey proves.** That the enrolled passkey approved this exact proposal, with user verification. Not who wrote the text, nor that the person read it; see the [security model](../../docs/security-model.md).

The reference uses local file locks (`framework.lock: flock`) for its single-host setup, including sandboxed tests. A deployment with multiple web hosts must configure a shared lock store and shared session/cache state before enabling concurrent MCP sessions or phone handoff.
