<?php

namespace Pterodactyl\Services\MinecraftPlugins;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Models\Server;
use Ramsey\Uuid\Uuid;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;

class SpigotMCPluginService extends AbstractPluginService
{
    protected Client $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
            'base_uri' => 'https://api.spiget.org/v2/',
        ]);
    }

    public function search(array $filters): array
    {
        $query = $filters['searchQuery'];
        $pageSize = $filters['pageSize'];
        $page = $filters['page'];

        try {
            $response = json_decode($this->client->get(empty($query) ? 'resources/free' : ('search/resources/' . $query), [
                'query' => [
                    'size' => $pageSize,
                    'page' => $page,
                    'sort' => '-downloads', // descending downloads
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching SpigotMC plugins.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $plugins = [];

        foreach ($response as $spigotPlugin) {
            $iconUrl = empty($spigotPlugin['icon']['url']) ? null : ('https://spigotmc.org/' . $spigotPlugin['icon']['url']);

            if ($iconUrl === null && !empty($spigotPlugin['icon']['data'])) {
                $iconUrl = $spigotPlugin['icon']['data'];
            }
            if ($iconUrl === null) {
                $iconUrl = 'https://static.spigotmc.org/styles/spigot/xenresource/resource_icon.png';
            }

            // Required because SpigotMC does not set CORS headers...
            $iconUrl = 'https://corsproxy.io/?' . urlencode($iconUrl);

            $plugins[] = [
                'id' => (string) $spigotPlugin['id'],
                'name' => $spigotPlugin['name'],
                'short_description' => $spigotPlugin['tag'],
                'url' => 'https://www.spigotmc.org/resources/' . $spigotPlugin['id'],
                'icon_url' => $iconUrl,
                'external_url' => $this->getExternalUrl($spigotPlugin),
            ];
        }

        return [
            'data' => $plugins,
            'total' => count($plugins),
        ];
    }

    public function versions(string $pluginId): array
    {
        try {
            $versionResponse = json_decode($this->client->get('resources/' . $pluginId . '/versions/latest')->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching SpigotMC plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            throw $e;
        }

        return [
            [
                'id' => (string) $versionResponse['id'],
                'name' => $versionResponse['name'],
                // 'download_url' => $this->getDownloadUrl($pluginId),
            ]
        ];
    }

    /**
     * @return ?string external URL to which the user needs to go to download the plugin, if necessary
     */
    protected function getExternalUrl(array $plugin): ?string
    {
        if (!($plugin['external'] && (str_ends_with($plugin['file']['externalUrl'] ?? '', "html") || str_contains($plugin['file']['externalUrl'] ?? '', 'hangar')))) {
            return null;
        }
        if ($plugin['premium'] ?? false) {
            return null;
        }
        return 'https://www.spigotmc.org/' . $plugin['file']['url'];
    }

    public function getDownloadUrl(string $pluginId, string $versionId): string
    {
        // We ignore $versionId: Spigot only allows for download of latest version.
        try {
            $detailsResponse = json_decode($this->client->get('resources/' . $pluginId)->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching SpigotMC resource details.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            throw $e;
        }


        $downloadUrl = $detailsResponse['file']['externalUrl'] ?? ('https://api.spiget.org/v2/resources/' . $pluginId . '/download');

        $downloadUrl = $this->getRedirectUrl($downloadUrl) ?? $downloadUrl;

        return $downloadUrl;
    }

    protected function getRedirectUrl(string $url): ?string {
        stream_context_set_default(array(
            'http' => array(
                'method' => 'HEAD'
            )
        ));
        $headers = get_headers($url, 1);
        if ($headers !== false && isset($headers['Location'])) {
            return is_array($headers['Location']) ? array_pop($headers['Location']) : $headers['Location'];
        }

        return null;
    }
}
