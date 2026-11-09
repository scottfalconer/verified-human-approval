<?php

declare(strict_types=1);

namespace Drupal\Tests\verified_human_approval\Kernel;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/** Exercises native route access and the escaped browser ceremony interface. */
#[Group('verified_human_approval')]
#[RunTestsInSeparateProcesses]
final class UiContractTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'sqlite',
    'basic_auth', 'verified_human_approval',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'node', 'filter', 'field']);
    Role::create([
      'id' => 'approval_editor', 'label' => 'Approval editor',
      'permissions' => ['use verified human approval'],
    ])->save();
    $this->container->get('router.builder')->rebuild();
  }

  public function testCeremonyRoutesRequireLoginPermissionAndSessionCsrf(): void {
    $editor = new UserSession(['uid' => 7, 'roles' => ['authenticated', 'approval_editor']]);
    $unprivileged = new UserSession(['uid' => 8, 'roles' => ['authenticated']]);
    $anonymous = new UserSession();
    // Even an accidentally granted anonymous permission must not open these
    // routes: Drupal's CSRF header checker itself permits anonymous requests.
    Role::load('anonymous')->grantPermission('use verified human approval')->save();
    $this->assertTrue($anonymous->hasPermission('use verified human approval'));
    $access = $this->container->get('access_manager');
    $token = $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    foreach ([
      '/verified-human-approval/passkeys/options',
      '/verified-human-approval/passkeys/complete',
      '/verified-human-approval/request/example/options',
      '/verified-human-approval/request/example/complete',
      '/verified-human-approval/request/example/cancel',
    ] as $path) {
      $request = $this->matchedRequest($path, 'POST');
      $this->assertTrue($access->checkRequest($request, $editor, TRUE)->isForbidden(), $path . ': missing CSRF token');
      $request->headers->set('X-CSRF-Token', 'invalid-token');
      $this->assertTrue($access->checkRequest($request, $editor, TRUE)->isForbidden(), $path . ': invalid CSRF token');
      $request->headers->set('X-CSRF-Token', $token);
      $this->assertTrue($access->checkRequest($request, $editor, TRUE)->isAllowed(), $path . ': valid session token');
      $this->assertFalse($access->checkRequest($request, $unprivileged, TRUE)->isAllowed(), $path . ': permission required');
      $this->assertFalse($access->checkRequest($request, $anonymous, TRUE)->isAllowed(), $path . ': login required');

      // Exercise Drupal's post-routing authentication filter, rather than just
      // asserting the YAML spelling of _auth. An API login cannot use this UI.
      $authentication = $this->container->get('authentication');
      $this->assertTrue($authentication->appliesToRoutedRequest($request, TRUE), $path . ': cookie login');
      $request->headers->set('PHP_AUTH_USER', 'editor');
      $request->headers->set('PHP_AUTH_PW', 'test-password');
      $this->assertFalse($authentication->appliesToRoutedRequest($request, TRUE), $path . ': API authentication excluded');
    }
  }

  public function testFrozenProposalIsEscapedAndBoundToBrowserControls(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();
    $build = [
      '#theme' => 'verified_human_approval_review',
      '#request_id' => 'example', '#status' => 'pending',
      '#statement' => 'Publish <script>statement()</script> exactly.',
      '#display' => [
        'title' => '<img src=x onerror=alert(1)>',
        'summary' => '<script>summary()</script>',
        'body' => "<script>body()</script>\nExact stored text.",
        'source_revision' => '42', 'language' => 'en',
        'resulting_change' => 'Unpublished → published',
        'content_digest' => 'content-digest',
        'owner_uid' => '73', 'promoted_to_front_page' => 'Yes', 'sticky' => 'No',
      ],
      '#digest' => 'proposal-digest', '#expires' => 'later',
      '#csrf_token' => 'session-token',
      '#options_url' => '/approval/options', '#complete_url' => '/approval/complete',
      '#cancel_url' => '/approval/cancel', '#review_url' => '/approval/review',
      '#receipt_url' => '/approval/receipt', '#key_url' => '/approval/key',
      '#passkeys_url' => '/approval/passkeys', '#return_url' => '/',
      '#cache' => ['max-age' => 0],
    ];
    $xpath = $this->renderedXpath($build);
    $this->assertSame(0, $xpath->query('//script | //img')->length);
    $this->assertSame($build['#display']['title'], $xpath->evaluate('string(//h2)'));
    $this->assertSame($build['#statement'], $xpath->evaluate('string(//blockquote)'));
    $this->assertSame($build['#display']['summary'], $xpath->evaluate('string((//pre)[1])'));
    $this->assertSame($build['#display']['body'], $xpath->evaluate('string((//pre)[2])'));
    foreach ([
      'Content owner (user ID)' => '73',
      'Promoted to front page' => 'Yes',
      'Sticky at top of lists' => 'No',
    ] as $label => $value) {
      $this->assertSame($value, $xpath->evaluate('string(//dt[text()="' . $label . '"]/following-sibling::dd[1])'));
    }
    foreach ([
      'csrf' => 'session-token', 'options-url' => '/approval/options',
      'complete-url' => '/approval/complete', 'cancel-url' => '/approval/cancel',
      'success-url' => '/approval/review',
    ] as $attribute => $value) {
      $this->assertSame($value, $xpath->evaluate('string(//*[@data-vha-ceremony]/@data-' . $attribute . ')'));
    }
    $this->assertSame(1, $xpath->query('//*[@data-vha-message][@role="status"][@aria-live="polite"]')->length);

    $build['#status'] = 'completed';
    $build['#has_proof'] = TRUE;
    $build['#has_receipt'] = TRUE;
    $xpath = $this->renderedXpath($build);
    $this->assertSame(0, $xpath->query('//*[@data-vha-ceremony] | //*[@data-vha-start]')->length);
    $this->assertSame(1, $xpath->query('//a[@href="/approval/receipt"][@download]')->length);
  }

  private function matchedRequest(string $path, string $method): Request {
    $request = Request::create('https://approval.test' . $path, $method);
    $name = $this->container->get('session_configuration')->getOptions($request)['name'];
    $request->cookies->set($name, 'browser-session');
    $router = $this->container->get('router.no_access_checks');
    $router->getContext()->fromRequest($request);
    $request->attributes->add($router->matchRequest($request));
    return $request;
  }

  private function renderedXpath(array $build): \DOMXPath {
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $document = new \DOMDocument();
    // libxml's HTML4 parser warns about HTML5 elements; they remain in the DOM.
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new \DOMXPath($document);
  }

}
