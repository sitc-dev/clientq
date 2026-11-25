const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');

const source = path.join(__dirname, '..', 'scripts', 'deploy-updates.sh');

function fixture(t) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'clientq-deploy-test-'));
    t.after(() => fs.rmSync(root, { recursive: true, force: true }));
    const repository = path.join(root, 'repository');
    const bin = path.join(root, 'bin');
    fs.mkdirSync(path.join(repository, 'scripts'), { recursive: true });
    fs.mkdirSync(bin);
    fs.copyFileSync(source, path.join(repository, 'scripts', 'deploy-updates.sh'));
    const init = spawnSync('git', ['init', '--quiet', repository], { encoding: 'utf8' });
    assert.equal(init.status, 0, init.stderr);
    fs.writeFileSync(path.join(bin, 'rsync'), `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const manifest = args.find(arg => arg.startsWith('--files-from=')).slice('--files-from='.length);
const files = fs.readFileSync(manifest, 'utf8').split('\\0').filter(Boolean);
fs.writeFileSync(process.env.DEPLOY_TEST_CAPTURE, JSON.stringify({ args, files, manifest }));
const unchanged = new Set(JSON.parse(process.env.DEPLOY_TEST_UNCHANGED || '[]'));
for (const name of files.filter(name => !unchanged.has(name))) {
    let escaped = '';
    for (const char of name) {
        const code = char.charCodeAt(0);
        escaped += code < 32 || code === 127 || char === '\\\\'
            ? '\\\\#' + code.toString(8).padStart(3, '0') : char;
    }
    console.log('CLIENTQ_TRANSFER:<f+++++++++:16:' + escaped);
}
process.exit(Number(process.env.DEPLOY_TEST_EXIT || 0));
`, { mode: 0o755 });
    const remoteLog = path.join(root, 'remote-deployment.log');
    fs.writeFileSync(path.join(bin, 'ssh'), `#!/usr/bin/env node
const fs = require('node:fs');
const record = fs.readFileSync(0, 'utf8');
const entry = JSON.parse(record.slice(record.indexOf('ClientQ deployment: ') + 'ClientQ deployment: '.length));
if (process.env.DEPLOY_TEST_SSH_EXIT) process.exit(Number(process.env.DEPLOY_TEST_SSH_EXIT));
if (entry.status === process.env.DEPLOY_TEST_SSH_FAIL_STATUS) process.exit(255);
fs.mkdirSync(require('node:path').dirname(process.env.DEPLOY_TEST_REMOTE_LOG), { recursive: true });
fs.appendFileSync(process.env.DEPLOY_TEST_REMOTE_LOG, record);
`, { mode: 0o755 });
    const capture = path.join(root, 'capture.json');
    function file(name, timestamp = '2026-11-18T17:00:00Z') {
        const target = path.join(repository, name);
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.writeFileSync(target, 'fixture content\n');
        fs.utimesSync(target, new Date(timestamp), new Date(timestamp));
        return target;
    }
    function run(extra = [], env = {}) {
        const dates = extra.includes('--date') ? [] : ['--start-date', '2026-11-18', '--end-date', '2026-11-18'];
        return spawnSync('bash', [
            path.join(repository, 'scripts', 'deploy-updates.sh'),
            ...dates, '--host', 'deploy@example.invalid',
            '--path', '/srv/clientq site', ...extra
        ], {
            encoding: 'utf8',
            cwd: root,
            env: {
                ...process.env, PATH: `${bin}${path.delimiter}${process.env.PATH}`,
                DEPLOY_TEST_CAPTURE: capture, DEPLOY_TEST_REMOTE_LOG: remoteLog, ...env
            }
        });
    }
    const logName = `clientq-${new Date().toISOString().slice(0, 10)}.log`;
    const localLog = path.join(repository, 'logs', logName);
    function entries(filename) {
        return fs.readFileSync(filename, 'utf8').trim().split('\n')
            .filter(line => line.includes('ClientQ deployment: '))
            .map(line => JSON.parse(line.slice(line.indexOf('ClientQ deployment: ') + 'ClientQ deployment: '.length)));
    }
    return { root, repository, bin, capture, file, run, localLog, remoteLog, entries, logName };
}

