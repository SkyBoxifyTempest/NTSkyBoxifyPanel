<?php

namespace Pterodactyl\Services\MinecraftPlugins;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Models\Server;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\DB;

class PolymartPluginService extends AbstractPluginService
{
    protected Client $client;
    const MAX_PAGE_SIZE = 50;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
            'base_uri' => 'https://api.polymart.org/v1/',
        ]);
    }

    public function search(array $filters): array
    {
        $query = $filters['searchQuery'];
        $pageSize = $filters['pageSize'];
        $page = $filters['page'];

        $link = DB::select('SELECT token FROM polymart_links WHERE user_id = ?', [auth()->user()->id])[0] ?? null;
        try {
            $response = json_decode($this->client->post('search', [
                'json' => [
                    'start' => ($page - 1) * $pageSize,
                    'limit' => $pageSize,
                    'premium' => $link ? null : '0', // Only show premium resources for people with active link
                    'token' => $link?->token,
                    'query' => $query,
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Polymart plugins.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $plugins = [];

        foreach ($response['response']['result'] as $polymartPlugin) {
            // Required because Polymart does not set CORS headers...
            $iconUrl = $polymartPlugin['thumbnailURL'];
            // $iconUrl = 'https://corsproxy.io/?' . urlencode($polymartPlugin['thumbnailURL']);

            $plugins[] = [
                'id' => $polymartPlugin['id'],
                'name' => $polymartPlugin['title'],
                'short_description' => $polymartPlugin['subtitle'],
                'url' => $polymartPlugin['url'],
                'icon_url' => $iconUrl,
                'external_url' => $polymartPlugin['canDownload'] ? null : $polymartPlugin['url'],
            ];
        }

        return [
            'data' => $plugins,
            'total' => $response['response']['total'],
        ];
    }

    public function versions(string $pluginId): array
    {
        $versions = [];

        try {
            $response = json_decode($this->client->post('getResourceUpdates', [
                'json' => [
                    'resource_id' => $pluginId,
                    'start' => 0,
                    'limit' => 50,
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Polymart plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            throw $e;
        }

        foreach ($response['response']['updates'] as $version) {
            $versionName = $version['version'];

            if ($version['title'] && $version['title'] != $versionName) {
                $versionName .= ' - ' . $version['title'];
            }

            $versions[] = [
                'id' => (string) $version['id'],
                'name' => $versionName,
            ];
        }

        return $versions;
    }

    public function getDownloadUrl(string $pluginId, string $versionId): string
    {
        // We ignore $version: Polymart currently has no way to generate
        // a download link for a specific version.
        $link = DB::select('SELECT token FROM polymart_links WHERE user_id = ?', [auth()->user()->id])[0] ?? null;
        try {
            $response = json_decode($this->client->post('getDownloadURL', [
                'json' => [
                    'resource_id' => $pluginId,
                    'token' => $link ? $link->token : null,
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching Polymart plugin files.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            throw $e;
        }

        if (!$response['response']['success']) {
            throw new \Exception('Couldn\'t get download URL for Polymart plugin.' . json_encode($response['response']));
        }
        $downloadUrl = $response['response']['result']['url'];
        return $downloadUrl;
    }

    public function getLinkRedirectURL(Server $server): string
    {
        $randomState = bin2hex(random_bytes(50));
        DB::insert('INSERT INTO polymart_links (user_id, random_state) VALUES (?, ?)', [auth()->user()->id, $randomState]);

        try {
            $response = json_decode($this->client->post('authorizeUser', [
                'json' => [
                    'service' => parse_url(config('app.url'), PHP_URL_HOST),
                    'return_url' => route('minecraft-plugins.link-back', ['server' => $server]),
                    'return_token' => false,
                    'state' => $randomState,
                ],
            ])->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when sending user authorization request to Polymart.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }
        }

        if (!$response['response']['success']) {
            throw new \Exception('Couldn\'t request user authorization from Polymart.');
        }
        $redirectUrl = $response['response']['result']['url'];

        return $redirectUrl;
    }

    public function handleBack(array $data): void
    {
        $link = DB::select('SELECT * FROM polymart_links WHERE random_state = ?', [$data['state']])[0] ?? null;

        if (!$link) {
            abort(404);
        }

        if ($data['success'] != '1') {
            return;
        }

        DB::update('UPDATE polymart_links SET token = ? WHERE id = ?', [$data['token'], $link->id]);
    }

    public function isLinked(): bool
    {
        $links = DB::select('SELECT * FROM polymart_links WHERE user_id = ? AND token IS NOT NULL', [auth()->user()->id]);
        return count($links) > 0;
    }

    public function disconnect(): void
    {
        $links = DB::select('SELECT * FROM polymart_links WHERE user_id = ?', [auth()->user()->id]);
        foreach ($links as $link) {
            try {
                $this->client->post('invalidateAuthToken', [
                    'json' => [
                        'token' => $link->token,
                    ],
                ]);
            } catch (TransferException $e) {
                if ($e instanceof BadResponseException) {
                    logger()->error('Received bad response when invalidating Polymart auth token.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
                }
            }
        }
        DB::delete('DELETE FROM polymart_links WHERE user_id = ?', [auth()->user()->id]);
    }
}
