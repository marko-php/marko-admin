<?php

declare(strict_types=1);

use Marko\Admin\Discovery\AdminPermissionDefinition;
use Marko\Admin\Discovery\AdminSectionCacheContributor;
use Marko\Admin\Discovery\AdminSectionDefinition;
use Marko\Admin\Discovery\AdminSectionDiscovery;
use Marko\Core\Exceptions\DiscoveryCacheException;
use Marko\Core\Module\ModuleManifest;

/**
 * A module whose src/ declares one section with two permissions.
 */
function adminCacheTestModule(): ModuleManifest
{
    $tempDir = sys_get_temp_dir() . '/marko-admin-cache-test-' . bin2hex(random_bytes(8));
    mkdir($tempDir . '/src', 0755, true);
    file_put_contents($tempDir . '/src/WarehouseSection.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AdminCacheTest;

use Marko\Admin\Attributes\AdminPermission;
use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;

#[AdminSection(id: 'warehouse', label: 'Warehouse', icon: 'truck', sortOrder: 40)]
#[AdminPermission(id: 'warehouse.stock.view', label: 'View Stock')]
#[AdminPermission(id: 'warehouse.stock.edit', label: 'Edit Stock')]
class WarehouseSection implements AdminSectionInterface
{
    public function getId(): string { return 'warehouse'; }
    public function getLabel(): string { return 'Warehouse'; }
    public function getIcon(): string { return 'truck'; }
    public function getSortOrder(): int { return 40; }
    public function getMenuItems(): array { return []; }
}
PHP);

    return new ModuleManifest(
        name: 'test/warehouse',
        version: '1.0.0',
        path: $tempDir,
    );
}

function removeAdminCacheTestModule(
    ModuleManifest $module,
): void {
    unlink($module->path . '/src/WarehouseSection.php');
    rmdir($module->path . '/src');
    rmdir($module->path);
}

describe('AdminSectionCacheContributor', function (): void {
    it('compiles every section definition into exportable records', function (): void {
        $module = adminCacheTestModule();
        $contributor = new AdminSectionCacheContributor(new AdminSectionDiscovery());

        try {
            $section = $contributor->compile([$module]);
        } finally {
            removeAdminCacheTestModule($module);
        }

        expect($contributor->key())->toBe('admin_sections')
            ->and($section)->toBe([
                [
                    'className' => 'AdminCacheTest\\WarehouseSection',
                    'id' => 'warehouse',
                    'label' => 'Warehouse',
                    'icon' => 'truck',
                    'sortOrder' => 40,
                    'permissions' => [
                        ['id' => 'warehouse.stock.view', 'label' => 'View Stock'],
                        ['id' => 'warehouse.stock.edit', 'label' => 'Edit Stock'],
                    ],
                ],
            ]);
    });

    it('hydrates compiled records back into equal definitions', function (): void {
        $module = adminCacheTestModule();
        $discovery = new AdminSectionDiscovery();
        $contributor = new AdminSectionCacheContributor($discovery);

        try {
            $live = $discovery->discoverAll([$module]);
            $section = $contributor->compile([$module]);
        } finally {
            removeAdminCacheTestModule($module);
        }

        $hydrated = $contributor->hydrate($section);

        expect($hydrated)->toEqual($live)
            ->and($hydrated[0])->toBeInstanceOf(AdminSectionDefinition::class)
            ->and($hydrated[0]->permissions[0])->toBeInstanceOf(AdminPermissionDefinition::class);
    });

    it(
        'throws a malformed section error for an invalid cached record',
        function (array $section, string $problem): void {
            $contributor = new AdminSectionCacheContributor(new AdminSectionDiscovery());
    
            expect(fn () => $contributor->hydrate($section))
                ->toThrow(DiscoveryCacheException::class, $problem);
        }
    )->with([
        'record is not an array' => [['warehouse'], 'admin section 0 must be an array'],
        'missing class name' => [
            [['id' => 'a', 'label' => 'A', 'icon' => '', 'sortOrder' => 0, 'permissions' => []]],
            'admin section 0.className must be a string',
        ],
        'sort order is not an int' => [
            [['className' => 'A', 'id' => 'a', 'label' => 'A', 'icon' => '', 'sortOrder' => '1', 'permissions' => []]],
            'admin section 0.sortOrder must be an int',
        ],
        'permission without a label' => [
            [[
                'className' => 'A',
                'id' => 'a',
                'label' => 'A',
                'icon' => '',
                'sortOrder' => 0,
                'permissions' => [['id' => 'a.view']],
            ]],
            'admin section 0.permissions must be a list of {id, label} string pairs',
        ],
    ]);
});