test('selects exactly one Eastern day, includes tracked and untracked code, and excludes local data', t => {
    const { repository, capture, file, run, localLog, remoteLog } = fixture(t);
    file('.gitignore');
    fs.writeFileSync(path.join(repository, '.gitignore'), 'ignored.php\n');
    fs.utimesSync(path.join(repository, '.gitignore'), new Date('2026-11-17T17:00:00Z'), new Date('2026-11-17T17:00:00Z'));
    for (const name of [
        'admin/layout.php', 'api/with space.php', 'api/with\nnewline.php',
        'database/tenant-any-staff-migration.sql', 'database/tenant-schema.sql',
        'tests/test.php', 'README.md', 'update-prompt.txt', '.env', '.env.production',
        'uploads/logo.png', 'logs/current.log', 'vendor/library.php', 'scripts/helper.sh',
        '.playwright-mcp/snapshot.yml', '.phpunit.cache/result', 'custq.sql', 'custq.sql.bak',
        'database/backup.sql.gz', 'ignored.php'
    ]) file(name);
    file('api/start.php', '2026-11-18T05:00:00Z');
    file('api/before.php', '2026-11-18T04:59:59Z');
    file('api/last.php', '2026-11-19T04:59:59Z');
    file('api/next-day.php', '2026-11-19T05:00:00Z');
    const add = spawnSync('git', ['-C', repository, 'add', 'admin/layout.php'], { encoding: 'utf8' });
    assert.equal(add.status, 0, add.stderr);
    const result = run();
    assert.equal(result.status, 0, result.stderr);
    const transferred = JSON.parse(fs.readFileSync(capture, 'utf8'));
    assert.deepEqual(transferred.files, [
        'admin/layout.php', 'api/last.php', 'api/start.php', 'api/with\nnewline.php',
        'api/with space.php', 'database/tenant-any-staff-migration.sql', 'database/tenant-schema.sql'
    ]);
    assert.ok(transferred.args.includes('--dry-run'));
    assert.ok(transferred.args.includes('--protect-args'));
    assert.equal(transferred.args.at(-1), 'deploy@example.invalid:/srv/clientq site/');
    assert.equal(fs.existsSync(transferred.manifest), false);
    assert.equal(fs.existsSync(localLog), false);
    assert.equal(fs.existsSync(remoteLog), false);
});

test('upload requires --apply and does not delete remote files or execute migrations', t => {
    const { capture, file, run, localLog, remoteLog, entries } = fixture(t);
    file('index.php');
    const result = run(['--apply']);
    assert.equal(result.status, 0, result.stderr);
    const transferred = JSON.parse(fs.readFileSync(capture, 'utf8'));
    assert.deepEqual(transferred.files, ['index.php']);
    assert.ok(!transferred.args.includes('--dry-run'));
    assert.ok(!transferred.args.some(arg => arg.startsWith('--delete')));
    assert.match(result.stdout, /no databases, tenants, or records are copied or migrated/);
    assert.deepEqual(entries(localLog).map(entry => entry.status), ['STARTED', 'SUCCESS']);
    assert.deepEqual(entries(remoteLog), entries(localLog));
    assert.deepEqual(entries(localLog)[1].uploaded_files, ['index.php']);
    assert.equal(entries(localLog)[1].rsync_exit_code, 0);
    assert.equal(entries(localLog)[0].run_id, entries(localLog)[1].run_id);
    assert.ok(fs.statSync(localLog).mode & 0o020, 'new daily logs must permit shared-group writes');
    assert.match(result.stdout, /Uploaded files:\n  index\.php\n/);
});

test('an empty day never contacts the remote server', t => {
    const { capture, file, run } = fixture(t);
    file('index.php', '2026-11-17T17:00:00Z');
    const result = run();
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /Nothing to deploy; no remote connection made/);
    assert.equal(fs.existsSync(capture), false);
});

test('invalid dates and unsafe remote destinations fail before transfer', t => {
    const { capture, file, run } = fixture(t);
    file('index.php');
    for (const args of [
        ['--date', '2026-02-30'], ['--date', '20261118'], ['--path', '/'],
        ['--path', '///'], ['--path', '/srv/../'], ['--path', 'relative/path'], ['--host', '-unsafe'],
        ['--host', 'host; command'], ['--end-date', '2026-11-17'],
        ['--start-date', '2026-02-30'], ['--end-date', '9999-12-31'],
        ['--date', '2026-11-18', '--start-date', '2026-11-18', '--end-date', '2026-11-19']
    ]) {
        const result = run(args);
        assert.notEqual(result.status, 0);
        assert.match(result.stderr, /Error:/);
        assert.equal(fs.existsSync(capture), false);
    }
});

test('symlinked files are rejected instead of uploading files outside the repository', t => {
    const { repository, capture, file, run } = fixture(t);
    file('outside.txt');
    fs.symlinkSync(path.join(repository, 'outside.txt'), path.join(repository, 'linked.php'));
    fs.lutimesSync(path.join(repository, 'linked.php'), new Date('2026-11-18T17:00:00Z'), new Date('2026-11-18T17:00:00Z'));
    const result = run();
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /refusing to deploy a symlinked path/);
    assert.equal(fs.existsSync(capture), false);
});

