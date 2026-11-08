<?php

declare(strict_types=1);

/** Invoked by setup through Drush, after the isolated database exists. */
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (!Settings::get('exact_approval_acceptance_test', false)) {
    throw new RuntimeException('This setup is restricted to the isolated demo.');
}
$demo = json_decode(file_get_contents(dirname(DRUPAL_ROOT).'/var/demo.json'), true, 512, JSON_THROW_ON_ERROR);
\Drupal::configFactory()->getEditable('exact_approval.settings')->set('origin', $demo['origin'])->set('rp_id', $demo['rp_id'])->save();
if (!NodeType::load('announcement')) {
    NodeType::create(['type' => 'announcement', 'name' => 'Announcement', 'new_revision' => true])->save();
}
if (!Role::load('campaign_editor')) {
    Role::create(['id' => 'campaign_editor', 'label' => 'Campaign editor', 'permissions' => [
        'access content', 'access content overview', 'create announcement content', 'edit any announcement content',
        'delete any announcement content', 'administer node published status', 'view own unpublished content', 'access mcp server',
    ]])->save();
}
$editor = user_load_by_name('editor');
if (!$editor) {
    $editor = User::create(['name' => 'editor', 'mail' => 'editor@example.test', 'pass' => $demo['editor_password'], 'status' => 1, 'roles' => ['campaign_editor']]);
    $editor->save();
}
foreach (['Campaign announcement ready for publication', 'Obsolete campaign announcement for deletion'] as $title) {
    Node::create(['type' => 'announcement', 'title' => $title, 'uid' => $editor->id(), 'status' => 0])->save();
}
\Drupal::configFactory()->getEditable('exact_approval.settings')->set('rules', [
    ['operation' => 'publish', 'bundle' => 'announcement', 'permissions' => ['administer node published status']],
    ['operation' => 'delete', 'bundle' => 'announcement', 'permissions' => ['delete any announcement content']],
])->save();
\Drupal::service('theme_installer')->install(['claro']);
\Drupal::configFactory()->getEditable('system.theme')->set('default', 'claro')->set('admin', 'claro')->save();
drupal_flush_all_caches();
echo "Configured a publisher, two saved drafts, and protected publish/delete rules.\n";

