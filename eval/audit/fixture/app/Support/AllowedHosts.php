<?php

namespace App\Support;

class AllowedHosts
{
    /** @var list<string> */
    private array $hosts = [];

    /** @param list<string> $configured */
    public function __construct(array $configured)
    {
        foreach ($configured as $host) {
            $this->hosts[] = strtolower(trim($host));
        }
    }

    public function allows(string $host): bool
    {
        return in_array(strtolower($host), $this->hosts, true);
    }

    public function isEmpty(): bool
    {
        return $this->hosts === [];
    }
}
