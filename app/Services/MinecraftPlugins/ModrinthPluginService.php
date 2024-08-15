<?php

namespace Pterodactyl\Services\MinecraftPlugins;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Models\Server;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\Cache;

class ModrinthPluginService extends AbstractPluginService
{
    protected Client $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
            'base_uri' => 'https://api.modrinth.com/v2/',
        ]);
    }

    public function search(array $filters): array
    {
        $query = $filters['searchQuery'];
        $pageSize = $filters['pageSize'];
        $page = $filters['page'];
        $minecraftVersion = $filters['minecraftVersion'];
        $facets = '["project_type:plugin"],["server_side!=unsupported"]';

        if (!empty($minecraftVersion)) {
            $facets .= ',["versions:' . $minecraftVersion . '"]';
        }

        try {
            $response = json_decode($this->client->get('search', [
                'query' => [
                    'offset' => ($page - 1) * $pageSize,
                    'facets' => '[ ' . $facets . ' ]',
                    'limit' => $pageSize,
                    'query' => $query,
                    'index' => 'relevance',
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Modrinth plugins.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $plugins = [];

        foreach ($response['hits'] as $modrinthPlugin) {
            $plugins[] = [
                'id' => $modrinthPlugin['project_id'],
                'name' => $modrinthPlugin['title'],
                'short_description' => $modrinthPlugin['description'],
                'url' => 'https://modrinth.com/plugin/' . $modrinthPlugin['slug'],
                'icon_url' => empty($modrinthPlugin['icon_url']) ? null : $modrinthPlugin['icon_url'],
            ];
        }

        return [
            'data' => $plugins,
            'total' => $response['total_hits'],
        ];
    }

    public function versions(string $pluginId): array
    {
        // Quoted and comma delimited list of loader names as a string.
        $loaders = implode(',', array_map(fn($s): string => '"' . $s . '"', $this->getPluginLoaders()));

        try {
            $response = json_decode($this->client->get('project/' . $pluginId . '/version', [
                'query' => [
                    'loaders' => '[' . $loaders  . ']'
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Modrinth plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }
        }

        $versions = [];

        foreach ($response as $version) {
            $versions[] = [
                'id' => $version['id'],
                'name' => $version['name'],
                'game_versions' => $version['game_versions'],
                'download_url' => $version['files'][0]['url'],
            ];
        }

        return $versions;
    }


    public function getDownloadUrl(string $pluginId, string $versionId): string
    {
        try {
            $response = json_decode($this->client->get('project/' . $pluginId . '/version/' . $versionId)->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Modrinth plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }
        }

        $file = $response['files'][0];
        $downloadUrl = $file['url'];

        return $downloadUrl;
    }

    public function getPluginLoaders(): array
    {

        return Cache::remember('modrinth-plugin-loaders', 3600 * 24, function() {
            $response = json_decode($this->client->get('tag/loader')->getBody(), true);
            $pluginLoaders = [];

            foreach ($response as $loader) {
                if (in_array('plugin', $loader['supported_project_types'])) {
                    $pluginLoaders[] = $loader['name'];
                }
            }
            return $pluginLoaders;
        });
    }
}
