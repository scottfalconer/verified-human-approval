<?php

declare(strict_types=1);

/** Separate real Drupal process for the SQLite concurrent completion test. */

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use ExactApproval\Core\Actor;
use ExactApproval\Core\CanonicalJson;
use Symfony\Component\HttpFoundation\Request;

try {
  if (count($argv) !== 4 || !in_array($argv[3], ['a', 'b'], TRUE)) {
    throw new \InvalidArgumentException('Expected payload, barrier directory, and worker identifier.');
  }
  $project = dirname(__DIR__, 4);
  $example = $project.'/examples/drupal';
  $loader = require $example.'/vendor/autoload.php';
  chdir($example.'/web');
  $request = Request::create('http://localhost:8090');
  $kernel = DrupalKernel::createFromRequest($request, $loader, 'prod');
  $kernel->boot();
  $kernel->preHandle($request);
  if (Settings::get('exact_approval_acceptance_test') !== TRUE) {
    throw new \RuntimeException('The isolated acceptance site marker is required.');
  }
  $data = CanonicalJson::map(CanonicalJson::decode(file_get_contents($argv[1])));
  $actor = new Actor($data['userId'], $data['tenantId']);
  $account = \Drupal::service('exact_approval.actor_provider')->account($actor);
  \Drupal::service('account_switcher')->switchTo($account);
  file_put_contents($argv[2].'/ready-'.$argv[3], 'ready');
  $deadline = microtime(TRUE) + 10;
  while (!is_file($argv[2].'/go')) {
    if (microtime(TRUE) > $deadline) {
      throw new \RuntimeException('Race start barrier timed out.');
    }
    usleep(10_000);
    clearstatcache();
  }
  $state = \Drupal::service('exact_approval.approvals')->complete($actor, $data['ceremonyId'], CanonicalJson::map($data['assertion']));
  fwrite(STDOUT, json_encode(['status' => $state->status, 'requestId' => $state->request->id, 'result' => $state->result], JSON_THROW_ON_ERROR)."\n");
  exit(0);
}
catch (\Throwable $error) {
  $messages = [];
  for ($cause = $error; $cause !== NULL; $cause = $cause->getPrevious()) {
    $messages[] = get_class($cause).': '.$cause->getMessage();
  }
  fwrite(STDERR, implode(' <- ', $messages)."\n");
  exit(1);
}
