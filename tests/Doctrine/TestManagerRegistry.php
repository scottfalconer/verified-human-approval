<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;

/** One connection and one entity manager, which resetManager() replaces with a fresh one, as Symfony's registry does. */
final class TestManagerRegistry extends AbstractManagerRegistry
{
    public int $resets = 0;
    private EntityManagerInterface $manager;

    /** @param \Closure(): EntityManagerInterface $factory */
    public function __construct(private readonly Connection $connection, private readonly \Closure $factory)
    {
        parent::__construct('ORM', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default');
        $this->manager = ($factory)();
    }

    protected function getService(string $name): object
    {
        return 'connection' === $name ? $this->connection : $this->manager;
    }

    protected function resetService(string $name): void
    {
        ++$this->resets;
        $this->manager = ($this->factory)();
    }
}
