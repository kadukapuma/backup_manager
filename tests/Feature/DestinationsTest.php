<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TestStatus;
use App\Jobs\TestDestinationJob;
use App\Models\AuditLog;
use App\Models\BackupCopy;
use App\Models\Destination;
use App\Services\Storage\RcloneClient;
use App\Services\Storage\RcloneEnvBuilder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function commandArgs(PendingProcess $process): array
{
    return is_array($process->command) ? $process->command : [$process->command];
}

test('sftp env uses obscured password and never puts secrets on the command line', function () {
    Process::fake(['*obscure*' => Process::result('OBSCURED-VALUE')]);
    $dest = Destination::factory()->sftp()->create();

    $env = app(RcloneEnvBuilder::class)->build($dest);

    expect($env)->toMatchArray([
        'RCLONE_CONFIG' => '/notfound',
        'RCLONE_CONFIG_BMDEST_TYPE' => 'sftp',
        'RCLONE_CONFIG_BMDEST_HOST' => 'backup.example.com',
        'RCLONE_CONFIG_BMDEST_USER' => 'bk',
        'RCLONE_CONFIG_BMDEST_PASS' => 'OBSCURED-VALUE',
    ]);

    Process::assertRan(fn (PendingProcess $p) => in_array('obscure', commandArgs($p), true)
        && $p->input === 'sftp-secret'
        && ! in_array('sftp-secret', commandArgs($p), true));
});

test('s3 env contains credentials and the bucket goes in the path', function () {
    $dest = Destination::factory()->s3()->create();

    $env = app(RcloneEnvBuilder::class)->build($dest);

    expect($env['RCLONE_CONFIG_BMDEST_TYPE'])->toBe('s3')
        ->and($env['RCLONE_CONFIG_BMDEST_SECRET_ACCESS_KEY'])->toBe('s3-secret')
        ->and($env['RCLONE_CONFIG_BMDEST_ENV_AUTH'])->toBe('false')
        ->and(app(RcloneClient::class)->spec($dest, 'db/x/file.age'))->toBe('BMDEST:kreethya-backups/db/x/file.age');
});

test('destination paths keep absolute base paths', function () {
    $local = Destination::factory()->make(['base_path' => '/var/backups/db']);
    $sftpAbs = Destination::factory()->sftp()->make(['base_path' => '/home/bk/backups']);
    $sftpRel = Destination::factory()->sftp()->make(['base_path' => 'backups']);

    expect($local->pathFor('vps/shop/f.age'))->toBe('/var/backups/db/vps/shop/f.age')
        ->and($sftpAbs->pathFor('/vps/f.age'))->toBe('/home/bk/backups/vps/f.age')
        ->and($sftpRel->pathFor('vps/f.age'))->toBe('backups/vps/f.age');
});

test('test job writes, reads back and deletes a file', function () {
    config(['backup-manager.tmp_path' => sys_get_temp_dir().'/bm-test-'.uniqid()]);
    $written = '';
    Process::fake(function (PendingProcess $process) use (&$written) {
        $args = commandArgs($process);
        if (in_array('copyto', $args, true)) {
            $written = (string) file_get_contents($args[2]);

            return Process::result('');
        }
        if (in_array('lsjson', $args, true)) {
            return Process::result(json_encode(['Path' => 'x', 'Size' => strlen($written), 'Hashes' => []]));
        }
        if (in_array('cat', $args, true)) {
            return Process::result($written);
        }
        if (in_array('about', $args, true)) {
            return Process::result(json_encode(['free' => 5_000_000_000, 'used' => 10]));
        }

        return Process::result('');
    });
    $dest = Destination::factory()->create(['base_path' => '/var/backups/db']);

    (new TestDestinationJob($dest->id))->handle(app(RcloneClient::class));

    $dest->refresh();
    expect($dest->last_test_status)->toBe(TestStatus::Ok)
        ->and($dest->free_space_bytes)->toBe(5_000_000_000);
    Process::assertRan(fn (PendingProcess $p) => in_array('deletefile', commandArgs($p), true));
});

