<?php

namespace Pterodactyl\Services\MinecraftPlugins;

use Pterodactyl\Models\Server;

abstract class AbstractPluginService
{
    protected string $userAgent;

    public function __construct()
    {
        $this->userAgent = config('app.name') . '/' . config('app.version') . ' (' . url('/') . ')';
    }

    abstract public function search(array $filters): array;

    abstract public function versions(string $pluginId): array;

    abstract public function getDownloadUrl(string $pluginId, string $versionId): string;
}
