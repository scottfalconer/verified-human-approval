<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;

/**
 * A tripwire on ORM flushes: it refuses a change to a protected entity or field (marked #[RequiresApproval], or
 * named in the configuration) unless the flush happens while an approved action executes and that action permitted
 * the entity. EntityAction permits the entities it locks; another adapter calls permit() itself.
 *
 * Register it for onFlush and preUpdate with the lowest priority, so it sees what other listeners changed. It
 * covers the unit of work only: DQL UPDATE or DELETE and plain SQL do not pass through it, so keep those away from
 * protected rows, as you keep every other write path behind the approval service.
 */
final class ProtectedEntityListener
{
    /** @var \WeakMap<object, string> each permitted entity, with the approval request it was permitted for */
    private \WeakMap $permitted;

    /** @var array<class-string, array{entity: bool, fields: list<string>}> */
    private array $rules = [];

    /**
     * @param array<class-string, list<string>|null> $protected entities protected by configuration: each class's
     *                                                          fields, or null for the whole entity
     */
    public function __construct(private readonly ExecutionGuard $guard, private readonly array $protected = [])
    {
        $this->permitted = new \WeakMap();
    }

    /** Permits protected changes to these entities in this approved execution's flushes. Valid only while it runs. */
    public function permit(object ...$entities): void
    {
        $executing = $this->guard->current() ?? throw new \LogicException('Only an action executing under an approval can permit protected changes.');
        foreach ($entities as $entity) {
            $this->permitted[$entity] = $executing->requestId;
        }
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $manager = $event->getObjectManager();
        $work = $manager->getUnitOfWork();
        foreach ($work->getScheduledEntityInsertions() as $entity) {
            $values = array_map(static fn (array|PersistentCollection $change): mixed => $change instanceof PersistentCollection ? $change : $change[1], $work->getEntityChangeSet($entity));
            $this->assertAllowed($manager, $entity, 'inserted', $this->held($manager, $entity, $values));
        }
        foreach ($work->getScheduledEntityUpdates() as $entity) {
            $this->assertAllowed($manager, $entity, 'changed', array_keys($work->getEntityChangeSet($entity)));
        }
        foreach ($work->getScheduledEntityDeletions() as $entity) {
            $this->assertAllowed($manager, $entity, 'deleted', $this->held($manager, $entity, $work->getOriginalEntityData($entity)));
        }
        foreach ([...$work->getScheduledCollectionUpdates(), ...$work->getScheduledCollectionDeletions()] as $collection) {
            if (null !== $owner = $collection->getOwner()) {
                $this->assertAllowed($manager, $owner, 'changed', [$collection->getMapping()->fieldName]);
            }
        }
    }

    /** The final changes of an update, after other listeners had their say. */
    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $manager = $event->getObjectManager();
        $entity = $event->getObject();
        $work = $manager->getUnitOfWork();
        // Earlier preUpdate listeners may set a property without updating the event's change set. Doctrine
        // recomputes those changes after dispatch; do it before this final check so it sees the same update.
        $work->recomputeSingleEntityChangeSet($manager->getClassMetadata($entity::class), $entity);
        $this->assertAllowed($manager, $entity, 'changed', array_keys($work->getEntityChangeSet($entity)));
    }

    /**
     * @param list<string> $fields the fields the change touches
     */
    private function assertAllowed(EntityManagerInterface $manager, object $entity, string $how, array $fields): void
    {
        $metadata = $manager->getClassMetadata($entity::class);
        $rules = $this->rules($metadata);
        $touched = $rules['entity'] ? $fields : array_values(array_intersect($fields, $rules['fields']));
        if (!$rules['entity'] && [] === $touched) {
            return;
        }
        $executing = $this->guard->current();
        if (null !== $executing && ($this->permitted[$entity] ?? null) === $executing->requestId) {
            return;
        }
        $what = $rules['entity'] ? $metadata->getName() : $metadata->getName().'::$'.implode(', $', $touched);
        throw new ApprovalException('approval_required', \sprintf('%s cannot be %s directly: it changes only through an approved action. Request approval for the change instead.', $what, $how));
    }

    /**
     * Protected fields that hold a value other than null, or whose value is unknown. An unloaded reference has
     * no original data; its missing fields must not be treated as null and allowed to be deleted as a draft.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function held(EntityManagerInterface $manager, object $entity, array $values): array
    {
        $rules = $this->rules($manager->getClassMetadata($entity::class));

        return $rules['entity'] ? array_keys($values) : array_values(array_filter($rules['fields'], static fn (string $field): bool => !\array_key_exists($field, $values) || null !== $values[$field]));
    }

    /**
     * The entity's protection, with its protected fields named as Doctrine names them in a change set: a field of
     * an embedded object by its path, such as published.title. Marking the embedded property, or naming it in the
     * configuration, protects each of its fields; a mark on a property of the embeddable protects that field
     * wherever the embeddable is used.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array{entity: bool, fields: list<string>}
     */
    private function rules(ClassMetadata $metadata): array
    {
        $class = $metadata->getName();
        if (isset($this->rules[$class])) {
            return $this->rules[$class];
        }
        $entity = false;
        $marked = [];
        for ($reflection = $metadata->getReflectionClass(); false !== $reflection; $reflection = $reflection->getParentClass()) {
            $entity = $entity || [] !== $reflection->getAttributes(RequiresApproval::class);
            foreach ($reflection->getProperties() as $property) {
                if ([] !== $property->getAttributes(RequiresApproval::class)) {
                    $marked[] = $property->getName();
                }
            }
            if (\array_key_exists($reflection->getName(), $this->protected)) {
                $configured = $this->protected[$reflection->getName()];
                $entity = $entity || null === $configured;
                $marked = [...$marked, ...($configured ?? [])];
            }
        }
        $fields = [];
        foreach ($marked as $name) {
            if (!isset($metadata->embeddedClasses[$name])) {
                $fields[] = $name;
                continue;
            }
            foreach (array_keys($metadata->fieldMappings) as $field) {
                if (str_starts_with($field, $name.'.')) {
                    $fields[] = $field;
                }
            }
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (null !== $mapping->originalClass && null !== $mapping->originalField
                && [] !== (new \ReflectionProperty($mapping->originalClass, $mapping->originalField))->getAttributes(RequiresApproval::class)) {
                $fields[] = $field;
            }
        }

        return $this->rules[$class] = ['entity' => $entity, 'fields' => array_values(array_unique($fields))];
    }
}
