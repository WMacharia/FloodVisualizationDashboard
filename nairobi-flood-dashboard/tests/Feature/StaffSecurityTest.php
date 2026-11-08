<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireRecentSignIn;
use App\Http\Middleware\StaffIdleTimeout;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Staff sign-in security: Google-only sign-in, admin allowlist and CLI, last-admin guard,
 * rate limiting, sign-in audit, 30-minute staff idle timeout and re-confirmation ("sudo mode").
 * Google is mocked; nothing leaves the machine.
 */
class StaffSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.redirect' => rtrim(config('app.url'), '/') . '/auth/google/callback',
            'auth.admin_emails' => ['owner@example.com'],
        ]);
        Http::fake(['*' => Http::response(['status' => 'ok'])]);   // model server / weather checks
    }

    private function googleReturns(string $email, string $id = 'g-1', bool $verified = true): void
    {
        $user = (new GoogleUser)
            ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $id, 'name' => 'Test Person', 'email' => $email, 'avatar' => null]);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_allowlisted_google_account_becomes_admin_and_sign_in_is_audited(): void
    {
        $this->googleReturns('Owner@Example.com');
        $this->get('/auth/google/callback?state=x&code=y')->assertRedirect('/');

        $user = User::where('email', 'owner@example.com')->first();
        $this->assertSame('admin', $user->role);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.granted', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.signin', 'user_id' => $user->id]);
    }

    public function test_other_google_accounts_become_residents(): void
    {
        $this->googleReturns('someone@example.com');
        $this->get('/auth/google/callback?state=x&code=y')->assertRedirect('/');
        $this->assertSame('user', User::where('email', 'someone@example.com')->value('role'));
        $this->assertSame(0, AuditLog::where('action', 'auth.signin')->count());   // only staff sign-ins are logged
    }

    public function test_unverified_google_email_is_refused(): void
    {
        $this->googleReturns('owner@example.com', verified: false);
        $this->get('/auth/google/callback?state=x&code=y')->assertRedirect('/')->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed']);
    }

    public function test_email_linked_to_another_google_account_is_refused(): void
    {
        User::factory()->create(['email' => 'resp@example.com', 'google_id' => 'g-original', 'role' => 'responder']);
        $this->googleReturns('resp@example.com', 'g-impostor');
        $this->get('/auth/google/callback?state=x&code=y')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_forged_or_expired_sign_in_link_is_refused(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback?state=forged&code=y')->assertRedirect('/')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_password_login_is_retired(): void
    {
        $this->post('/admin', ['email' => 'admin@floodviz.com', 'password' => 'anything'])->assertStatus(405);
    }

    public function test_there_is_one_sign_in_for_everyone(): void
    {
        // Guests opening the console are sent to Google, then brought back to the console
        $this->get('/admin/dashboard')->assertRedirect(route('login.google'));
        $this->assertSame(url('/admin/dashboard'), session('url.intended'));

        $this->googleReturns('owner@example.com');
        $this->withSession(['url.intended' => url('/admin/dashboard')])
            ->get('/auth/google/callback?state=x&code=y')->assertRedirect(url('/admin/dashboard'));
    }

    public function test_non_admins_are_sent_back_to_the_map(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'responder']))
            ->get('/admin')->assertRedirect('/')->assertSessionHas('error');
        $this->actingAs(User::factory()->create(['role' => 'responder']))
            ->get('/admin/dashboard')->assertRedirect('/')->assertSessionHas('error');
    }

    public function test_sign_in_is_rate_limited(): void
    {
        // Using the map must not use up the sign-in allowance (separate counters)
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/alerts')->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->get('/auth/google')->assertRedirect();
        }
        $this->get('/auth/google')->assertStatus(429);
    }

    public function test_idle_staff_are_signed_out_but_residents_are_not(): void
    {
        $responder = User::factory()->create(['role' => 'responder']);
        $this->actingAs($responder)->withSession([StaffIdleTimeout::KEY => time() - 31 * 60])
            ->getJson('/api/alerts')->assertStatus(401)->assertJson(['signed_out' => true]);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.timeout', 'user_id' => $responder->id]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession([StaffIdleTimeout::KEY => time() - 31 * 60])
            ->get('/admin/dashboard')->assertRedirect('/')->assertSessionHas('status');

        $resident = User::factory()->create(['role' => 'user']);
        $this->actingAs($resident)->withSession([StaffIdleTimeout::KEY => time() - 5 * 3600])
            ->getJson('/api/alerts')->assertOk();
    }

    public function test_background_polling_does_not_keep_a_staff_session_alive(): void
    {
        $responder = User::factory()->create(['role' => 'responder']);
        $twentyAgo = time() - 20 * 60;

        $this->actingAs($responder)->withSession([StaffIdleTimeout::KEY => $twentyAgo])->getJson('/api/alerts')->assertOk();
        $this->assertSame($twentyAgo, session(StaffIdleTimeout::KEY));

        $this->actingAs($responder)->withSession([StaffIdleTimeout::KEY => $twentyAgo])->postJson('/auth/activity')->assertNoContent();
        $this->assertGreaterThan($twentyAgo, session(StaffIdleTimeout::KEY));
    }

    public function test_role_changes_need_a_recent_google_sign_in(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create(['role' => 'user']);

        // Never confirmed, or confirmed 20 minutes ago (limit 15): blocked
        foreach ([[], [RequireRecentSignIn::KEY => time() - 20 * 60]] as $session) {
            $this->actingAs($admin)->withSession($session)->from('/admin/dashboard')
                ->patch("/admin/users/{$resident->id}/role", ['role' => 'responder'])
                ->assertRedirect('/admin/dashboard')->assertSessionHas('reconfirm_needed');
            $this->assertSame('user', $resident->fresh()->role);
        }
        $this->actingAs($admin)->withSession([])->delete("/admin/users/{$resident->id}")->assertSessionHas('reconfirm_needed');
        $this->assertNotNull($resident->fresh());

        // Confirmed 2 minutes ago: allowed
        $this->actingAs($admin)->withSession([RequireRecentSignIn::KEY => time() - 120])
            ->patch("/admin/users/{$resident->id}/role", ['role' => 'responder']);
        $this->assertSame('responder', $resident->fresh()->role);
    }

    public function test_reconfirm_sends_staff_back_to_google_for_the_same_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'owner@example.com', 'google_id' => 'g-owner']);
        $res = $this->actingAs($admin)->get('/auth/reconfirm?return=/admin/dashboard');
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $url);
        $this->assertStringContainsString('prompt=select_account', $url);
        $this->assertStringContainsString('login_hint=owner%40example.com', $url);

        // Unsafe return addresses are ignored (no open redirect)
        $this->actingAs($admin)->get('/auth/reconfirm?return=//evil.example');
        $this->assertSame('/admin/dashboard', session('auth.reconfirm')['return']);
    }

    private function pendingReconfirm(User $admin): array
    {
        return ['auth.reconfirm' => ['user_id' => $admin->id, 'at' => time(), 'return' => '/admin/dashboard']];
    }

    public function test_reconfirm_with_a_different_google_account_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'owner@example.com', 'google_id' => 'g-owner']);
        $this->googleReturns('other@example.com', 'g-other');
        $this->actingAs($admin)->withSession($this->pendingReconfirm($admin))->get('/auth/google/callback?state=x&code=y')
            ->assertRedirect('/admin/dashboard')->assertSessionHas('error');
        $this->assertNull(session(RequireRecentSignIn::KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.reconfirm_failed']);
        $this->assertAuthenticatedAs($admin);   // still the same account, nothing switched
    }

    public function test_reconfirm_with_the_same_google_account_unlocks_sensitive_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'owner@example.com', 'google_id' => 'g-owner']);
        $this->googleReturns('owner@example.com', 'g-owner');
        $this->actingAs($admin)->withSession($this->pendingReconfirm($admin))->get('/auth/google/callback?state=x&code=y')
            ->assertRedirect('/admin/dashboard')->assertSessionHas('status');
        $this->assertNotNull(session(RequireRecentSignIn::KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.reconfirmed', 'user_id' => $admin->id]);
    }

    public function test_admins_are_granted_and_revoked_from_the_command_line_only(): void
    {
        $this->artisan('admin:grant', ['email' => 'new.admin@example.com'])->assertSuccessful();
        $this->assertSame('admin', User::where('email', 'new.admin@example.com')->value('role'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.granted']);

        // The only admin cannot be revoked...
        $this->artisan('admin:revoke', ['email' => 'new.admin@example.com'])->assertFailed();
        $this->assertSame('admin', User::where('email', 'new.admin@example.com')->value('role'));

        // ...but can be once there is another one
        $this->artisan('admin:grant', ['email' => 'second@example.com'])->assertSuccessful();
        $this->artisan('admin:revoke', ['email' => 'new.admin@example.com'])->assertSuccessful();
        $this->assertSame('user', User::where('email', 'new.admin@example.com')->value('role'));
        $this->artisan('admin:list')->assertSuccessful();
    }

    public function test_the_last_admin_can_never_be_removed_by_any_route(): void
    {
        $only = User::factory()->create(['role' => 'admin']);
        $this->expectException(\DomainException::class);
        $only->delete();
    }
}
