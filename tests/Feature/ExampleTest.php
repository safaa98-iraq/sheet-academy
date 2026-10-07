<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_student_and_admin_login_pages_render_without_registration(): void
    {
        $this->get('/login')->assertOk()->assertSee('رمز الوصول')->assertSee('name="token"', false);
        $this->get('/admin/login')->assertOk()->assertSee('البريد الإلكتروني')->assertSee('name="password"', false);
        $this->get('/register')->assertNotFound();
    }
}
