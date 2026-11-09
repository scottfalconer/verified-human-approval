<?php

declare(strict_types=1);

namespace Drupal\Tests\verified_human_approval\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\Form\NodeForm;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Tests the actual Drupal node form build, validation and draft save path. */
#[Group('verified_human_approval')]
#[RunTestsInSeparateProcesses]
final class NodePublicationFormTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'sqlite',
    'verified_human_approval',
  ];

  private NodeInterface $published;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'filter', 'field']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'unprotected', 'name' => 'Unprotected'])->save();
    $admin = User::create(['name' => 'admin', 'status' => 1]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);
    $request = Request::create('https://approval.test/node/add/page');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->getSession()->set('uid', $admin->id());
    $this->container->get('request_stack')->push($request);

    // Model content which was published before the type became protected.
    $this->published = Node::create(['type' => 'page', 'title' => 'Already live', 'status' => 1]);
    $this->published->save();
    $settings = Settings::getAll();
    $settings['verified_human_approval'] = ['protected_bundles' => ['page']];
    new Settings($settings);
    $this->container->get('router.builder')->rebuild();
  }

  public function testProtectedCreateFormDefaultsToDraftAndLinksNextStep(): void {
    $new = Node::create(['type' => 'page', 'title' => 'New protected content', 'status' => 1]);
    [$form, $state] = $this->buildNodeForm($new);
    $this->assertFalse($new->isPublished());
    $this->assertFalse($form['status']['widget']['value']['#default_value']);
    $this->assertStringContainsString('unpublished draft', (string) $form['verified_human_approval_help']['message']['#markup']);

    $this->container->get('form_builder')->submitForm($state->getFormObject(), $state);
    $this->assertSame([], $state->getErrors());
    $saved = $state->getFormObject()->getEntity();
    $this->assertFalse($saved->isNew());
    $this->assertFalse(Node::load($saved->id())->isPublished());
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertStringContainsString('/node/' . $saved->id() . '/request-verified-publication', implode(' ', $messages));
  }

  public function testPublishedSaveGetsValidationErrorWithoutSilentUnpublishing(): void {
    [$form, $state] = $this->buildNodeForm($this->published);
    $this->assertTrue($this->published->isPublished());
    $this->assertTrue($form['status']['widget']['value']['#default_value']);
    $this->assertStringContainsString('takes it off the public site', (string) $form['verified_human_approval_help']['message']['#markup']);
    $state->setValue(['title', 0, 'value'], 'Unsaved change');
    $this->container->get('form_builder')->submitForm($state->getFormObject(), $state);
    $this->assertArrayHasKey('status', $state->getErrors());
    $this->assertStringContainsString('Clear Published', (string) $state->getErrors()['status']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->published->id()]);
    $stored = $storage->load($this->published->id());
    $this->assertTrue($stored->isPublished());
    $this->assertSame('Already live', $stored->label());

    // Only an explicit unchecked Published value permits this normal save.
    [, $state] = $this->buildNodeForm($stored);
    // Form API represents an unchecked browser checkbox as NULL input.
    $state->setValue(['status', 'value'], NULL);
    $this->container->get('form_builder')->submitForm($state->getFormObject(), $state);
    $this->assertSame([], $state->getErrors());
    $storage->resetCache([$stored->id()]);
    $this->assertFalse($storage->load($stored->id())->isPublished());
  }

  public function testUnprotectedCreateFormKeepsItsPublicationDefault(): void {
    $node = Node::create(['type' => 'unprotected', 'title' => 'Ordinary content', 'status' => 1]);
    [$form] = $this->buildNodeForm($node);
    $this->assertTrue($node->isPublished());
    $this->assertTrue($form['status']['widget']['value']['#default_value']);
    $this->assertArrayNotHasKey('verified_human_approval_help', $form);
  }

  public function testNewPublishedSubmissionWithButtonValidatorsIsRejected(): void {
    $this->container->get('entity_type.manager')->getDefinition('node')
      ->setFormClass('default', NodeFormWithButtonValidation::class);
    $node = Node::create(['type' => 'page', 'title' => 'Do not publish', 'status' => 1]);
    [, $state] = $this->buildNodeForm($node);
    $state->setValue(['status', 'value'], 1);
    $this->container->get('form_builder')->submitForm($state->getFormObject(), $state);
    $this->assertArrayHasKey('status', $state->getErrors());
    $this->assertTrue($state->getFormObject()->getEntity()->isNew());
    $this->assertSame(1, (int) $this->container->get('database')->select('node')->countQuery()->execute()->fetchField());
  }

  private function buildNodeForm(NodeInterface $node): array {
    $object = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $state = new FormState();
    $form = $this->container->get('form_builder')->buildForm($object, $state);
    $state->setValue('op', (string) $form['actions']['submit']['#value']);
    return [$form, $state];
  }

}

/** Models a node form whose Save button supplies its own validation list. */
final class NodeFormWithButtonValidation extends NodeForm {

  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#validate'] = ['::validateForm'];
    return $actions;
  }

}