test('rsync failures are returned and temporary manifests are still removed', t => {
    const { capture, file, run, localLog, remoteLog, entries } = fixture(t);
    file('index.php');
    const result = run(['--apply'], { DEPLOY_TEST_EXIT: '23' });
    assert.equal(result.status, 23);
    const transferred = JSON.parse(fs.readFileSync(capture, 'utf8'));
    assert.equal(fs.existsSync(transferred.manifest), false);
    assert.doesNotMatch(result.stdout, /Upload complete/);
    assert.equal(entries(localLog).at(-1).status, 'FAILED');
    assert.equal(entries(localLog).at(-1).rsync_exit_code, 23);
    assert.deepEqual(entries(remoteLog), entries(localLog));
    assert.match(fs.readFileSync(localLog, 'utf8'), /\[ERROR\] \[scripts\/deploy-updates\.sh\] ClientQ deployment: /);
});

test('real rsync previews and transfers selected files while preserving remote configuration and data', t => {
    const { root, bin, file, run, entries, logName } = fixture(t);
    fs.unlinkSync(path.join(bin, 'rsync'));
    fs.writeFileSync(path.join(bin, 'ssh'), `#!/usr/bin/env node
const fs = require('node:fs');
const { spawnSync } = require('node:child_process');
const result = spawnSync('sh', ['-c', process.argv.at(-1)], {
    input: fs.readFileSync(0), stdio: ['pipe', 'inherit', 'inherit']
});
if (result.error) throw result.error;
process.exit(result.status === null ? 1 : result.status);
`, { mode: 0o755 });
    const transport = path.join(bin, 'local-ssh');
    fs.writeFileSync(transport, `#!/usr/bin/env node
const { spawnSync } = require('node:child_process');
const args = process.argv.slice(2);
const command = args.indexOf('rsync');
if (command < 0 || !args.includes('--server')) throw new Error('Expected only the local rsync test server.');
const result = spawnSync('rsync', args.slice(command + 1), { stdio: 'inherit' });
if (result.error) throw result.error;
process.exit(result.status === null ? 1 : result.status);
`, { mode: 0o755 });
    file('index.php');
    file('api/name with space.php');
    file('api/name\nwith newline.php');
    file('api/literal\\#123.php');
    file('README.md');
    file('.env');
    file('uploads/customer.txt');
    const destination = path.join(root, "remote site's $HOME");
    fs.mkdirSync(path.join(destination, 'uploads'), { recursive: true });
    fs.writeFileSync(path.join(destination, 'index.php'), 'remote content!\n');
    fs.utimesSync(path.join(destination, 'index.php'), new Date('2026-11-18T17:00:00Z'), new Date('2026-11-18T17:00:00Z'));
    fs.writeFileSync(path.join(destination, '.env'), 'remote configuration\n');
    fs.writeFileSync(path.join(destination, 'uploads', 'customer.txt'), 'remote customer data\n');
    fs.writeFileSync(path.join(destination, 'keep.php'), 'keep this remote file\n');
    const env = { RSYNC_RSH: transport };
    const preview = run(['--path', destination], env);
    assert.equal(preview.status, 0, preview.stderr);
    assert.equal(fs.readFileSync(path.join(destination, 'index.php'), 'utf8'), 'remote content!\n');
    assert.equal(fs.existsSync(path.join(destination, 'api')), false);
    const applied = run(['--path', destination, '--apply'], env);
    assert.equal(applied.status, 0, applied.stderr);
    assert.equal(fs.readFileSync(path.join(destination, 'index.php'), 'utf8'), 'fixture content\n');
    assert.equal(fs.readFileSync(path.join(destination, 'api', 'name with space.php'), 'utf8'), 'fixture content\n');
    assert.equal(fs.existsSync(path.join(destination, 'README.md')), false);
    assert.equal(fs.readFileSync(path.join(destination, '.env'), 'utf8'), 'remote configuration\n');
    assert.equal(fs.readFileSync(path.join(destination, 'uploads', 'customer.txt'), 'utf8'), 'remote customer data\n');
    assert.equal(fs.readFileSync(path.join(destination, 'keep.php'), 'utf8'), 'keep this remote file\n');
    const remoteEntries = entries(path.join(destination, 'logs', logName));
    assert.ok(fs.statSync(path.join(destination, 'logs', logName)).mode & 0o020);
    assert.equal(remoteEntries.at(-1).status, 'SUCCESS');
    assert.deepEqual(remoteEntries.at(-1).uploaded_files, [
        'api/literal\\#123.php', 'api/name\nwith newline.php', 'api/name with space.php', 'index.php'
    ]);
    assert.match(applied.stdout, /Uploaded files:\n  /);
    const repeated = run(['--path', destination, '--apply'], env);
    assert.equal(repeated.status, 0, repeated.stderr);
    assert.match(repeated.stdout, /\(none; selected files were already up to date\)/);
});

