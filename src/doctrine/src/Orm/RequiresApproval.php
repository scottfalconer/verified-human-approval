<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine\Orm;

/**
 * Marks an entity, or some of its fields, as changed only by an approved action, which ProtectedEntityListener
 * then enforces on every ORM flush.
 *
 * On an entity class: inserting, changing or deleting it needs an approved execution for that entity. On a field
 * or association: changing it does, and so does inserting or deleting the entity while it holds a value other than
 * null. Keep the approved state in such fields (a published title and body, say, next to the editable draft), and
 * drafts stay free to edit.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
final readonly class RequiresApproval
{
}
