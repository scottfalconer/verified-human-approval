<?php

declare(strict_types=1);

/*
 * The independent consumer check. It installs core and WebAuthn ("webauthn"), core, WebAuthn and MCP ("mcp"),
 * core, WebAuthn and the Doctrine storage ("doctrine"), or core, WebAuthn and the testing package with a PSR-17
 * implementation ("testing"), into a separate Composer project in a temporary directory: from this checkout's
 * src/, through a path repository that copies the packages, at matching dev-main versions, with stable
 * defaults. It then checks them there with only that project's autoloader, and runs the installed evidence
 * command on the public synthetic fixture. "archives" instead checks what each package's archive would ship.
 *
 * A path install is not a published-package installation; it shows the packages work apart from this
 * monorepo. Usage: php tests/Package/check-consumer.php webauthn|mcp|doctrine|testing|archives [--keep]
 */

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['webauthn', 'mcp', 'doctrine', 'testing', 'archives'], true)) {
    fwrite(\STDERR, "Usage: php tests/Package/check-consumer.php webauthn|mcp|doctrine|testing|archives [--keep]\n");
    exit(2);
}
$keep = in_array('--keep', $argv, true);
$composer = getenv('COMPOSER_BIN') ?: 'composer';
$directory = sys_get_temp_dir().'/verified-human-approval-consumer-'.$mode.'-'.bin2hex(random_bytes(4));
mkdir($directory, 0700);

