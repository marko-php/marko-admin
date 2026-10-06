<?php

declare(strict_types=1);

namespace Marko\Admin\Discovery;

use Marko\Admin\Exceptions\AdminException;
use Marko\Core\Discovery\CachedDiscovery;
use Marko\Core\Exceptions\DiscoveryCacheException;
use Marko\Core\Module\ModuleRepositoryInterface;
use ReflectionException;

/**
 * The admin section definitions for this boot, shared by marko/admin (which
 * registers the sections) and marko/admin-auth (which registers their
 * permissions).
 *
 * A boot from the discovery cache hydrates the definitions from the
 * 'admin_sections' section; any other boot scans the enabled modules. Either
 * way the work happens once, on the first call to all(), and the result is
 * kept for the rest of the process.
 */
class DiscoveredAdminSections
{
    /** @var array<int, AdminSectionDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly CachedDiscovery $cachedDiscovery,
        private readonly AdminSectionCacheContributor $adminSectionCacheContributor,
        private readonly AdminSectionDiscovery $adminSectionDiscovery,
        private readonly ModuleRepositoryInterface $moduleRepository,
    ) {}

    /**
     * @return array<int, AdminSectionDefinition>
     *
     * @throws AdminException|DiscoveryCacheException|ReflectionException
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            $section = $this->cachedDiscovery->section(AdminSectionCacheContributor::KEY);

            $this->definitions = $section !== null
                ? $this->adminSectionCacheContributor->hydrate($section)
                : $this->adminSectionDiscovery->discoverAll($this->moduleRepository->all());
        }

        return $this->definitions;
    }
}
