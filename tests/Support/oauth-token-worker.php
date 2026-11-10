<?php

declare(strict_types=1);

use League\OAuth2\Server\AuthorizationServer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Tests\Support\OAuthGrantHooks;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = ProcessRace::payload();
    $browser = new KernelBrowser(new DemoKernel($input['runtime'], 'test'), ['HTTP_HOST' => 'localhost:8080']);
    $browser->disableReboot();
    $browser->catchExceptions(false);
    $browser->getKernel()->boot();
    OAuthGrantHooks::afterCredentialRead($browser->getContainer()->get('test.service_container')->get(AuthorizationServer::class), $input['form']['grant_type'], static function (bool $revoked): void {
        if (!$revoked) {
            // The contender starts while this request has read an unused credential but has not consumed it.
            usleep(300000);
        }
    });
    ProcessRace::start($input);
    $browser->request('POST', 'http://localhost:8080/oauth/token', $input['form']);
    $response = $browser->getResponse();
    fwrite(\STDOUT, json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR)], \JSON_THROW_ON_ERROR)."\n");
    $browser->getKernel()->shutdown();
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
