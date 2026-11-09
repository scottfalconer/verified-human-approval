<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPolicy;
use VerifiedHumanApproval\Example\Publishing\Mcp\ArticleTools;

return static function (ContainerConfigurator $container): void {
    $container->extension('mcp', ['servers' => ['newsroom' => [
        'name' => 'Newsroom',
        'version' => '1.0.0',
        'instructions' => 'A newsroom: list_articles reads it, save_draft writes drafts. Publishing, changing a live article, archiving and deleting need the person to approve the exact change with their passkey on the site: those tools return a pending result with a review_url. Show it, never say the change happened while it is pending, and read approval_status afterwards.',
        'http' => ['path' => '/mcp', 'allowed_hosts' => ['%env(key:host:url:APP_ORIGIN)%']],
        // The approval card polls approval_status while the agent calls tools: the lock keeps one session's requests
        // from overwriting what another saved.
        'session' => ['lock' => ['enabled' => true]],
        // Signs the state a URL-mode prompt's retry carries. It lives longer than a request, so a late retry still
        // reads the outcome.
        'request_state' => ['key' => '%env(MCP_REQUEST_STATE_KEY)%', 'ttl' => EditorialPolicy::LIFETIME_SECONDS + 300],
        'registry' => [
            'tools' => [ArticleTools::class, ApprovalStatusTool::class, ApprovalHandoffTool::class],
            'apps' => [ApprovalCardApp::class],
        ],
    ]]]);
};
