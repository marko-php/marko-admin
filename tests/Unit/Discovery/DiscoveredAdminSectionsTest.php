<?php

declare(strict_types=1);

use Marko\Admin\Discovery\AdminSectionCacheContributor;
use Marko\Admin\Discovery\AdminSectionDefinition;
use Marko\Admin\Discovery\AdminSectionDiscovery;
use Marko\Admin\Discovery\DiscoveredAdminSections;
use Marko\Core\Discovery\CachedDiscovery;
use Marko\Core\Module\ModuleManifest;
use Marko\Core\Module\ModuleRepository;

/**
 * A module whose src/ declares one section.
 */
function discoveredSectionsTestModule(): ModuleManifest
{
    $tempDir = sys_get_temp_dir() . '/marko-admin-discovered-test-' . bin2hex(random_bytes(8));
    mkdir($tempDir . '/src', 0755, true);
    file_put_contents($tempDir . '/src/DockSection.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AdminDiscoveredTest;

use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;

#[AdminSection(id: 'dock', label: 'Dock')]
class DockSection implements AdminSectionInterface
{
    public function getId(): string { return 'dock'; }
    public function getLabel(): string { return 'Dock'; }
    public function getIcon(): string { return ''; }
    public function getSortOrder(): int { return 0; }
    public function getMenuItems(): array { return []; }
}
PHP);

    return new ModuleManifest(
        name: 'test/dock',
        version: '1.0.0',
        path: $tempDir,
    );
}

function removeDiscoveredSectionsTestModule(
    ModuleManifest $module,
): void {
    unlink($module->path . '/src/DockSection.php');
    rmdir($module->path . '/src');
    rmdir($module->path);
}

function discoveredAdminSections(
    CachedDiscovery $cachedDiscovery,
    ModuleManifest ...$modules,
): DiscoveredAdminSections {
    $discovery = new AdminSectionDiscovery();

    return new DiscoveredAdminSections(
        cachedDiscovery: $cachedDiscovery,
        adminSectionCacheContributor: new AdminSectionCacheContributor($discovery),
        adminSectionDiscovery: $discovery,
        moduleRepository: new ModuleRepository($modules),
    );
}

describe('DiscoveredAdminSections', function (): void {
    it('returns the cached definitions without scanning when the boot used the cache', function (): void {
        // The module path does not exist: a scan would find nothing.
        $missing = new ModuleManifest(
            name: 'test/missing',
            version: '1.0.0',
            path: sys_get_temp_dir() . '/marko-admin-discovered-missing',
        );
        $sections = discoveredAdminSections(new CachedDiscovery([
            AdminSectionCacheContributor::KEY => [
                [
                    'className' => 'App\\Admin\\CachedSection',
                    'id' => 'cached',
                    'label' => 'Cached',
                    'icon' => '',
                    'sortOrder' => 5,
                    'permissions' => [['id' => 'cached.view', 'label' => 'View']],
                ],
            ],
        ]), $missing);

        $definitions = $sections->all();

        expect($definitions)->toHaveCount(1)
            ->and($definitions[0]->className)->toBe('App\\Admin\\CachedSection')
            ->and($definitions[0]->permissions[0]->id)->toBe('cached.view');
    });

    it('scans the enabled modules when the boot did not use the cache', function (): void {
        $module = discoveredSectionsTestModule();

        try {
            $definitions = discoveredAdminSections(new CachedDiscovery(), $module)->all();
        } finally {
            removeDiscoveredSectionsTestModule($module);
        }

        expect(array_map(fn (AdminSectionDefinition $definition): string => $definition->id, $definitions))
            ->toBe(['dock']);
    });

    it('parses the sections only once', function (): void {
        $module = discoveredSectionsTestModule();
        $sections = discoveredAdminSections(new CachedDiscovery(), $module);

        try {
            $first = $sections->all();
        } finally {
            removeDiscoveredSectionsTestModule($module);
        }

        // The module is gone, so a second scan would find nothing.
        expect($sections->all())->toBe($first)
            ->and($first)->toHaveCount(1);
    });
});
