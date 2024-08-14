<?php

namespace Pterodactyl\Services\Modpacks;

abstract class AbstractModpackService
{
    protected string $userAgent;

    public function __construct()
    {
        $this->userAgent = config('app.name') . '/' . config('app.version') . ' (' . url('/') . ')';
    }

    /**
     * Search for modpacks on the provider.
     */
    abstract public function search(string $searchQuery, int $pageSize, int $page): array;

    /**
     * Get the versions of a specific modpack for the provider.
     */
    abstract public function versions(string $modpackId): array;
}
