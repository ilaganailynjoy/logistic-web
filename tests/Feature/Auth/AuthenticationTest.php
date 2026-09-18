<?php

namespace Tests\Feature\Auth;

use App\Models\Rider;
use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /**
     * "Active Riders" on the login page counts activated riders only
     * (status != inactive). Deactivated riders are excluded even though the
     * dashboard's separate total-records metric includes them.
     */
    public function test_login_page_active_riders_excludes_inactive_riders(): void
    {
        $before = Rider::where('status', '!=', 'inactive')->count();

        Rider::create([
            'name' => 'Active Rider ' . uniqid(),
            'email' => 'login-active-' . uniqid() . '@test.com',
            'phone' => '09000000001',
            'vehicle_type' => 'motorcycle',
            'status' => 'available',
        ]);
        Rider::create([
            'name' => 'Inactive Rider ' . uniqid(),
            'email' => 'login-inactive-' . uniqid() . '@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'motorcycle',
            'status' => 'inactive',
        ]);

        $expected = $before + 1;

        $this->get('/login')
            ->assertOk()
            ->assertSee('Active Riders')
            ->assertSee('<p class="mt-3 text-xl sm:text-2xl font-bold">' . $expected . '</p>', false);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
            'status' => 'active',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
