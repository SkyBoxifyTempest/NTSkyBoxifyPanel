import http from '@/api/http';
import deleteFiles from '@/api/server/files/deleteFiles';
import Button from '@/components/elements/Button';
import Code from '@/components/elements/Code';
import { Dialog } from '@/components/elements/dialog';
import Select from '@/components/elements/Select';
import Switch from '@/components/elements/Switch';
import Label from '@/components/elements/Label';
import GreyRowBox from '@/components/elements/GreyRowBox';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import { faTrash, faExternalLinkAlt, faDownload } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import {
    MinecraftPlugin,
    MinecraftPluginProvider,
} from '@/components/server/minecraft-plugins/MinecraftPluginContainer';

interface Props {
    provider: MinecraftPluginProvider;
    plugin: MinecraftPlugin;
    className?: string;
}

interface MinecraftPluginVersion {
    id: string;
    name: string;
    game_versions: string[];
    download_url: string;
}

export default ({ provider, plugin, className }: Props) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [installDialogVisible, setInstallDialogVisible] = useState<boolean>(false);
    const [versions, setVersions] = useState<MinecraftPluginVersion[]>([]);
    const [selectedVersion, setSelectedVersion] = useState<string | null>(null);

    const installPlugin = () => {
        http.post(`/api/client/servers/${uuid}/minecraft-plugins/install`, {
            provider: provider,
            pluginId: plugin.id,
            versionId: selectedVersion,
        })
            .then(() => {
                clearFlashes();
                addFlash({
                    key: 'minecraft-plugins',
                    message: `Plugin "${plugin.name}" successfully installed in /home/container/plugins.`,
                    type: 'success',
                });
            })
            .catch((error) => {
                clearAndAddHttpError({ error, key: 'minecraft-plugins' });
            })
            .finally(() => {});

        setInstallDialogVisible(false);
    };

    useEffect(() => {
        if (installDialogVisible && !versions.length) {
            http.get(`/api/client/servers/${uuid}/minecraft-plugins/versions`, {
                params: {
                    provider: provider,
                    pluginId: plugin.id,
                },
            })
                .then(({ data }) => {
                    setVersions(data);
                    setSelectedVersion(data[0]?.id);
                })
                .catch((error) => {
                    clearAndAddHttpError({ error, key: 'minecraft-plugins' });
                    setInstallDialogVisible(false);
                });
        }
    }, [installDialogVisible]);

    return (
        <>
            <Dialog.Confirm
                title={'Install plugin'}
                confirm={'Install plugin'}
                open={installDialogVisible}
                onClose={() => setInstallDialogVisible(false)}
                onConfirmed={installPlugin}
            >
                <p>
                    You requested the installation of the plugin &quot;{plugin.name}&quot; from the {provider} provider.
                    Please select the desired plugin version below.
                </p>
                <Label className={'mt-3'} htmlFor='plugin_version_id'>
                    Plugin version
                </Label>
                <Select
                    name='plugin_version_id'
                    onChange={(event) => {
                        setSelectedVersion(event.target.value);
                    }}
                >
                    {versions.map((version) => (
                        <option key={version.id} value={version.id}>
                            {version.name}
                        </option>
                    ))}
                </Select>
            </Dialog.Confirm>
            <GreyRowBox className={className} css={tw`items-center`}>
                <img
                    src={plugin.icon_url ?? 'https://placehold.co/32'}
                    css={tw`rounded-md w-8 h-8 sm:w-16 sm:h-16 object-contain flex items-center justify-center bg-neutral-500 sm:p-3`}
                />
                <div css={tw`ml-3 flex flex-col`}>
                    {plugin.url ? (
                        <a href={plugin.url} target='_blank' css={tw`hover:text-gray-400`}>
                            {plugin.name} <FontAwesomeIcon icon={faExternalLinkAlt} css={tw`ml-1 h-3 w-3`} />
                        </a>
                    ) : (
                        <p>{plugin.name}</p>
                    )}
                    <p css={tw`text-gray-400`}>{plugin.short_description}</p>
                </div>
                {plugin.external_url ? (
                    <a
                        title='Go to external URL'
                        target='_blank'
                        css={tw`ml-auto p-2 text-sm text-neutral-400 hover:text-green-400 transition-colors duration-150`}
                        href={plugin.external_url}
                    >
                        <FontAwesomeIcon icon={faExternalLinkAlt} />
                    </a>
                ) : (
                    <button
                        title='Install'
                        css={tw`ml-auto p-2 text-sm text-neutral-400 hover:text-green-400 transition-colors duration-150`}
                        onClick={() => setInstallDialogVisible(true)}
                    >
                        <FontAwesomeIcon icon={faDownload} />
                    </button>
                )}
            </GreyRowBox>
        </>
    );
};
