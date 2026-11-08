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
import base64
import urllib.request

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

CARD = 'ui://exact-approval/approval-card.html'
APP_MIME = 'text/html;profile=mcp-app'

def modern(arguments, client='claude-code', extra=None, tool='exact_approval_publish', user='editor'):
    """A raw MCP 2026-07-28 tools/call shaped like Claude Code's, over real HTTP."""
    params = {'name': tool, 'arguments': arguments, '_meta': {
        'io.modelcontextprotocol/protocolVersion': '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities': {'elicitation': {'url': {}}},
        'io.modelcontextprotocol/clientInfo': {'name': client, 'version': '2.1.317'},
    }, **(extra or {})}
    password = DEMO['editor_password' if user == 'editor' else 'admin_password']
    request = urllib.request.Request(ORIGIN + '/mcp', data=json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': params}).encode(), method='POST', headers={
        'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream',
        'Authorization': 'Basic ' + base64.b64encode(f'{user}:{password}'.encode()).decode(),
        'Mcp-Method': 'tools/call', 'Mcp-Name': tool, 'MCP-Protocol-Version': '2026-07-28',
    })
    with urllib.request.urlopen(request, timeout=25) as response:
        body = response.read().decode()
    for line in body.splitlines():
        if line.startswith('data: '):
            body = line[6:]
            break
    message = json.loads(body)
    assert 'result' in message, message
    return message['result']

def answer(asked, action):
    return {'inputResponses': {'exact_approval': {'action': action}}, 'requestState': asked['requestState']}

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
    with sqlite3.connect(SITE / 'var/drupal.sqlite') as db:
        executions_before = db.execute('SELECT COUNT(*) FROM exact_approval_executions').fetchone()[0]
    tools = {tool['name']: tool for tool in mcp('list')['tools']}
    for tool in ('exact_approval_publish', 'exact_approval_delete'):
        assert tools[tool]['_meta']['ui']['resourceUri'] == CARD, tools[tool]
    assert 'ui' not in (tools['exact_approval_status'].get('_meta') or {})
    resources = {resource['uri']: resource for resource in mcp('resources')['resources']}
    assert resources[CARD]['mimeType'] == APP_MIME, resources
    card = mcp('read', {'uri': CARD})['contents'][0]
    assert card['mimeType'] == APP_MIME and 'ui/open-link' in card['text'] and 'navigator.credentials' not in card['text']
    checks.append('Publish and delete link the MCP App approval card, which the endpoint serves and which never runs a passkey')

    second = node_id  # a keyless request for the same node reuses its pending proposal
    asked = modern({'node_id': second})
    assert asked.get('resultType') == 'input_required', asked
    ask = asked['inputRequests']['exact_approval']
    assert ask['method'] == 'elicitation/create' and ask['params']['mode'] == 'url', ask
    assert ask['params']['url'].startswith(DEMO['origin'] + '/exact-approval/'), ask
    accepted = modern({'node_id': second}, extra=answer(asked, 'accept'))
    assert not accepted.get('isError'), accepted
    assert accepted['structuredContent']['status'] == 'pending', accepted
    assert accepted['structuredContent']['reviewUrl'] == ask['params']['url'], accepted
    assert 'not an approval' in accepted['structuredContent']['message'], accepted
    assert accepted['structuredContent']['approval']['statusTool'] == 'exact_approval_status'
    checks.append('Claude Code gets a URL-mode elicitation on 2026-07-28; its accept is reported as not an approval, and the retry keeps the same proposal')
    declined = modern({'node_id': second}, extra=answer(asked, 'decline'))
    assert declined['structuredContent']['status'] == 'pending' and 'nothing was approved or changed' in declined['structuredContent']['message'], declined
    changed = modern({'node_id': second, 'idempotency_key': 'changed-' + os.urandom(4).hex()}, extra=answer(asked, 'accept'))
    assert changed.get('isError') and changed['structuredContent']['reason'] == 'continuation_mismatch', changed
    checks.append('A declined prompt leaves the request open, and a retry with changed arguments cannot continue it')
    other = modern({'node_id': second}, client='another-client')
    assert other.get('resultType') != 'input_required' and other['structuredContent']['status'] == 'pending', other
    checks.append('A client the site has not listed gets the card and link only')
    with sqlite3.connect(SITE / 'var/drupal.sqlite') as db:
        assert db.execute('SELECT COUNT(*) FROM exact_approval_executions').fetchone()[0] == executions_before, 'An MCP prompt or its answer executed an action'
    report = {'passed': True, 'checks': checks, 'transport': 'real HTTP, official mcp/sdk client and Drupal mcp_server SDK server', 'authentication': 'per-user Basic authentication on isolated test-only route', 'live_model_calls': 0, 'browser_or_physical_device_tested': False}
    (SITE / 'var/mcp-verification.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    drush('pm:uninstall', 'exact_approval_mcp_test', '--yes')

