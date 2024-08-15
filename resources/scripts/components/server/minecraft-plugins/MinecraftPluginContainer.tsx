import http from '@/api/http';
import { getPaginationSet, PaginatedResult } from '@/api/http';
import ContentBox from '@/components/elements/ContentBox';
import GreyRowBox from '@/components/elements/GreyRowBox';
import Input from '@/components/elements/Input';
import Pagination from '@/components/elements/Pagination';
import Label from '@/components/elements/Label';
import Select from '@/components/elements/Select';
import { Button } from '@/components/elements/button/index';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import { faDownload } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import React, { useEffect, useState } from 'react';
import { useLocation } from 'react-router';
import useSWR from 'swr';
import tw from 'twin.macro';
import MinecraftPluginRow from '@/components/server/minecraft-plugins/MinecraftPluginRow';

export type MinecraftPluginProvider = 'curseforge' | 'modrinth' | 'hangar' | 'spigotmc' | 'craftaro' | 'polymart';

export interface MinecraftPlugin {
    id: string;
    name: string;
    short_description: string;
    url: string;
    icon_url: string | null;
    external_url: string | null;
}

type MinecraftPluginResponse = PaginatedResult<MinecraftPlugin>;

export default () => {
    const { search } = useLocation();
    const params = new URLSearchParams(search);
    const defaultProvider = (params.get('provider') as MinecraftPluginProvider) || 'modrinth';
    const defaultQuery = params.get('query') || '';
    const defaultPageSize = params.get('pageSize') || 50;
    const defaultMinecraftVersion = params.get('minecraftVersion') || '';
    const defaultPage = Number(params.get('page') || 1);

    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const shortUuid = ServerContext.useStoreState((state) => state.server.data!.id);
    const [pluginProvider, setPluginProvider] = useState<MinecraftPluginProvider>(defaultProvider);
    const [searchQuery, setSearchQuery] = useState<string>(defaultQuery);
    const [pageSize, setPageSize] = useState(defaultPageSize);
    const [minecraftVersion, setMinecraftVersion] = useState(defaultMinecraftVersion);
    const [minecraftVersions, setMinecraftVersions] = useState<string[]>([]);
    const [page, setPage] = useState(!isNaN(defaultPage) && defaultPage > 0 ? defaultPage : 1);

    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    const { data: isLinkedToPolymart, mutate } = useSWR<boolean>(
        `minecraft-plugins-is-linked-to-polymart`,
        async () => {
            const { data } = await http.get(`/api/client/servers/${uuid}/minecraft-plugins/is-linked`);
            return data;
        }
    );

    const {
        data: plugins,
        error,
        isValidating,
    } = useSWR<MinecraftPluginResponse>(
        ['minecraft-plugins', pluginProvider, searchQuery, pageSize, page, isLinkedToPolymart, minecraftVersion],
        async () => {
            const { data } = await http.get(`/api/client/servers/${uuid}/minecraft-plugins`, {
                params: {
                    provider: pluginProvider,
                    search_query: searchQuery,
                    page_size: pageSize,
                    page: page,
                    minecraft_version: minecraftVersion,
                },
            });
            return {
                items: data.data || [],
                pagination: getPaginationSet(data.meta.pagination),
            };
        }
    );

    const handleLinkButton = async () => {
        const { data: redirectUrl } = await http.post(`/api/client/servers/${uuid}/minecraft-plugins/link`);
        window.location = redirectUrl;
    };

    const handleDisconnectButton = async () => {
        await http.post(`/api/client/servers/${uuid}/minecraft-plugins/disconnect`);
        mutate();
    };

    useEffect(() => {
        http.get('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json', {
            // for CORS
            withCredentials: false,
            transformRequest: [
                function (data, headers) {
                    // @ts-ignore
                    delete headers['X-Requested-With'];
                    return data;
                },
            ],
        }).then(({ data }) =>
            setMinecraftVersions(
                data.versions
                    .filter((v: { id: string; type: string }) => v.type === 'release')
                    .map((v: { id: string; type: string }) => v.id)
            )
        );
    }, []);

    useEffect(() => {
        // Don't use react-router to handle changing this part of the URL, otherwise it
        // triggers a needless re-render. We just want to track this in the URL incase the
        // user refreshes the page.
        const params = new URLSearchParams();
        if (pluginProvider != 'modrinth') {
            params.set('provider', pluginProvider);
        }
        if (searchQuery.length > 0) {
            params.set('query', searchQuery);
        }
        if (pageSize != 50) {
            params.set('pageSize', pageSize.toString());
        }
        if (minecraftVersion != '') {
            params.set('minecraftVersion', minecraftVersion);
        }
        if (page > 1) {
            params.set('page', page.toString());
        }

        window.history.replaceState(
            null,
            document.title,
            `/server/${shortUuid}/minecraft-plugins${params.toString().length > 0 ? '?' + params.toString() : ''}`
        );
    }, [pluginProvider, searchQuery, pageSize, page, minecraftVersion]);

    useEffect(() => {
        if (!plugins) return;
        if (plugins.pagination.currentPage > 1 && !plugins.items.length) {
            setPage(1);
        }
    }, [plugins?.pagination.currentPage]);

    useEffect(() => {
        if (!error) {
            clearFlashes('minecraft-plugins');
            return;
        }
        clearAndAddHttpError({ error, key: 'minecraft-plugins' });
    }, [error]);

    return (
        <ServerContentBlock title={'Minecraft Plugins'} showFlashKey='minecraft-plugins'>
            <div css={tw`flex flex-wrap items-end gap-4`}>
                <div css={tw`min-w-[112px]`}>
                    <Label htmlFor='plugin_provider'>Provider</Label>
                    <Select
                        name='plugin_provider'
                        value={pluginProvider}
                        onChange={(event) => setPluginProvider(event.target.value as MinecraftPluginProvider)}
                    >
                        <option value='curseforge'>CurseForge</option>
                        <option value='hangar'>Hangar</option>
                        <option value='modrinth'>Modrinth</option>
                        <option value='polymart'>Polymart</option>
                        <option value='spigotmc'>SpigotMC</option>
                    </Select>
                </div>
                <div>
                    <Label htmlFor={'page_size'}>Page size</Label>
                    <Select
                        name='page_size'
                        value={pageSize}
                        onChange={(event) => {
                            setPageSize(Number(event.target.value));
                        }}
                    >
                        <option value='10'>10</option>
                        <option value='25'>25</option>
                        <option value='50'>50</option>
                    </Select>
                </div>
                {(pluginProvider === 'modrinth' || pluginProvider === 'curseforge') && (
                    <div>
                        <Label htmlFor={'minecraft_version'}>Minecraft version</Label>
                        <Select
                            name='minecraft_version'
                            value={minecraftVersion}
                            onChange={(event) => {
                                setMinecraftVersion(event.target.value);
                            }}
                        >
                            <option value=''>All</option>
                            {minecraftVersions.map((ver) => (
                                <option key={ver} value={ver}>
                                    {ver}
                                </option>
                            ))}
                        </Select>
                    </div>
                )}
                <div css={tw`w-full md:w-auto md:flex-1`}>
                    <Label htmlFor='search_query'>Search query</Label>
                    <Input
                        type='text'
                        name='search_query'
                        value={searchQuery}
                        onChange={(event) => {
                            setSearchQuery(event.target.value);
                        }}
                    />
                </div>
                {pluginProvider === 'polymart' &&
                    isLinkedToPolymart != null &&
                    (isLinkedToPolymart ? (
                        <Button css={tw`h-12`} onClick={handleDisconnectButton}>
                            Unlink Polymart account
                        </Button>
                    ) : (
                        <Button css={tw`h-12`} onClick={handleLinkButton}>
                            Link Polymart account
                        </Button>
                    ))}
            </div>
            <div css={tw`mt-3`}>
                {!error && plugins ? (
                    <Pagination data={plugins} onPageSelect={setPage}>
                        {({ items }) =>
                            items.length > 0 ? (
                                items.map((plugin, index) => (
                                    <MinecraftPluginRow
                                        key={`${page}-${plugin.id}`}
                                        provider={pluginProvider}
                                        plugin={plugin}
                                        className={index > 0 ? 'mt-2' : undefined}
                                    />
                                ))
                            ) : (
                                <span>
                                    No &quot;Minecraft: Java Edition&quot; plugins have been found for your query.
                                </span>
                            )
                        }
                    </Pagination>
                ) : (
                    <Spinner centered size='base' />
                )}
            </div>
        </ServerContentBlock>
    );
};
