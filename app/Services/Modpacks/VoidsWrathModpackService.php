<?php

namespace Pterodactyl\Services\Modpacks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\BadResponseException;

class VoidsWrathModpackService extends AbstractModpackService
{
    protected Client $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
        ]);
    }

    /**
     * Search for modpacks on the provider.
     */
    public function search(string $searchQuery, int $pageSize, int $page): array
    {
        try {
            $response = json_decode($this->client->get('https://raw.githubusercontent.com/astrooom/minecraft-modpack-index/main/voidswrath-modpacks.json')
                ->getBody(), true);
        } catch (TransferException $e) {
            if ($e instanceof BadResponseException) {
                logger()->error('Received bad response when fetching modpacks.', ['response' => \GuzzleHttp\Psr7\Message::toString($e->getResponse())]);
            }

            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $modpacks = [];

        foreach ($response as $voidswrathModpack) {
            $modpacks[] = [
                'id' => (string) $voidswrathModpack['id'],
                'name' => $voidswrathModpack['displayName'],
                'description' => $voidswrathModpack['description'],
                'url' => $voidswrathModpack['platformUrl'],
                'icon_url' => $voidswrathModpack['logo'],
            ];
        }

        return [
            'data' => $modpacks,
            'total' => count($modpacks),
        ];
    }

    /**
     * Get the versions of a specific modpack for the provider.
     */
    public function versions(string $modpackId): array
    {
        // We only have the latest for Voidswrath
        return [
            [
                'id' => 'latest',
                'name' => 'Latest',
            ],
        ];
    }
}
