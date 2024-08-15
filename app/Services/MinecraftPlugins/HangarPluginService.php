<?php

namespace Pterodactyl\Services\MinecraftPlugins;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Models\Server;
use Ramsey\Uuid\Uuid;

class HangarPluginService extends AbstractPluginService
{
    protected Client $client;
    const MAX_PAGE_SIZE = 25;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
            'base_uri' => 'https://hangar.papermc.io/api/v1/',
        ]);
    }

    public function search(array $filters): array
    {
        $query = $filters['searchQuery'];
        $pageSize = $filters['pageSize'];
        $page = $filters['page'];

        try {
            $response = json_decode($this->client->get('projects', [
                'query' => [
                    'limit' => $pageSize,
                    'offset' => ($page - 1) * $pageSize,
                    'query' => empty($query) ? null : $query,
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Hangar plugins.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $plugins = [];

        foreach ($response['result'] as $hangarPlugin) {
            $plugins[] = [
                'id' => $hangarPlugin['name'],
                'name' => $hangarPlugin['name'],
                'short_description' => $hangarPlugin['description'],
                'url' => 'https://hangar.papermc.io/' . $hangarPlugin['namespace']['owner'] . '/' . $hangarPlugin['namespace']['slug'],
                'icon_url' => $hangarPlugin['avatarUrl'],
            ];
        }

        return [
            'data' => $plugins,
            'total' => $response['pagination']['count'],
        ];
    }

    public function versions(string $pluginId): array
    {
        try {
            $response = json_decode($this->client->get('projects/' . $pluginId . '/versions', [
                'query' => [
                    'limit' => 25,
                    'offset' => 0,
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Hangar plugins versions.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [];
        }

        $versions = [];

        foreach ($response['result'] as $version) {
            foreach ($version['downloads'] as $platform => $download) {
                $versions[] = [
                    'id' => $platform . '-' . $version['name'],
                    'name' => $version['name'] . ' (' . $platform . ')',
                    'download_url' => $download['downloadUrl'],
                ];
            }
        }

        return $versions;
    }

    public function getDownloadUrl(string $pluginId, string $versionId): string
    {
        $parts = explode('-', $versionId, 2);
        $platform = $parts[0];
        $versionId = $parts[1];

        try {
            $response = json_decode($this->client->get('projects/' . $pluginId . '/versions/' . $versionId)->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Hangar plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }
        }

        $file = $response['downloads'][$platform];
        $downloadUrl = $file['downloadUrl'] ?? $file['externalUrl'];
        $fileName = isset($file['fileInfo']) ? $file['fileInfo']['name'] : null;

        return $downloadUrl;
    }
}
