#!/usr/bin/env python3
"""Real official PHP MCP SDK client/server over HTTP. No live agent or biometric device."""
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import time

ROOT = Path(__file__).resolve().parents[4]
SITE = ROOT / 'examples/drupal'
TESTS = Path(__file__).resolve().parent
PHP = os.environ.get('PHP_BIN') or shutil.which('php')
if not PHP:
    raise SystemExit('Set PHP_BIN to PHP 8.3+ with the demo extensions.')
DEMO = json.loads((SITE / 'var/demo.json').read_text())
PORT = 8091
ORIGIN = f'http://localhost:{PORT}'

def drush(*args):
    r = subprocess.run([PHP, str(SITE / 'vendor/drush/drush/drush.php'), '--root=' + str(SITE / 'web'), '--uri=' + ORIGIN, *args], cwd=SITE, capture_output=True, text=True, timeout=60)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout

def mcp(method, arguments=None, user='editor'):
    payload = {'endpoint': ORIGIN + '/mcp', 'user': user, 'password': DEMO['editor_password' if user == 'editor' else 'admin_password'], 'method': method, 'arguments': arguments or {}}
    r = subprocess.run([PHP, str(TESTS / 'mcp-client.php')], input=json.dumps(payload), capture_output=True, text=True, timeout=25)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return json.loads(r.stdout)

checks = []
drush('en', 'exact_approval_mcp_test', '--yes')
fixture = json.loads(drush('php:script', str(TESTS / 'prepare-mcp.php')))
node_id = fixture['node_id']
log = (SITE / 'var/mcp-http-server.log').open('w')
server = subprocess.Popen([PHP, '-S', f'localhost:{PORT}', '.ht.router.php'], cwd=SITE / 'web', stdout=log, stderr=log)
try:
    for _ in range(60):
        try:
            with socket.create_connection(('localhost', PORT), timeout=.2):
                break
        except OSError:
            time.sleep(.1)
    else:
        raise RuntimeError('Local Drupal server did not start.')
    names = {tool['name'] for tool in mcp('list')['tools']}
    assert {'exact_approval_publish', 'exact_approval_delete', 'exact_approval_status', 'fixture_direct_publish', 'fixture_direct_delete'} <= names
    checks.append('Official SDK discovers three approval tools on the existing Drupal MCP endpoint')
    for tool in ('fixture_direct_publish', 'fixture_direct_delete'):
        result = mcp(tool, {'node_id': node_id})
        assert result.get('isError') is True, result
        assert result['structuredContent']['reason'] == 'approval_required', result
        checks.append(tool + ' rejected by native storage guard')
    key = 'mcp-' + os.urandom(8).hex()
    prepared = mcp('exact_approval_publish', {'node_id': node_id, 'idempotency_key': key})
    assert not prepared['isError'], prepared
    pending = prepared['structuredContent']
    assert pending['status'] == 'pending' and pending['reviewUrl'].startswith(DEMO['origin'] + '/exact-approval/')
    request_id = pending['requestId']
    checks.append('Personal authenticated MCP request returns an owner-bound browser review URL')
    repeated = mcp('exact_approval_publish', {'node_id': node_id, 'idempotency_key': key})
    assert repeated['structuredContent']['requestId'] == request_id
    checks.append('Retried request returns the same proposal')
    assert mcp('exact_approval_status', {'request_id': request_id})['structuredContent']['status'] == 'pending'
    other = mcp('exact_approval_status', {'request_id': request_id}, user='admin')
    assert other['isError'] and other['structuredContent']['reason'] == 'not_found'
    checks.append('Another user, including administrator, cannot read the caller-bound MCP request')
    deletion = mcp('exact_approval_delete', {'node_id': node_id})
    assert deletion['structuredContent']['status'] == 'pending'
    with sqlite3.connect(SITE / 'var/drupal.sqlite') as db:
        assert db.execute('SELECT status FROM node_field_data WHERE nid=?', [node_id]).fetchone()[0] == 0
        assert db.execute('SELECT COUNT(*) FROM exact_approval_executions WHERE request_id=?', [request_id]).fetchone()[0] == 0
    checks.append('Neither approval requests nor ordinary tool bypass attempts publish or delete content without human proof')
    report = {'passed': True, 'checks': checks, 'transport': 'real HTTP, official mcp/sdk client and Drupal mcp_server SDK server', 'authentication': 'per-user Basic authentication on isolated test-only route', 'live_model_calls': 0, 'browser_or_physical_device_tested': False}
    (SITE / 'var/mcp-verification.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    drush('pm:uninstall', 'exact_approval_mcp_test', '--yes')

