<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\exact_approval\Controller\HumanRequestGuard;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\user\PermissionHandlerInterface;
use ExactApproval\Core\ApprovalPolicy;
use ExactApproval\Core\PreparedAction;
use ExactApproval\WebAuthn\Configuration;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Bind protected entity operations to permissions already defined by Drupal. */
final class SettingsForm extends ConfigFormBase
{
    public function __construct(
        ConfigFactoryInterface $configFactory,
        TypedConfigManagerInterface $typedConfigManager,
        protected EntityTypeManagerInterface $entities,
        protected PermissionHandlerInterface $permissionHandler,
        protected ActorProvider $actors,
    ) {
        parent::__construct($configFactory, $typedConfigManager);
    }

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('config.factory'), $container->get('config.typed'), $container->get('entity_type.manager'), $container->get('user.permissions'), $container->get('exact_approval.actor_provider'));
    }

    public function getFormId(): string
    {
        return 'exact_approval_settings';
    }

    protected function getEditableConfigNames(): array
    {
        return ['exact_approval.settings'];
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $this->assertAdministrator();
        $settings = $this->config('exact_approval.settings');
        $rules = [];
        foreach ($settings->get('rules') ?? [] as $rule) {
            $rules[$rule['bundle']][$rule['operation']] = $rule;
        }
        $form['description'] = [
            '#type' => 'item',
            '#markup' => $this->t('Require the holder of a registered key to approve publishing or deleting saved content. Native Drupal access checks still apply. Enabling publish protection also protects edits that leave content published: save an unpublished draft or revision first, then request approval for that saved revision.'),
        ];
        $form['policies'] = ['#type' => 'container', '#tree' => true];
        foreach ($this->entities->getStorage('node_type')->loadMultiple() as $bundle => $type) {
            $form['policies'][$bundle] = ['#type' => 'details', '#title' => $type->label(), '#open' => true];
            foreach (['publish' => $this->t('Publish content'), 'delete' => $this->t('Delete content')] as $operation => $label) {
                $rule = $rules[$bundle][$operation] ?? null;
                $selected = $rule['permissions'] ?? $this->defaultPermissions($operation, $bundle);
                $options = $this->permissionOptions($operation, $bundle, $selected);
                $form['policies'][$bundle][$operation] = ['#type' => 'fieldset', '#title' => $label];
                $form['policies'][$bundle][$operation]['enabled'] = [
                    '#type' => 'checkbox',
                    '#title' => $this->t('Require exact approval'),
                    '#default_value' => $rule !== null,
                ];
                $form['policies'][$bundle][$operation]['permissions'] = [
                    '#type' => 'select',
                    '#title' => $this->t('The user must hold at least one of these permissions'),
                    '#multiple' => true,
                    '#options' => $options,
                    '#default_value' => $selected,
                    '#size' => min(6, max(2, count($options))),
                    '#description' => $operation === 'delete'
                        ? $this->t('Approval is required for this content type. These are additional permission requirements; Drupal still checks ownership and node access. Select both delete-own and delete-any permissions to cover both paths.')
                        : $this->t('Approval is required for this content type. These are additional permission requirements; Drupal still checks update access and its published-status permission, or the actual moderation transition. Selecting a permission does not grant it.'),
                    '#states' => ['visible' => [':input[name="policies[' . $bundle . '][' . $operation . '][enabled]"]' => ['checked' => true]]],
                ];
            }
        }
        if ($form['policies'] === ['#type' => 'container', '#tree' => true]) {
            $form['no_types'] = ['#markup' => $this->t('Create a content type before enabling approval rules.')];
        }
        $form['statements'] = ['#type' => 'details', '#title' => $this->t('Exact approval statements'), '#tree' => true, '#open' => true];
        $form['statements']['help'] = [
            '#type' => 'item',
            '#markup' => $this->t('Plain text with optional placeholders: {{title}}, {{nodeId}}, {{bundle}}, {{revisionId}}, {{operation}}, {{transition}}, {{site}}. The site placeholder is this site’s UUID. The resolved statement is included in the signed proposal. Changing a rule or statement invalidates pending requests.'),
        ];
        foreach (['publish' => $this->t('Publish statement'), 'delete' => $this->t('Delete statement')] as $operation => $label) {
            $form['statements'][$operation] = [
                '#type' => 'textarea', '#title' => $label, '#required' => true, '#maxlength' => 8192, '#rows' => 3,
                '#default_value' => $settings->get('statements.' . $operation) ?? ('I approve ' . ($operation === 'publish' ? 'publishing' : 'deleting') . ' "{{title}}" (content {{nodeId}}, revision {{revisionId}}) on this site.'),
            ];
        }
        $form['lifetime'] = [
            '#type' => 'number', '#title' => $this->t('Approval request lifetime in seconds'),
            '#default_value' => $settings->get('lifetime') ?? 600, '#min' => 30, '#max' => 3600, '#required' => true,
        ];
        $form['webauthn'] = ['#type' => 'details', '#title' => $this->t('Passkey relying party'), '#open' => empty($settings->get('origin'))];
        $form['webauthn']['origin'] = [
            '#type' => 'url', '#title' => $this->t('Exact trusted origin'), '#required' => true,
            '#default_value' => $settings->get('origin'),
            '#description' => $this->t('The configured HTTPS origin, for example https://cms.example.org. HTTP localhost is accepted only for development. Do not include a path or infer this from incoming host headers.'),
        ];
        $form['webauthn']['rp_id'] = [
            '#type' => 'textfield', '#title' => $this->t('Relying party ID'), '#required' => true,
            '#default_value' => $settings->get('rp_id'),
            '#description' => $this->t('The trusted hostname, for example cms.example.org. Changing it can make existing passkeys unusable.'),
        ];
        $form['#cache']['max-age'] = 0;

        return parent::buildForm($form, $form_state);
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $this->assertAdministrator();
        parent::validateForm($form, $form_state);
        try {
            new Configuration(trim((string) $form_state->getValue('rp_id')), trim((string) $form_state->getValue('origin')));
        } catch (\InvalidArgumentException $error) {
            $form_state->setErrorByName('origin', $error->getMessage());
        }
        $available = $this->permissionHandler->getPermissions();
        foreach ($form_state->getValue('policies', []) as $bundle => $operations) {
            foreach (['publish', 'delete'] as $operation) {
                $selected = array_values(array_filter($operations[$operation]['permissions'] ?? [], static fn (mixed $value): bool => is_string($value) && $value !== ''));
                if (!empty($operations[$operation]['enabled']) && ($selected === [] || array_diff($selected, array_keys($available)) !== [])) {
                    $form_state->setErrorByName('policies][' . $bundle . '][' . $operation . '][permissions', $this->t('Select at least one currently available Drupal permission for each enabled rule.'));
                }
            }
        }
        foreach (['publish', 'delete'] as $operation) {
            try {
                $policy = new ApprovalPolicy('node.' . $operation, 'validation', (string) $form_state->getValue(['statements', $operation]), (int) $form_state->getValue('lifetime'));
                $policy->statement(new PreparedAction('node.' . $operation, 'validation', ['nodeId' => '1'], [], [], ['title' => 'Example'], [
                    'title' => 'Example', 'nodeId' => '1', 'bundle' => 'page', 'revisionId' => '1', 'operation' => $operation, 'transition' => '', 'site' => 'example-site-uuid',
                ]));
            } catch (\InvalidArgumentException | \ExactApproval\Core\ApprovalException $error) {
                $form_state->setErrorByName('statements][' . $operation, $error->getMessage());
            }
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->assertAdministrator();
        $rules = [];
        foreach ($form_state->getValue('policies', []) as $bundle => $operations) {
            foreach (['publish', 'delete'] as $operation) {
                if (!empty($operations[$operation]['enabled'])) {
                    $rules[] = ['operation' => $operation, 'bundle' => $bundle, 'permissions' => array_values($operations[$operation]['permissions'])];
                }
            }
        }
        $this->config('exact_approval.settings')
            ->set('rules', $rules)
            ->set('statements', ['publish' => (string) $form_state->getValue(['statements', 'publish']), 'delete' => (string) $form_state->getValue(['statements', 'delete'])])
            ->set('lifetime', (int) $form_state->getValue('lifetime'))
            ->set('origin', trim((string) $form_state->getValue('origin')))
            ->set('rp_id', trim((string) $form_state->getValue('rp_id')))
            ->save();
        parent::submitForm($form, $form_state);
    }

    private function permissionOptions(string $operation, string $bundle, array $selected): array
    {
        $available = $this->permissionHandler->getPermissions();
        $names = $operation === 'delete'
            ? ['delete own ' . $bundle . ' content', 'delete any ' . $bundle . ' content', 'bypass node access']
            : ['administer node published status', 'administer nodes', 'edit own ' . $bundle . ' content', 'edit any ' . $bundle . ' content', 'bypass node access'];
        if ($operation === 'publish') {
            foreach (array_keys($available) as $name) {
                if (str_starts_with($name, 'use ') && str_contains($name, ' transition ')) $names[] = $name;
            }
        }
        $options = [];
        foreach (array_unique(array_merge($names, $selected)) as $name) {
            if (isset($available[$name])) {
                $options[$name] = $available[$name]['title'] . ' [' . $name . ']';
            } elseif (in_array($name, $selected, true)) {
                $options[$name] = $this->t('Unavailable permission: @permission', ['@permission' => $name]);
            }
        }

        return $options;
    }

    private function defaultPermissions(string $operation, string $bundle): array
    {
        $available = $this->permissionHandler->getPermissions();
        $names = $operation === 'delete'
            ? ['delete own ' . $bundle . ' content', 'delete any ' . $bundle . ' content']
            : ['administer node published status', 'administer nodes'];

        return array_values(array_intersect($names, array_keys($available)));
    }

    private function assertAdministrator(): void
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        if (!$this->actors->account($actor)->hasPermission('administer exact approval')) {
            throw new AccessDeniedHttpException('Only an authenticated administrator may change approval policy.');
        }
    }
}
