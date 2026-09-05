<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Oversized submissions: PHP/Laravel reject bodies over post_max_size with
 * a clear 413 instead of silently dropping the body (which used to surface
 * as misleading "field is required" validation errors).
 *
 * Relies on the framework's built-in ValidatePostSize middleware; the only
 * project-side requirement is a post_max_size large enough for the
 * documented 5 MB-per-document contract (see C:\php\php.ini).
 */
class OversizedPostTest extends TestCase
{
    public function test_oversized_body_gets_clear_413(): void
    {
        $response = $this->call(
            'POST',
            '/api/rider/apply',
            [],
            [],
            [],
            // Far above any sane post_max_size, so this holds regardless
            // of the local php.ini value.
            ['CONTENT_LENGTH' => (string) (1024 * 1024 * 1024), 'HTTP_ACCEPT' => 'application/json']
        );

        $response->assertStatus(413);
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_normal_body_passes_through(): void
    {
        $response = $this->call(
            'POST',
            '/api/rider/apply',
            [],
            [],
            [],
            ['CONTENT_LENGTH' => '1024', 'HTTP_ACCEPT' => 'application/json']
        );

        // Falls through to validation (422), not the 413 guard.
        $response->assertStatus(422);
    }
}
