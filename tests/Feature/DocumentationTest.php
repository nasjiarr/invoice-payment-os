<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_docs_page_is_accessible(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('SwaggerUIBundle');
        $response->assertSee('/openapi.yaml');
    }

    public function test_api_documentation_alias_is_accessible(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertStatus(200);
        $response->assertSee('SwaggerUIBundle');
    }

    public function test_openapi_yaml_spec_exists_in_public_directory(): void
    {
        $this->assertFileExists(public_path('openapi.yaml'));
        $content = file_get_contents(public_path('openapi.yaml'));
        $this->assertStringContainsString('openapi: 3.1.0', $content);
        $this->assertStringContainsString('Invoice & Payment OS REST API', $content);
    }
}
