<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;

class LanguageSettings
{
    public function __construct(private Repository $config) {}

    /** @return list<string> */
    public function enabledCodes(): array
    {
        return array_keys(array_filter($this->languages(), fn (array $language): bool => $language['enabled']));
    }

    /** @return array<string, array{label: string, enabled: bool}> */
    private function languages(): array
    {
        return $this->config->get('languages.available', []);
    }
}
