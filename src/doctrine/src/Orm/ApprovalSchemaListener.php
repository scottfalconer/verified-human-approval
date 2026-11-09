<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine\Orm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;

/**
 * Adds the approval tables to the schema the ORM generates for the approval connection, as Symfony's Doctrine
 * Messenger, Lock and Cache stores add theirs, so doctrine:migrations:diff and doctrine:schema:update create them
 * instead of proposing to drop them. Register it for the postGenerateSchema event.
 */
final readonly class ApprovalSchemaListener
{
    public function __construct(private Connection $connection)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        // Only the entity manager on the approval connection: its tables live in that database.
        if ($event->getEntityManager()->getConnection() !== $this->connection) {
            return;
        }
        // An ORM before 3.6.8 cannot take a new schema, so the tables go into the one it holds.
        $replace = (new \ReflectionClass($event))->hasMethod('setSchema');
        $schema = ApprovalSchema::addTo($event->getSchema(), $this->connection->getDatabasePlatform(), !$replace, $this->connection->getConfiguration()->getSchemaAssetsFilter());
        if ($replace && $schema !== $event->getSchema()) {
            $event->setSchema($schema);
        }
    }
}
