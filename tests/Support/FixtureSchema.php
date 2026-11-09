<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

/**
 * The fixture application's tables and seed rows (articles, users, delegations, policies and test credentials), in
 * SQL that SQLite, PostgreSQL, MySQL and MariaDB all accept. Only the large text type and MySQL's table options
 * differ: an article body holds a proposal of up to 256 KiB, which a MySQL TEXT column cannot.
 */
final class FixtureSchema
{
    /** @return list<string> statements that create the tables and their rows in an empty database */
    public static function statements(string $platform): array
    {
        [$text, $options] = match ($platform) {
            'sqlite', 'postgresql' => ['TEXT', ''],
            'mysql' => ['LONGTEXT', ' ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'],
            default => throw new \InvalidArgumentException('Unknown platform: '.$platform),
        };

        return [
            "CREATE TABLE test_articles (
                id INTEGER NOT NULL PRIMARY KEY,
                tenant VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body {$text} NOT NULL,
                revision INTEGER NOT NULL,
                asset_version VARCHAR(64) NOT NULL,
                published INTEGER NOT NULL DEFAULT 0,
                publication_count INTEGER NOT NULL DEFAULT 0
            ){$options}",
            "CREATE TABLE test_users (
                user_id VARCHAR(64) NOT NULL,
                tenant VARCHAR(64) NOT NULL,
                enabled INTEGER NOT NULL,
                can_publish INTEGER NOT NULL,
                PRIMARY KEY (user_id, tenant)
            ){$options}",
            "CREATE TABLE test_delegations (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                tenant VARCHAR(64) NOT NULL,
                active INTEGER NOT NULL,
                can_publish INTEGER NOT NULL
            ){$options}",
            "CREATE TABLE test_policies (
                action_id VARCHAR(64) NOT NULL PRIMARY KEY,
                version INTEGER NOT NULL,
                statement VARCHAR(255) NOT NULL,
                required INTEGER NOT NULL
            ){$options}",
            "CREATE TABLE test_credentials (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                tenant VARCHAR(64) NOT NULL,
                active INTEGER NOT NULL,
                verification_count INTEGER NOT NULL DEFAULT 0
            ){$options}",
            "INSERT INTO test_articles (id, tenant, title, body, revision, asset_version) VALUES (1, 'tenant-a', 'Summer Campaign', 'Closing date: June 20.', 17, 'sha256:asset-v1')",
            "INSERT INTO test_articles (id, tenant, title, body, revision, asset_version) VALUES (2, 'tenant-a', 'Another Campaign', 'Closing date: July 20.', 4, 'sha256:asset-v2')",
            "INSERT INTO test_articles (id, tenant, title, body, revision, asset_version) VALUES (3, 'tenant-b', 'Other Organization', 'Private tenant B content.', 3, 'sha256:asset-v3')",
            "INSERT INTO test_users (user_id, tenant, enabled, can_publish) VALUES ('alice', 'tenant-a', 1, 1)",
            "INSERT INTO test_users (user_id, tenant, enabled, can_publish) VALUES ('bob', 'tenant-a', 1, 1)",
            "INSERT INTO test_users (user_id, tenant, enabled, can_publish) VALUES ('alice', 'tenant-b', 1, 1)",
            "INSERT INTO test_delegations (id, user_id, tenant, active, can_publish) VALUES ('agent-1', 'alice', 'tenant-a', 1, 1)",
            "INSERT INTO test_delegations (id, user_id, tenant, active, can_publish) VALUES ('agent-2', 'alice', 'tenant-a', 1, 1)",
            "INSERT INTO test_credentials (id, user_id, tenant, active) VALUES ('alice-key', 'alice', 'tenant-a', 1)",
            "INSERT INTO test_credentials (id, user_id, tenant, active) VALUES ('bob-key', 'bob', 'tenant-a', 1)",
            "INSERT INTO test_policies (action_id, version, statement, required) VALUES ('article.publish', 1, 'I approve publishing {{ title }}, revision {{ revision }}.', 1)",
        ];
    }
}
