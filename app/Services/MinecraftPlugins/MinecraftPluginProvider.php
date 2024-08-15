<?php

namespace Pterodactyl\Services\MinecraftPlugins;

enum MinecraftPluginProvider: string
{
    case CurseForge = 'curseforge';
    case Hangar = 'hangar';
    case Modrinth = 'modrinth';
    case SpigotMC = 'spigotmc';
    case Polymart = 'polymart';
}
