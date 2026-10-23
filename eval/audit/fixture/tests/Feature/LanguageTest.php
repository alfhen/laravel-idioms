<?php

namespace Tests\Feature;

use Tests\TestCase;

class LanguageTest extends TestCase
{
    public function test_lists_enabled_language_codes(): void
    {
        $this->getJson('/api/languages')->assertExactJson(['enabled' => ['da', 'de', 'en']]);
    }
}
