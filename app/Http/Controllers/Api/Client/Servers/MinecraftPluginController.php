<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\Server;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Permission;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\MinecraftPlugins\CurseForgePluginService;
use Pterodactyl\Services\MinecraftPlugins\HangarPluginService;
use Pterodactyl\Services\MinecraftPlugins\ModrinthPluginService;
use Pterodactyl\Services\MinecraftPlugins\SpigotMCPluginService;
use Pterodactyl\Services\MinecraftPlugins\PolymartPluginService;
use Pterodactyl\Services\MinecraftPlugins\MinecraftPluginProvider;

class MinecraftPluginController extends ClientApiController
{
    /**
     * MinecraftPluginController constructor.
     */
    public function __construct(private DaemonFileRepository $daemonFileRepository)
    {
        parent::__construct();
    }

    /**
     * Returns potential worlds in the server root directory.
     */
    public function index(Request $request, Server $server): array
    {
        if (!$request->user()->can(Permission::ACTION_FILE_READ, $server)) {
            throw new AuthorizationException();
        }
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(MinecraftPluginProvider::class)],
            'page' => 'required|numeric|integer|min:1',
            'page_size' => 'required|numeric|integer|max:50', // CurseForge page size max is 50
            'search_query' => 'nullable|string',
            'minecraft_version' => 'nullable|string',
        ]);

        $provider = MinecraftPluginProvider::from($validated['provider']);
        $page = (int) $validated['page'];
        $pageSize = (int) $validated['page_size'];
        $searchQuery = $validated['search_query'] ?? '';
        $minecraftVersion = $validated['minecraft_version'] ?? '';

        $service = $this->getPluginService($provider);
        if ($provider === MinecraftPluginProvider::Hangar) {
            $pageSize = min($pageSize, $service::MAX_PAGE_SIZE);
        }

        $data = $service->search(compact('searchQuery', 'pageSize', 'page', 'minecraftVersion'));

        $plugins = $data['data'];

        return [
            'object' => 'list',
            'data' => $plugins,
            'meta' => [
                'pagination' => [
                    'total' => $data['total'],
                    'count' => count($plugins),
                    'per_page' => $pageSize,
                    'current_page' => $page,
                    'total_pages' => ceil($data['total'] / $pageSize),
                    'links' => [],
                ],
            ],
        ];
    }

    /**
     * Returns a plugin's installable versions.
     */
    public function versions(Request $request, Server $server): array
    {
        if (!$request->user()->can(Permission::ACTION_FILE_READ, $server)) {
            throw new AuthorizationException();
        }
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(MinecraftPluginProvider::class)],
            'pluginId' => 'required|string',
        ]);

        $provider = MinecraftPluginProvider::from($validated['provider']);
        $pluginId = $validated['pluginId'];

        $service = $this->getPluginService($provider);

        $data = $service->versions($pluginId);

        return $data;
    }


    protected function getPluginService(MinecraftPluginProvider $provider) {
        $class = match ($provider) {
            MinecraftPluginProvider::CurseForge => CurseForgePluginService::class,
            MinecraftPluginProvider::Hangar => HangarPluginService::class,
            MinecraftPluginProvider::Modrinth => ModrinthPluginService::class,
            MinecraftPluginProvider::SpigotMC => SpigotMCPluginService::class,
            MinecraftPluginProvider::Polymart => PolymartPluginService::class,
        };
        return app($class);
    }

    /**
     * Install a remote plugin.
     */
    public function installPlugin(Request $request, Server $server)
    {
        if (!$request->user()->can(Permission::ACTION_FILE_CREATE, $server)) {
            throw new AuthorizationException();
        }

        $validated = $request->validate([
            'provider' => ['required', Rule::enum(MinecraftPluginProvider::class)],
            'pluginId' => 'required|string',
            'versionId' => 'required|string',
        ]);
        $provider = MinecraftPluginProvider::from($validated['provider']);
        $pluginId = $validated['pluginId'];
        $versionId = $validated['versionId'];

        $service = $this->getPluginService($provider);
        $downloadUrl = $service->getDownloadUrl($pluginId, $versionId);

        try {
            $this->daemonFileRepository->setServer($server)->pull(
                $downloadUrl,
                '/plugins',
                ['use_header' => true, 'foreground' => true]
            );
        } catch (\Exception $e) {
            throw new DisplayException(
                'Looks like this we couldn\'t download this plugin automatically. ' .
                'You should still be able to download it in your browser at ' . $downloadUrl
            );
        }

        return response()->noContent();
    }

    public function linkPolymart(Server $server)
    {
        $service = $this->getPluginService(MinecraftPluginProvider::Polymart);
        $redirectUrl = $service->getLinkRedirectURL($server);
        return response()->json($redirectUrl);
    }

    public function handleBackPolymart(Request $request, Server $server)
    {
        $validated = $request->validate([
            'success' => 'required|string',
            'token' => 'required|string',
            'state' => 'required|string',
        ]);
        $service = $this->getPluginService(MinecraftPluginProvider::Polymart);
        $service->handleBack($validated);
        return redirect('/server/' . $server->uuidShort . '/minecraft-plugins?provider=polymart');
    }

    public function disconnectPolymart()
    {
        $service = $this->getPluginService(MinecraftPluginProvider::Polymart);
        $service->disconnect();
    }

    public function isLinked(): bool
    {
        $service = $this->getPluginService(MinecraftPluginProvider::Polymart);
        return $service->isLinked();
    }
}
