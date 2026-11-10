<?php

declare(strict_types=1);

use App\Mcp\PageTools;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;

return static function (ContainerConfigurator $container): void {
    $container->extension('mcp', ['servers' => ['editorial' => [
        'name' => 'Editorial Desk', 'version' => '1.0.0',
        'instructions' => 'Save working drafts freely, including for live pages. A protected request can be pending: show its review_url and wait for approval_status before saying the change happened. The account role determines whether a passkey is required. Never infer human approval from completed alone.',
        'http' => ['path' => '/mcp', 'allowed_hosts' => ['%env(key:host:url:APP_ORIGIN)%']],
        'session' => ['lock' => ['enabled' => true]],
        'request_state' => ['key' => '%env(MCP_REQUEST_STATE_KEY)%', 'ttl' => 900],
        'registry' => ['tools' => [PageTools::class, ApprovalStatusTool::class, ApprovalHandoffTool::class], 'apps' => [ApprovalCardApp::class]],
    ]]]);
};