test('date ranges include both Eastern days and exclude the following midnight', t => {
    const { capture, file, run } = fixture(t);
    file('first.php', '2026-11-18T05:00:00Z');
    file('before.php', '2026-11-18T04:59:59Z');
    file('middle.php', '2026-11-19T05:00:00Z');
    file('last.php', '2026-11-20T04:59:59Z');
    file('after.php', '2026-11-20T05:00:00Z');
    const result = run(['--end-date', '2026-11-19']);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(JSON.parse(fs.readFileSync(capture, 'utf8')).files, ['first.php', 'last.php', 'middle.php']);
});

test('date ranges honor the 25-hour Eastern daylight-saving transition', t => {
    const { capture, file, run } = fixture(t);
    file('first.php', '2026-11-01T04:00:00Z');
    file('before.php', '2026-11-01T03:59:59Z');
    file('last.php', '2026-11-02T04:59:59Z');
    file('after.php', '2026-11-02T05:00:00Z');
    const result = run(['--start-date', '2026-11-01', '--end-date', '2026-11-01']);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(JSON.parse(fs.readFileSync(capture, 'utf8')).files, ['first.php', 'last.php']);
});

test('single-date compatibility remains available and unchanged files are not claimed as uploaded', t => {
    const { file, run, localLog, entries } = fixture(t);
    file('changed.php');
    file('unchanged.php');
    const result = run(['--date', '2026-11-18', '--apply'], { DEPLOY_TEST_UNCHANGED: '["unchanged.php"]' });
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(entries(localLog).at(-1).uploaded_files, ['changed.php']);
    assert.match(result.stdout, /Uploaded files:\n  changed\.php\n/);
    assert.doesNotMatch(result.stdout.split('Uploaded files:\n')[1], /unchanged\.php/);
});

test('remote logging preflight failure prevents upload and is recorded locally', t => {
    const { file, run, capture, localLog, remoteLog, entries } = fixture(t);
    file('index.php');
    const result = run(['--apply'], { DEPLOY_TEST_SSH_EXIT: '255' });
    assert.equal(result.status, 255);
    assert.equal(fs.existsSync(capture), false);
    assert.equal(fs.existsSync(remoteLog), false);
    assert.equal(entries(localLog).at(-1).status, 'FAILED');
    assert.equal(entries(localLog).at(-1).rsync_exit_code, null);
    assert.match(result.stderr, /remote failure log could not be written/);
    assert.match(result.stderr, /Nothing was uploaded/);
});

test('completion-log failure returns nonzero but reports the successful transfer truthfully', t => {
    const { file, run, localLog, remoteLog, entries } = fixture(t);
    file('index.php');
    const result = run(['--apply'], { DEPLOY_TEST_SSH_FAIL_STATUS: 'SUCCESS' });
    assert.equal(result.status, 1);
    assert.equal(entries(localLog).at(-1).status, 'SUCCESS');
    assert.equal(entries(localLog).at(-1).rsync_exit_code, 0);
    assert.match(entries(localLog).at(-1).error, /Remote completion log/);
    assert.deepEqual(entries(remoteLog).map(entry => entry.status), ['STARTED']);
    assert.match(result.stdout, /Uploaded files:\n  index\.php\n/);
    assert.match(result.stderr, /Upload succeeded, but deployment logging failed/);
});

test('an unwritable local log prevents remote logging and upload', t => {
    const { repository, file, run, capture, remoteLog } = fixture(t);
    file('index.php');
    fs.writeFileSync(path.join(repository, 'logs'), 'not a directory');
    const result = run(['--apply']);
    assert.equal(result.status, 1);
    assert.equal(fs.existsSync(capture), false);
    assert.equal(fs.existsSync(remoteLog), false);
    assert.match(result.stderr, /Cannot write local deployment log/);
    assert.match(result.stderr, /Nothing was uploaded/);
});

test('deployment records append to daily app logs without replacing existing entries', t => {
    const { repository, file, run, localLog, remoteLog, entries } = fixture(t);
    file('index.php');
    fs.mkdirSync(path.dirname(localLog), { recursive: true });
    const appEntry = '[2026-11-21 07:00:00] [INFO] [api/example.php] Existing application entry\n';
    fs.writeFileSync(localLog, appEntry);
    fs.writeFileSync(remoteLog, appEntry);
    const result = run(['--apply']);
    assert.equal(result.status, 0, result.stderr);
    const localText = fs.readFileSync(localLog, 'utf8');
    assert.ok(localText.startsWith(appEntry));
    assert.equal(fs.readFileSync(remoteLog, 'utf8'), localText);
    assert.match(localText, /\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[INFO\] \[scripts\/deploy-updates\.sh\] ClientQ deployment: /);
    assert.deepEqual(entries(localLog).map(entry => entry.status), ['STARTED', 'SUCCESS']);
    assert.equal(fs.existsSync(path.join(repository, 'logs', 'deploy-updates.log')), false);
});
