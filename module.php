<?php

declare(strict_types=1);

use Marko\Admin\AdminSectionRegistry;
use Marko\Admin\Config\AdminConfig;
use Marko\Admin\Config\AdminConfigInterface;
use Marko\Admin\Contracts\AdminSectionRegistryInterface;
use Marko\Admin\Discovery\AdminSectionCacheContributor;
use Marko\Admin\Discovery\DiscoveredAdminSections;

return [
    'bindings' => [
        AdminConfigInterface::class => AdminConfig::class,
    ],
    'singletons' => [
        // Shared: sections registered at boot must be visible to every consumer.
        AdminSectionRegistryInterface::class => AdminSectionRegistry::class,
        // Shared: the section list is discovered (or read from the cache) once and
        // reused by marko/admin-auth to register the sections' permissions.
        DiscoveredAdminSections::class,
    ],
    'discovery' => [
        AdminSectionCacheContributor::class,
    ],
    'boot' => function (
        DiscoveredAdminSections $discoveredAdminSections,
        AdminSectionRegistryInterface $adminSectionRegistry,
    ): void {
        // Register definitions only: each section is built on first use, so boot and
        // CLI commands such as db:migrate never run a section's constructor.
        foreach ($discoveredAdminSections->all() as $definition) {
            $adminSectionRegistry->registerDefinition($definition);
        }
    },
];
