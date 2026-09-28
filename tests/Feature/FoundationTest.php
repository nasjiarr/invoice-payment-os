<?php

namespace Tests\Feature;

use Illuminate\Support\Number;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    /**
     * Test application returns a successful response on root.
     */
    public function test_application_root_is_accessible(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * Test application timezone is set to Asia/Jakarta.
     */
    public function test_application_timezone_is_configured_to_jakarta(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    /**
     * Test application locale supports Indonesian.
     */
    public function test_application_locale_is_configured_to_indonesian(): void
    {
        $this->assertSame('id', config('app.locale'));
        $this->assertSame('id_ID', config('app.faker_locale'));
    }

    /**
     * Test application currency defaults to IDR and formats appropriately.
     */
    public function test_application_currency_formatting_supports_idr(): void
    {
        $this->assertSame('IDR', config('app.currency'));

        $formatted = Number::currency(1000000);
        // Expecting Indonesian Rupiah formatted output containing Rp and digits
        $this->assertStringContainsString('Rp', $formatted);
        $this->assertStringContainsString('1.000.000', $formatted);
    }
}