test('test job records a redacted failure', function () {
    Process::fake([
        '*obscure*' => Process::result('OBS'),
        '*' => Process::result('', 'Failed to copy: auth failed for password sftp-secret', 1),
    ]);
    $dest = Destination::factory()->sftp()->create();

    (new TestDestinationJob($dest->id))->handle(app(RcloneClient::class));

    $dest->refresh();
    expect($dest->last_test_status)->toBe(TestStatus::Failed)
        ->and($dest->last_test_message)->toContain('auth failed')
        ->and($dest->last_test_message)->not->toContain('sftp-secret');
});

test('admin creates an s3 destination; secrets are masked and a test is queued', function () {
    Queue::fake();
    actingAsRole(Role::Admin);

    $this->post('/destinations', [
        'name' => 'Wasabi',
        'type' => 's3',
        'base_path' => 'db',
        'is_active' => true,
        'config' => [
            'provider' => 'Wasabi', 'region' => 'ap-southeast-1', 'endpoint' => 'https://s3.ap-southeast-1.wasabisys.com',
            'bucket' => 'kreethya-db', 'access_key_id' => 'AKIA123', 'secret_access_key' => 'Very-Secret-Key',
        ],
    ])->assertSessionHasNoErrors();

    Queue::assertPushed(TestDestinationJob::class);
    $response = $this->get('/destinations');
    expect($response->getContent())->not->toContain('Very-Secret-Key');
    $response->assertInertia(fn ($page) => $page->where('destinations.0.config.secret_access_key_is_set', true));

    $log = AuditLog::query()->where('action', AuditAction::DestinationCreated->value)->firstOrFail();
    expect(json_encode($log->meta))->not->toContain('Very-Secret-Key');
});

test('blank secrets on update keep the stored ones', function () {
    Queue::fake();
    $dest = Destination::factory()->sftp()->create(['name' => 'offsite']);
    actingAsRole(Role::Admin);

    $this->put("/destinations/{$dest->id}", [
        'name' => 'offsite', 'base_path' => 'backups2', 'is_active' => true,
        'config' => ['host' => 'backup.example.com', 'port' => 2222, 'user' => 'bk', 'password' => ''],
    ])->assertSessionHasNoErrors();

    $dest->refresh();
    expect($dest->config['password'])->toBe('sftp-secret')
        ->and($dest->config['port'])->toBe(2222)
        ->and($dest->base_path)->toBe('backups2');
});

test('sftp needs a password or key and local needs an absolute path', function () {
    actingAsRole(Role::Admin);

    $this->post('/destinations', ['name' => 'a', 'type' => 'sftp', 'is_active' => true, 'config' => ['host' => 'h', 'port' => 22, 'user' => 'u']])
        ->assertSessionHasErrors('config.password');
    $this->post('/destinations', ['name' => 'b', 'type' => 'local', 'base_path' => 'relative/path', 'is_active' => true])
        ->assertSessionHasErrors('base_path');
    $this->post('/destinations', ['name' => 'c', 'type' => 'local', 'base_path' => '/var/../etc', 'is_active' => true])
        ->assertSessionHasErrors('base_path');
});

test('destinations with live copies cannot be deleted', function () {
    $copy = BackupCopy::factory()->create();
    actingAsRole(Role::Admin);

    $this->delete("/destinations/{$copy->destination_id}")->assertSessionHas('error');
    expect(Destination::query()->count())->toBe(1);
});

test('non admins cannot manage destinations', function (Role $role) {
    $dest = Destination::factory()->create();
    actingAsRole($role);

    $this->get('/destinations')->assertOk();
    $this->post("/destinations/{$dest->id}/test")->assertForbidden();
    $this->delete("/destinations/{$dest->id}")->assertForbidden();
})->with([Role::Operator, Role::Viewer]);
