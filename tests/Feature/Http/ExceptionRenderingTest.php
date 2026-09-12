<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ExceptionRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_web_exceptions_render_the_inertia_error_page(): void
    {
        config(['app.debug' => false]);

        Route::get('/testing/generic-error', fn () => throw new RuntimeException('test failure'));

        $response = $this->get('/testing/generic-error');

        $response->assertStatus(500)
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('status', 500));
    }

    public function test_a_rejected_form_returns_its_field_errors_instead_of_a_500(): void
    {
        // With app.debug on — as it is in the test environment by default —
        // the catch-all renderer bows out, so this only ever reproduced the
        // way production runs.
        config(['app.debug' => false]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login')
            ->assertSessionHasErrors();
    }
}
