<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_land_on_their_flows_with_a_workspace()
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/workflows');
        $this->get('/workflows')->assertOk();
        $this->assertNotNull($user->fresh()->current_workspace_id);
    }
}