$fail = static function (string $message): never {
    fwrite(\STDERR, 'Consumer check failed: '.$message."\n");
    exit(1);
};
$run = static function (array $command, string $what) use ($fail): void {
    echo '> '.$what."\n";
    passthru(implode(' ', array_map('escapeshellarg', $command)), $exit);
    if (0 !== $exit) {
        $fail($what);
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

try {
    if ('archives' === $mode) {
        // What each package ships: its manifest, README, licence and runtime files; never vendor, tests or runtime state.
        $required = [
            'core' => ['composer.json', 'README.md', 'LICENSE', 'src/ApprovalService.php'],
            'webauthn' => ['composer.json', 'README.md', 'LICENSE', 'src/WebAuthnVerifier.php', 'bin/verify-evidence.php'],
            'mcp' => ['composer.json', 'README.md', 'LICENSE', 'src/ApprovalCard.php', 'resources/approval-card.html'],
            'symfony-bundle' => ['composer.json', 'README.md', 'LICENSE', 'src/VerifiedHumanApprovalBundle.php', 'config/routes.php', 'public/approval.js', 'templates/review.html.twig', 'translations/VerifiedHumanApproval.en.xlf', 'docs/index.md', 'docs/first-action.md', 'docs/authentication.md', 'docs/enrollment.md', 'docs/testing.md'],
            'doctrine' => ['composer.json', 'README.md', 'LICENSE', 'src/DbalTransaction.php', 'src/ApprovalSchema.php', 'src/Orm/EntityAction.php'],
            'store-contracts' => ['composer.json', 'README.md', 'LICENSE', 'src/ApprovalStoreContract.php', 'src/Fixture/FixtureSchema.php', 'bin/store-worker.php', 'bin/audit-worker.php', 'resources/audit-v1-chain.json'],
            'testing' => ['composer.json', 'README.md', 'LICENSE', 'src/VirtualAuthenticator.php', 'src/PasskeyPanel.php', 'src/PanelAnswer.php', 'src/KernelHttpClient.php'],
        ];
        foreach ($required as $package => $files) {
            $run([$composer, '--working-dir='.$root.'/src/'.$package, 'archive', '--format=tar', '--dir='.$directory, '--file='.$package, '--no-interaction'], 'Archive '.$package);
            $entries = [];
            foreach (new RecursiveIteratorIterator(new PharData($directory.'/'.$package.'.tar')) as $file) {
                // The path inside the archive, whatever the temporary directory resolves to.
                $entries[] = (string) preg_replace('#^phar://.*?/'.preg_quote($package, '#').'\.tar/#', '', (string) $file->getPathname());
            }
            foreach ($files as $file) {
                if (!in_array($file, $entries, true)) {
                    $fail($package.' does not ship '.$file);
                }
            }
            foreach ($entries as $entry) {
                if (preg_match('#(^|/)(vendor|tests|var|\.phpunit[^/]*)(/|$)#', $entry)) {
                    $fail($package.' ships '.$entry);
                }
            }
            echo 'ok   '.$package.' ships '.count($entries)." files, and no vendor, tests or runtime state\n";
        }
        echo "Archive check passed.\n";
        exit(0);
    }

    $packages = ['verified-human-approval/core', 'verified-human-approval/webauthn'];
    if ('mcp' === $mode || 'doctrine' === $mode || 'testing' === $mode) {
        $packages[] = 'verified-human-approval/'.$mode;
    }
    $requirements = array_fill_keys($packages, 'dev-main');
    if ('testing' === $mode) {
        // The kernel client takes any PSR-17 factories, so the application under test brings an implementation.
        // An application requires the testing package for development; here one install without development
        // packages serves every mode.
        $requirements['nyholm/psr7'] = '^1.8';
    }
    $manifest = [
        'name' => 'verified-human-approval/consumer-check',
        'description' => 'A separate application that installs the packages, for the consumer check.',
        'type' => 'project',
        'license' => 'MIT',
        // PHP alone: the extensions are the packages' own requirements, which check-platform-reqs checks below.
        'require' => ['php' => '^8.3'] + $requirements,
        'repositories' => [[
            'type' => 'path',
            'url' => $root.'/src/*',
            'options' => ['symlink' => false, 'versions' => array_fill_keys(['verified-human-approval/core', 'verified-human-approval/webauthn', 'verified-human-approval/mcp', 'verified-human-approval/symfony-bundle', 'verified-human-approval/doctrine', 'verified-human-approval/store-contracts', 'verified-human-approval/testing'], 'dev-main')],
        ]],
        'minimum-stability' => 'stable',
        'prefer-stable' => true,
        'config' => ['allow-plugins' => false, 'platform' => ['php' => '8.3.0']],
    ];
    file_put_contents($directory.'/composer.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");

    $run([$composer, '--working-dir='.$directory, 'update', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist', '--no-scripts', '--no-plugins'], 'Install into a separate project ('.implode(', ', $packages).')');
    $run([$composer, '--working-dir='.$directory, 'check-platform-reqs', '--no-dev'], 'Check that this PHP meets every installed requirement');
    $run([\PHP_BINARY, __DIR__.'/consumer-smoke.php', $directory, $mode], 'Smoke checks with the consumer’s own autoloader');

    // The installed command, through Composer's bin proxy, on public synthetic evidence.
    $fixture = $root.'/tests/fixtures/evidence/commit-v2';
    foreach ([
        'with the passkey record' => [[$fixture.'/evidence.json', $fixture.'/trusted-application.json', $fixture.'/trusted-credential.json'], true],
        'with the site key alone' => [[$fixture.'/evidence.json', $fixture.'/trusted-application.json'], null],
    ] as $how => [$files, $passkey]) {
        $output = [];
        exec(implode(' ', array_map('escapeshellarg', [\PHP_BINARY, $directory.'/vendor/bin/verify-evidence.php', ...$files])).' 2>&1', $output, $exit);
        $result = json_decode(implode("\n", $output), true);
        if (0 !== $exit || !is_array($result) || $passkey !== $result['credentialApprovedExactProposal']
            || true !== $result['applicationSignedExecutionReport'] || 'commit' !== $result['applicationReportSignedAt']) {
            $fail('the installed evidence command '.$how.': '.implode("\n", $output));
        }
        echo 'ok   the installed evidence command verifies the synthetic commit receipt '.$how."\n";
    }
    echo 'Consumer check passed ('.$mode.").\n";
} finally {
    if ($keep) {
        echo 'Kept '.$directory."\n";
    } else {
        $remove($directory);
    }
}
