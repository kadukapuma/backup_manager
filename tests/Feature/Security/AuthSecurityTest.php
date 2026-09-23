<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

test('successful login is audit logged with ip', function () {
    $user = userWithRole(Role::Viewer);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $log = AuditLog::query()->where('action', AuditAction::Login->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->ip)->toBe('127.0.0.1');
});

test('failed login is audit logged without the password', function () {
    $user = userWithRole(Role::Viewer);

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertGuest();
    $log = AuditLog::query()->where('action', AuditAction::LoginFailed->value)->firstOrFail();
    expect(json_encode($log->meta))->not->toContain('wrong-password');
});

test('users with 2fa are sent to the challenge instead of being logged in', function () {
    $user = userWithRole(Role::Admin);
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one-aaaa', 'code-two-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->get(route('two-factor.login'))->assertOk();

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'code-one-aaaa'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('a wrong 2fa code does not log in', function () {
    $user = userWithRole(Role::Admin);
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('verify')->andReturnFalse();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('the ip allowlist blocks other addresses', function () {
    config(['backup-manager.ip_allowlist' => ['10.0.0.0/8']]);
    actingAsRole(Role::Admin);

    $this->get('/dashboard')->assertForbidden();
    expect(AuditLog::query()->where('action', AuditAction::AccessDeniedByIp->value)->exists())->toBeTrue();
});

test('the ip allowlist allows listed addresses', function () {
    config(['backup-manager.ip_allowlist' => ['127.0.0.1']]);
    actingAsRole(Role::Admin);

    $this->get('/dashboard')->assertOk();
});

test('required 2fa redirects users without it to the setup page', function () {
    config(['backup-manager.require_two_factor' => true]);
    actingAsRole(Role::Admin);

    $this->get('/dashboard')->assertRedirect(route('two-factor.show'));
});

test('the 2fa settings page requires password confirmation', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.show'))
        ->assertOk();
});
