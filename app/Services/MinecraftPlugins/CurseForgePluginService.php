<?php

namespace Pterodactyl\Services\MinecraftPlugins;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Models\Server;
use Ramsey\Uuid\Uuid;

enum CurseForgeSortField: int
{
    case Featured = 1;
    case Popularity = 2;
    case LastUpdated = 3;
    case Name = 4;
    case Author = 5;
    case TotalDownloads = 6;
    case Category = 7;
    case GameVersion = 8;
    case EarlyAccess = 9;
    case FeaturedReleased = 10;
    case ReleasedDate = 11;
    case Rating = 12;
};

class CurseForgePluginService extends AbstractPluginService
{
    public const CURSEFORGE_MINECRAFT_GAME_ID = 432;
    public const CURSEFORGE_MINECRAFT_PLUGINS_CLASS_ID = 5;

    protected Client $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
                'X-API-Key' => config('services.curseforge_api_key'),
            ],
            'base_uri' => 'https://api.curseforge.com/v1/',
        ]);
    }

    public function search(array $filters): array
    {
        $query = $filters['searchQuery'];
        $pageSize = $filters['pageSize'];
        $page = $filters['page'];
        $minecraftVersion = $filters['minecraftVersion'];

        try {
            $response = json_decode($this->client->get('mods/search', [
                'query' => [
                    'index' => ($page - 1) * $pageSize,
                    'pageSize' => $pageSize,
                    'gameId' => self::CURSEFORGE_MINECRAFT_GAME_ID,
                    'classId' => self::CURSEFORGE_MINECRAFT_PLUGINS_CLASS_ID,
                    'gameVersion' => $minecraftVersion,
                    'searchFilter' => $query,
                    'sortField' => CurseForgeSortField::Popularity->value,
                    'sortOrder' => 'desc',
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching CurseForge plugins.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $plugins = [];

        foreach ($response['data'] as $curseforgePlugin) {
            $plugins[] = [
                'id' => (string) $curseforgePlugin['id'],
                'name' => $curseforgePlugin['name'],
                'short_description' => $curseforgePlugin['summary'],
                'url' => $curseforgePlugin['links']['websiteUrl'],
                'icon_url' => isset($curseforgePlugin['logo']) ? $curseforgePlugin['logo']['thumbnailUrl'] : null,
            ];
        }

        // https://docs.curseforge.com/#search-mods
        // index + pageSize <= 10000
        $maximumPage = (10000 - $pageSize) / $pageSize + 1;

        return [
            'data' => $plugins,
            'total' => min($maximumPage * $pageSize, $response['pagination']['totalCount']),
        ];
    }

    public function versions(string $pluginId): array
    {
        try {
            $response = json_decode($this->client->get('mods/' . $pluginId . '/files')->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching CurseForge plugins versions.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [];
        }

        $versions = [];

        foreach ($response['data'] as $version) {
            $versions[] = [
                'id' => (string) $version['id'],
                'name' => $version['displayName'],
                'game_versions' => $version['gameVersions'],
                'download_url' => $version['downloadUrl'],
            ];
        }

        return $versions;
    }

    public function getDownloadUrl(string $pluginId, string $versionId): string
    {
        try {
            $response = json_decode($this->client->get('mods/' . $pluginId . '/files/' . $versionId)->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching CurseForge plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }
        }

        $file = $response['data'];
        $downloadUrl = str_replace("edge", "mediafiles", $file['downloadUrl']);

        return $downloadUrl;
    }
}
