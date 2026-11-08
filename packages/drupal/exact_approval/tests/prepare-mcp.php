<?php

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;

if (!Settings::get('exact_approval_acceptance_test', false)) {
    throw new RuntimeException('Acceptance fixtures require the disposable-site marker.');
}
$role = Role::load('campaign_editor');
$role->grantPermission('access mcp server')->save();
$editor = user_load_by_name('editor');
$node = Node::create(['type' => 'announcement', 'title' => 'MCP approval acceptance '.bin2hex(random_bytes(4)), 'uid' => $editor->id(), 'status' => 0]);
$node->save();
echo json_encode(['node_id' => (int) $node->id(), 'user_id' => (int) $editor->id()]), "\n";

