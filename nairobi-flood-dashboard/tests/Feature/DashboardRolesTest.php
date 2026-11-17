<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Each role gets the right tools on the flood map
class DashboardRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_public_map_without_personal_or_response_tools(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="tab-overview"', false)
            ->assertDontSee('id="tab-places"', false)
            ->assertDontSee('id="tab-response"', false);
    }

    public function test_residents_get_places_but_not_response_tools(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))->get('/')->assertOk()
            ->assertSee('id="tab-places"', false)
            ->assertDontSee('id="tab-response"', false);
    }

    public function test_privacy_policy_is_public(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy')->assertSee('Data Protection Act');
        $this->get('/')->assertSee('/privacy', false);
    }

    public function test_responders_and_admins_get_response_tools(): void
    {
        foreach (['responder', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/')->assertOk()
                ->assertSee('id="tab-places"', false)
                ->assertSee('id="tab-response"', false);
        }
    }
}
