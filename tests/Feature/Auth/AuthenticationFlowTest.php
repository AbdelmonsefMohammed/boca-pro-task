<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('a user registered through the app can log in with the password they chose', function (): void {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'chosen-password',
        'password_confirmation' => 'chosen-password',
    ]);
    $this->post(route('logout'));

    $response = $this->post(route('login.store'), [
        'email' => 'test@example.com',
        'password' => 'chosen-password',
    ]);

    $this->assertAuthenticatedAs(User::where('email', 'test@example.com')->sole());
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('logging out revokes access to the dashboard on the next request', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->post(route('logout'));

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
