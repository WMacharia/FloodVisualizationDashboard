<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Roles (resident, responder, admin), on-screen alerts and saved places.
 * Runs on the in-memory SQLite database from phpunit.xml; the model server and Open-Meteo are faked.
 */
class AlertsAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private function fakeServices(bool $modelUp = true, array $rain = [0.0, 0.2, 0.0], $health = null): void
    {
        $times = [];
        foreach (array_keys($rain) as $k) $times[] = now('Africa/Nairobi')->startOfHour()->addHours($k)->format('Y-m-d\TH:i');
        Http::fake([
            '*/health' => $health ?? ($modelUp ? $this->healthy() : Http::response('down', 500)),
            '*/subcounties' => Http::response(['type' => 'FeatureCollection', 'features' => [
                ['properties' => ['name' => 'Mathare']], ['properties' => ['name' => 'Kasarani']],
            ]]),
            '*/point*' => Http::response(['subcounty' => 'Mathare']),
            'api.open-meteo.com/*' => Http::response(['hourly' => ['time' => $times, 'precipitation' => $rain]]),
        ]);
    }

    private function healthy()
    {
        return Http::response(['status' => 'ok', 'model' => ['test_iou' => 0.741], 'dv_available' => true]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function advisoryPayload(array $over = []): array
    {
        return $over + ['level' => 'severe', 'title' => 'Flooding likely in Mathare', 'body' => 'Avoid river banks.', 'areas' => ['Mathare'], 'hours' => 6];
    }

    public function test_guests_and_residents_cannot_issue_advisories(): void
    {
        $this->fakeServices();
        $this->postJson('/api/advisories', $this->advisoryPayload())->assertStatus(401);
        $this->actingAs($this->user('user'))->postJson('/api/advisories', $this->advisoryPayload())->assertStatus(403);
        $this->assertSame(0, Alert::count());
    }

    public function test_responder_advisory_is_public_audited_and_reaches_saved_places(): void
    {
        $this->fakeServices();
        $resident = $this->user('user');
        SavedPlace::create(['user_id' => $resident->id, 'name' => 'Home', 'lat' => -1.26, 'lng' => 36.85, 'subcounty' => 'Mathare']);
        $responder = $this->user('responder');

        $this->actingAs($responder)->postJson('/api/advisories', $this->advisoryPayload())
            ->assertCreated()->assertJson(['residents_notified' => 1]);

        // Guests see the advisory; only the resident sees the personal alert
        auth()->logout();
        $this->getJson('/api/alerts')->assertOk()->assertJsonCount(1, 'alerts')->assertJsonPath('alerts.0.kind', 'advisory');
        $this->actingAs($resident)->getJson('/api/alerts')->assertJsonCount(2, 'alerts');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $responder->id, 'action' => 'advisory.issued']);
    }

    public function test_advisory_areas_must_be_real_subcounties(): void
    {
        $this->fakeServices();
        $this->actingAs($this->user('responder'))
            ->postJson('/api/advisories', $this->advisoryPayload(['areas' => ['Atlantis']]))
            ->assertStatus(422);
    }

    public function test_only_the_issuer_or_an_admin_can_withdraw(): void
    {
        $this->fakeServices();
        $issuer = $this->user('responder');
        $id = $this->actingAs($issuer)->postJson('/api/advisories', $this->advisoryPayload())->json('id');

        $this->actingAs($this->user('responder'))->postJson("/api/advisories/{$id}/withdraw")->assertStatus(403);
        $this->actingAs($this->user('admin'))->postJson("/api/advisories/{$id}/withdraw")->assertOk();
        $this->assertNotNull(Alert::find($id)->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'advisory.withdrawn']);
    }

    public function test_model_server_outage_alerts_admins_only_and_clears_itself(): void
    {
        // First health check fails, the next one succeeds
        $this->fakeServices(health: Http::sequence()->push('down', 500)->push(['status' => 'ok', 'model' => [], 'dv_available' => true]));
        $admin = $this->user('admin');
        $this->actingAs($admin)->getJson('/api/alerts')->assertJsonPath('alerts.0.title', 'Flood model server is down');
        $this->actingAs($this->user('responder'))->getJson('/api/alerts')->assertJsonCount(0, 'alerts');

        // Next check (after the 60 s throttle) finds the server back up and resolves the alert
        cache()->forget('monitor:lock');
        $this->actingAs($admin)->getJson('/api/alerts')->assertJsonCount(0, 'alerts');
    }

    public function test_heavy_rain_forecast_alerts_staff_with_a_matching_scenario(): void
    {
        $this->fakeServices(rain: [0, 4, 12, 18, 14, 2]);
        $res = $this->actingAs($this->user('responder'))->getJson('/api/alerts')->assertOk();
        $res->assertJsonPath('alerts.0.kind', 'weather')
            ->assertJsonPath('alerts.0.level', 'warning')
            ->assertJsonPath('alerts.0.action.intensity', 15)   // mean of the 3 hours at or above 10 mm/h
            ->assertJsonPath('alerts.0.action.duration', 3);

        auth()->logout();
        $this->getJson('/api/alerts')->assertJsonCount(0, 'alerts');   // not shown to the public
    }

    public function test_acknowledging_a_staff_alert_is_audited(): void
    {
        $this->fakeServices(modelUp: false);
        $admin = $this->user('admin');
        $id = $this->actingAs($admin)->getJson('/api/alerts')->json('alerts.0.id');
        $this->actingAs($admin)->postJson("/api/alerts/{$id}/ack")->assertOk();
        $this->actingAs($admin)->getJson('/api/alerts')->assertJsonPath('alerts.0.read', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.acknowledged', 'user_id' => $admin->id]);
    }

    public function test_saved_places_are_private_and_limited(): void
    {
        $this->fakeServices();
        $a = $this->user('user');
        $id = $this->actingAs($a)->postJson('/api/places', ['name' => 'Home', 'lat' => -1.26, 'lng' => 36.85])
            ->assertCreated()->assertJsonPath('subcounty', 'Mathare')->json('id');

        $this->actingAs($this->user('user'))->deleteJson("/api/places/{$id}")->assertNotFound();
        for ($k = 0; $k < SavedPlace::MAX_PER_USER - 1; $k++) {
            $this->actingAs($a)->postJson('/api/places', ['name' => "P{$k}", 'lat' => -1.26, 'lng' => 36.85])->assertCreated();
        }
        $this->actingAs($a)->postJson('/api/places', ['name' => 'One too many', 'lat' => -1.26, 'lng' => 36.85])->assertStatus(422);
    }

    public function test_admin_manages_roles_but_cannot_demote_admins(): void
    {
        $this->fakeServices();
        $admin = $this->user('admin');
        $resident = $this->user('user');

        $this->actingAs($admin)->patch("/admin/users/{$resident->id}/role", ['role' => 'responder'])->assertRedirect();
        $this->assertSame('responder', $resident->fresh()->role);
        $this->assertSame(1, AuditLog::where('action', 'role.changed')->count());

        $other = $this->user('admin');
        $this->actingAs($admin)->patch("/admin/users/{$other->id}/role", ['role' => 'user']);
        $this->assertSame('admin', $other->fresh()->role);

        // Making someone an admin is not possible from the console
        $this->actingAs($admin)->patch("/admin/users/{$resident->id}/role", ['role' => 'admin'])->assertSessionHasErrors('role');
    }

    public function test_non_admins_cannot_open_the_console(): void
    {
        $this->fakeServices();
        $this->actingAs($this->user('responder'))->get('/admin/dashboard')->assertRedirect(route('admin.login'));
        $this->actingAs($this->user('admin'))->get('/admin/dashboard')->assertOk()->assertSee('System health');
    }
}
