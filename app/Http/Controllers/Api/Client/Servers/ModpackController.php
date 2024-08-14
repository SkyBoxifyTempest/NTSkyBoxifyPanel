<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Permission;
use Pterodactyl\Jobs\Server\InstallModpackJob;
use Illuminate\Auth\Access\AuthorizationException;
use Pterodactyl\Services\Modpacks\TechnicModpackService;
use Pterodactyl\Services\Modpacks\ModrinthModpackService;
use Pterodactyl\Services\Modpacks\CurseForgeModpackService;
use Pterodactyl\Services\Modpacks\VoidsWrathModpackService;
use Pterodactyl\Services\Modpacks\FeedTheBeastModpackService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Egg;

enum ModpackProvider: string
{
    case CurseForge = 'curseforge';
    case FeedTheBeast = 'feedthebeast';
    case Modrinth = 'modrinth';
    case Technic = 'technic';
    case VoidsWrath = 'voidswrath';
}

class ModpackController extends ClientApiController
{
    /**
     * ModpackController constructor.
     */
    public function __construct(
        protected CurseForgeModpackService $curseForgeModpackService,
        protected FeedTheBeastModpackService $feedTheBeastModpackService,
        protected ModrinthModpackService $modrinthModpackService,
        protected TechnicModpackService $technicModpackService,
        protected VoidsWrathModpackService $voidsWrathModpackService
    ) {
        parent::__construct();
    }

    /**
     * List modpacks for a specific provider.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(ModpackProvider::class)],
            'page' => 'required|numeric|integer|min:1',
            'page_size' => 'required|numeric|integer|max:50', // CurseForge page size max is 50
            'search_query' => 'nullable|string',
        ]);

        $provider = ModpackProvider::from($validated['provider']);
        $page = (int) $validated['page'];
        $pageSize = (int) $validated['page_size'];
        $searchQuery = $validated['search_query'] ?? '';

        $data = match ($provider) {
            ModpackProvider::CurseForge => $this->curseForgeModpackService->search($searchQuery, $pageSize, $page),
            ModpackProvider::FeedTheBeast => $this->feedTheBeastModpackService->search($searchQuery, $pageSize, $page),
            ModpackProvider::Modrinth => $this->modrinthModpackService->search($searchQuery, $pageSize, $page),
            ModpackProvider::Technic => $this->technicModpackService->search($searchQuery, $pageSize, $page),
            ModpackProvider::VoidsWrath => $this->voidsWrathModpackService->search($searchQuery, $pageSize, $page),
        };

        $modpacks = $data['data'];

        return [
            'object' => 'list',
            'data' => $modpacks,
            'meta' => [
                'pagination' => [
                    'total' => $data['total'],
                    'count' => count($modpacks),
                    'per_page' => $pageSize,
                    'current_page' => $page,
                    'total_pages' => ceil($data['total'] / $pageSize),
                    'links' => [],
                ],
            ],
        ];
    }

    /**
     * List modpack versions of a specific modpack.
     */
    public function versions(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(ModpackProvider::class)],
            'modpack_id' => 'required|string|min:1',
        ]);

        $provider = ModpackProvider::from($validated['provider']);
        $modpackId = $validated['modpack_id'];

        $versions = match ($provider) {
            ModpackProvider::CurseForge => $this->curseForgeModpackService->versions($modpackId),
            ModpackProvider::FeedTheBeast => $this->feedTheBeastModpackService->versions($modpackId),
            ModpackProvider::Modrinth => $this->modrinthModpackService->versions($modpackId),
            ModpackProvider::Technic => $this->technicModpackService->versions($modpackId),
            ModpackProvider::VoidsWrath => $this->voidsWrathModpackService->versions($modpackId),
        };

        return $versions;
    }

    /**
     * Start modpack installation procedure.
     */
    public function install(
        Request $request,
        Server $server
    ) {
        if (!$request->user()->can(Permission::ACTION_FILE_CREATE, $server)) {
            throw new AuthorizationException();
        }

        $installerEgg = Egg::where('author', 'modpack-installer@ric-rac.org')->firstOrFail();
        if ($server->egg_id === $installerEgg->id) {
            return response()->json([
                'message' => 'Already processing a modpack installation job.'
            ], 409);
        }

        $validated = $request->validate([
            'provider' => ['required', Rule::enum(ModpackProvider::class)],
            'modpack_id' => 'required|string',
            'modpack_version_id' => 'required|string',
            'delete_server_files' => 'required|boolean',
        ]);

        $provider = ModpackProvider::from($validated['provider']);
        $modpackId = $validated['modpack_id'];
        $modpackVersionId = $validated['modpack_version_id'];
        $deleteServerFiles = (bool) $validated['delete_server_files'];

        InstallModpackJob::dispatch($server, $provider->value, $modpackId, $modpackVersionId, $deleteServerFiles);

        Activity::event('server:modpack.install')
            ->property('provider', $provider->value)
            ->property('modpack_id', $modpackId)
            ->property('modpack_version_id', $modpackVersionId)
            ->log();

        return response()->noContent();
    }
}
