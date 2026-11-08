<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Action;

use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\CanonicalJson;

/** Captures saved field values, including translations, without rendering them. */
final class NodeSnapshot
{
    /** Fields Drupal legitimately generates when creating the publication revision. */
    private const GENERATED = [
        'vid', 'revision_timestamp', 'revision_uid', 'revision_log',
        'revision_default', 'revision_translation_affected', 'changed',
    ];

    public function capture(NodeInterface $node): array
    {
        $translations = [];
        foreach ($node->getTranslationLanguages() as $langcode => $language) {
            $translation = $node->getTranslation($langcode);
            $fields = [];
            foreach ($translation->getFieldDefinitions() as $name => $definition) {
                // Computed references and render caches are not stored content.
                // Moderation state is computed from a separately stored entity.
                if (!$definition->isComputed() || $name === 'moderation_state') {
                    $items = [];
                    foreach ($translation->get($name) as $delta => $item) {
                        $values = $item->getValue() ?? [];
                        foreach ($item->getProperties(false) as $propertyName => $property) {
                            $raw = $property->getValue();
                            // SQL drivers return e.g. "1" while an in-memory
                            // status change is true. Drupal's declared scalar
                            // type defines equality, without lossy string casts.
                            $values[$propertyName] = $raw !== null && $property instanceof PrimitiveInterface
                                ? $property->getCastedValue() : $raw;
                        }
                        $items[$delta] = $values;
                    }
                    $fields[$name] = $items;
                }
            }
            $translations[$langcode] = (object) $fields;
        }
        ksort($translations, SORT_STRING);
        $snapshot = [
            'entity_type' => 'node',
            'node_id' => (string) $node->id(),
            'uuid' => $node->uuid(),
            'bundle' => $node->bundle(),
            'revision_id' => (string) $node->getRevisionId(),
            'language' => $node->getUntranslated()->language()->getId(),
            'default_revision' => $node->isDefaultRevision(),
            'translations' => (object) $translations,
        ];
        // Fail closed on field values that cannot be represented losslessly by
        // the approval core (for example floats or arbitrary PHP objects).
        try {
            CanonicalJson::encode($snapshot);
        } catch (\Throwable $error) {
            throw new ApprovalException('unsupported_field_value', 'A saved field value cannot be represented by the exact approval format.', $error);
        }
        return $snapshot;
    }

    public function content(NodeInterface $node): array
    {
        return $this->contentFromSnapshot($this->capture($node));
    }

    /** Compare every saved content field; permit only documented server metadata. */
    public function contentFromSnapshot(array $snapshot): array
    {
        $translations = [];
        foreach ($this->map($snapshot['translations']) as $language => $values) {
            $fields = $this->map($values);
            foreach (self::GENERATED as $name) {
                unset($fields[$name]);
            }
            $translations[$language] = (object) $fields;
        }
        return [
            'node_id' => $snapshot['node_id'], 'uuid' => $snapshot['uuid'],
            'bundle' => $snapshot['bundle'], 'language' => $snapshot['language'],
            'translations' => (object) $translations,
        ];
    }

    /** Rebuild only an access-check subject, never save this reconstructed entity. */
    public function restore(NodeStorageInterface $storage, array $snapshot): NodeInterface
    {
        $translations = $this->map($snapshot['translations']);
        $language = $snapshot['language'];
        if (!isset($translations[$language])) {
            throw new ApprovalException('invalid_snapshot', 'The approved source language is missing.');
        }
        $node = $storage->create($this->arrays($translations[$language]));
        if (!$node instanceof NodeInterface) {
            throw new ApprovalException('invalid_snapshot', 'The approval source is not a node.');
        }
        foreach ($translations as $langcode => $fields) {
            if ($langcode !== $language && !$node->hasTranslation($langcode)) {
                $node->addTranslation($langcode, $this->arrays($fields));
            }
        }
        $node->enforceIsNew(false);
        $node->setNewRevision(false);
        $node->isDefaultRevision((bool) $snapshot['default_revision']);
        return $node;
    }

    public function digest(mixed $value): string
    {
        return hash('sha256', CanonicalJson::encode($value));
    }

    public function same(mixed $a, mixed $b): bool
    {
        return hash_equals($this->digest($a), $this->digest($b));
    }

    public function map(mixed $value): array
    {
        return $value instanceof \stdClass ? get_object_vars($value) : $value;
    }

    private function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        return is_array($value) ? array_map($this->arrays(...), $value) : $value;
    }
}
