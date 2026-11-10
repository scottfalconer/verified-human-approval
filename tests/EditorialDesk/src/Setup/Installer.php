<?php

declare(strict_types=1);

namespace App\Setup;

use App\Account\PasskeyEnrollment;
use App\Entity\Page;
use App\Security\Account;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;

/** Creates a fresh, disposable integration instance. Never resets an existing database. */
final readonly class Installer
{
    public function __construct(private EntityManagerInterface $entities, private UserPasswordHasherInterface $passwords,
        private PasskeyEnrollment $enrollment, private string $origin)
    {
    }

    /** @return array<string, array{password:string,token:string,passkey_link:string}> */
    public function install(): array
    {
        $db = $this->entities->getConnection();
        if ($db->createSchemaManager()->tablesExist(['app_accounts'])) {
            throw new \RuntimeException('This database is already initialized. Use a new empty database.');
        }
        (new SchemaTool($this->entities))->createSchema($this->entities->getMetadataFactory()->getAllMetadata());
        ApprovalSchema::install($db);
        $schema = new Schema();
        $accounts = $schema->createTable('app_accounts');
        foreach (['id' => 64, 'username' => 64, 'password_hash' => 255, 'display_name' => 100, 'role' => 32] as $name => $length) {
            $accounts->addColumn($name, 'string', ['length' => $length]);
        }
        $accounts->addColumn('enabled', 'boolean', ['default' => true]);
        $accounts->setPrimaryKey(['id']);
        $accounts->addUniqueIndex(['username']);
        $grants = $schema->createTable('app_connections');
        foreach (['id' => 64, 'user_id' => 64, 'token_hash' => 64, 'scopes' => 255] as $name => $length) {
            $grants->addColumn($name, 'string', ['length' => $length]);
        }
        $grants->addColumn('expires_at', 'integer');
        $grants->addColumn('revoked_at', 'integer', ['notnull' => false]);
        $grants->setPrimaryKey(['id']);
        $grants->addUniqueIndex(['token_hash']);
        $links = $schema->createTable('app_enrollment_links');
        $links->addColumn('token_hash', 'string', ['length' => 64]);
        $links->addColumn('user_id', 'string', ['length' => 64]);
        $links->addColumn('expires_at', 'integer');
        $links->addColumn('used_at', 'integer', ['notnull' => false]);
        $links->setPrimaryKey(['token_hash']);
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
        $secrets = [];
        foreach (['editor' => 'ROLE_EDITOR', 'publisher' => 'ROLE_PUBLISHER', 'reader' => 'ROLE_READER'] as $id => $role) {
            $password = ChallengeBinding::encode(random_bytes(18));
            $token = ChallengeBinding::encode(random_bytes(32));
            $account = new Account($id, $id, ucfirst($id), '', $role);
            $db->insert('app_accounts', ['id' => $id, 'username' => $id, 'display_name' => ucfirst($id),
                'password_hash' => $this->passwords->hashPassword($account, $password), 'role' => $role, 'enabled' => 1]);
            $db->insert('app_connections', ['id' => 'grant-'.$id, 'user_id' => $id, 'token_hash' => hash('sha256', $token),
                'scopes' => 'ROLE_READER' === $role ? 'pages.read approval.read' : 'pages.read pages.write approval.read',
                'expires_at' => time() + 86400, 'revoked_at' => null]);
            $secrets[$id] = ['password' => $password, 'token' => $token];
        }
        $this->entities->persist(new Page(bin2hex(random_bytes(16)), 'Welcome to the editorial desk',
            "This is a private working draft. Publish it to try exact human approval.\nYou can keep editing its next draft while the public version stays unchanged."));
        $this->entities->flush();
        foreach ($secrets as $id => $secret) {
            $secrets[$id]['passkey_link'] = rtrim($this->origin, '/').'/account/passkeys/link/'.$this->enrollment->issueLink($id, 'local setup');
        }

        return $secrets;
    }
}
