<?php

declare(strict_types=1);

use Marko\Admin\AdminSectionRegistry;
use Marko\Admin\Config\AdminConfig;
use Marko\Admin\Config\AdminConfigInterface;
use Marko\Admin\Contracts\AdminSectionInterface;
use Marko\Admin\Contracts\AdminSectionRegistryInterface;
use Marko\Admin\Discovery\AdminSectionCacheContributor;
use Marko\Admin\Discovery\DiscoveredAdminSections;
use Marko\Admin\Exceptions\AdminException;
use Marko\Core\Container\ContainerInterface;

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
        ContainerInterface $container,
    ): void {
        // Resolve each #[AdminSection] class through the container so it can inject dependencies.
        foreach ($discoveredAdminSections->all() as $definition) {
            if (!class_exists($definition->className)) {
                throw AdminException::sectionClassNotFound($definition->className);
            }

            $section = $container->get($definition->className);

            if (!$section instanceof AdminSectionInterface) {
                throw AdminException::sectionMustImplementInterface($definition->className);
            }

            // The registry keys on getId(); permissions and duplicate checks use the attribute id.
            if ($section->getId() !== $definition->id) {
                throw AdminException::sectionIdMismatch($definition->className, $definition->id, $section->getId());
            }

            $adminSectionRegistry->register($section);
        }
    },
];
