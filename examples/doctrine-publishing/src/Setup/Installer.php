<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Setup;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Example\Publishing\Account\PasskeyEnrollment;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPermissions;
use VerifiedHumanApproval\Example\Publishing\Entity\AgentConnection;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;
use VerifiedHumanApproval\Example\Publishing\Entity\User;

/**
 * Creates the newsroom: the schema, an editor and a reader, each with a password, an agent token and a one-time
 * link for their passkey, and three drafts. Nothing starts live: only an approved action can put an article live,
 * and setup is no exception.
 */
final readonly class Installer
{
    /** The accounts: display name, role and what their agent token may do. */
    public const ACCOUNTS = [
        'editor' => ['Edith (editor)', User::EDITOR, [EditorialPermissions::READ, EditorialPermissions::WRITE, EditorialPermissions::RESULTS]],
        'reader' => ['Rafe (reader)', User::READER, [EditorialPermissions::READ, EditorialPermissions::RESULTS]],
    ];

    private const DRAFTS = [
        ['Harbour Street closes for resurfacing', "Harbour Street will be closed between the bridge and Quay Lane from 6 to 10 May while it is resurfaced.\nBuses divert along Mill Road."],
        ['Library opening hours change', "From 1 June the central library opens at 9:00 on weekdays and closes at 19:00.\nSaturday hours stay the same."],
        ['Spring market returns to the square', 'The spring market is back on Saturday mornings from 8:00, with local growers, bakers and a repair café.'],
    ];

    public function __construct(private EntityManagerInterface $entities, private UserPasswordHasherInterface $passwords, private PasskeyEnrollment $enrollment)
    {
    }

    /**
     * Returns each account's secrets, once. Only hashes of the passwords, tokens and links are stored.
     *
     * @return array<string, array{password: string, agent_token: string, passkey_link: string}>
     */
    public function install(string $origin): array
    {
        $connection = $this->entities->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['publishing_users'])) {
            throw new \RuntimeException('This newsroom is set up already. To start again, use a new database.');
        }
        // The entities' tables, with the approval tables that the bundle's schema listener adds to the ORM's schema
        // (doctrine:migrations:diff would create the same). install() then finds them all, and seeds the lock rows.
        (new SchemaTool($this->entities))->createSchema($this->entities->getMetadataFactory()->getAllMetadata());
        ApprovalSchema::install($connection);

        $secrets = [];
        foreach (self::ACCOUNTS as $id => [$name, $role, $scopes]) {
            $user = new User($id, $name, $role);
            $password = ChallengeBinding::encode(random_bytes(18));
            $user->setPasswordHash($this->passwords->hashPassword($user, $password));
            $token = ChallengeBinding::encode(random_bytes(32));
            $this->entities->persist($user);
            $this->entities->persist(new AgentConnection('agc_'.bin2hex(random_bytes(8)), $id, hash('sha256', $token), implode(' ', $scopes)));
            $secrets[$id] = ['password' => $password, 'agent_token' => $token];
        }
        foreach (self::DRAFTS as [$title, $body]) {
            $this->entities->persist(new Article(bin2hex(random_bytes(8)), $title, $body));
        }
        $this->entities->flush();

        $accounts = [];
        foreach ($secrets as $id => $secret) {
            $link = $this->enrollment->issueLink($id, 'setup');
            $accounts[$id] = $secret + ['passkey_link' => rtrim($origin, '/').'/account/passkey/link/'.$link];
        }

        return $accounts;
    }
}
