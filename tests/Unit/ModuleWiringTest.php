<?php

declare(strict_types=1);

namespace Marko\Admin\Tests\Unit;

use Marko\Admin\AdminSectionRegistry;
use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;
use Marko\Admin\Contracts\AdminSectionRegistryInterface;
use Marko\Admin\Discovery\AdminSectionCacheContributor;
use Marko\Admin\Discovery\DiscoveredAdminSections;
use Marko\Admin\Exceptions\AdminException;
use Marko\Core\Container\BindingRegistry;
use Marko\Core\Container\Container;
use Marko\Core\Container\ContainerInterface;
use Marko\Core\Discovery\CachedDiscovery;
use Marko\Core\Module\ModuleManifest;
use Marko\Core\Module\ModuleRepository;
use Marko\Core\Module\ModuleRepositoryInterface;

readonly class WiringSectionReader
{
    public function __construct(
        public AdminSectionRegistryInterface $adminSectionRegistry,
    ) {}
}

readonly class WiringSectionWriter
{
    public function __construct(
        public AdminSectionRegistryInterface $adminSectionRegistry,
    ) {}
}

class WiringSectionLabels
{
    public function label(): string
    {
        return 'Injected Label';
    }
}

#[AdminSection(id: 'injected', label: 'Injected')]
readonly class WiringInjectedSection implements AdminSectionInterface
{
    public function __construct(
        private WiringSectionLabels $labels,
    ) {}

    public function getId(): string
    {
        return 'injected';
    }

    public function getLabel(): string
    {
        return $this->labels->label();
    }

    public function getIcon(): string
    {
        return '';
    }

    public function getSortOrder(): int
    {
        return 0;
    }

    public function getMenuItems(): array
    {
        return [];
    }
}

/**
 * @return array<string, mixed>
 */
function adminModule(): array
{
    return require dirname(__DIR__, 2) . '/module.php';
}

/**
 * Build a container wired with admin's module.php, plus what Application binds
 * before boot callbacks run: the module repository and the discovery cache.
 */
function adminModuleContainer(
    CachedDiscovery $cachedDiscovery,
    ModuleManifest ...$modules,
): Container {
    $module = adminModule();

    $container = new Container();
    $container->instance(ContainerInterface::class, $container);
    $container->instance(CachedDiscovery::class, $cachedDiscovery);
    $container->instance(ModuleRepositoryInterface::class, new ModuleRepository($modules));

    new BindingRegistry($container)->registerModule(new ModuleManifest(
        name: 'marko/admin',
        version: '1.0.0',
        bindings: $module['bindings'],
        singletons: $module['singletons'] ?? [],
    ));

    return $container;
}

/**
 * Write one PHP file into a fresh app module's src/ and return its manifest.
 */
function adminWiringAppModule(
    string $fileName,
    string $code,
): ModuleManifest {
    $path = sys_get_temp_dir() . '/marko-admin-wiring-' . bin2hex(random_bytes(8));
    mkdir($path . '/src', 0755, true);
    file_put_contents("$path/src/$fileName", $code);

    return new ModuleManifest(
        name: 'app/backoffice',
        version: '1.0.0',
        path: $path,
    );
}

function removeAdminWiringAppModule(
    ModuleManifest $module,
): void {
    array_map('unlink', glob($module->path . '/src/*.php') ?: []);
    rmdir($module->path . '/src');
    rmdir($module->path);
}

it('binds AdminSectionRegistryInterface to AdminSectionRegistry as a singleton in module.php', function (): void {
    $module = adminModule();

    expect($module['singletons'][AdminSectionRegistryInterface::class] ?? null)->toBe(AdminSectionRegistry::class)
        ->and($module['bindings'])->not->toHaveKey(AdminSectionRegistryInterface::class);
});

it('shares registered sections between separately injected consumers', function (): void {
    $container = adminModuleContainer(new CachedDiscovery());

    $container->get(WiringSectionWriter::class)->adminSectionRegistry->register(
        $container->get(WiringInjectedSection::class),
    );

    $ids = array_map(
        static fn (AdminSectionInterface $section): string => $section->getId(),
        $container->get(WiringSectionReader::class)->adminSectionRegistry->all(),
    );

    expect($ids)->toBe(['injected']);
});

it('registers an attribute-declared section at boot with no manual registration', function (): void {
    $appModule = adminWiringAppModule('ReportsSection.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AdminWiringBoot;

use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;

#[AdminSection(id: 'reports', label: 'Reports')]
class ReportsSection implements AdminSectionInterface
{
    public function getId(): string { return 'reports'; }
    public function getLabel(): string { return 'Reports'; }
    public function getIcon(): string { return 'chart'; }
    public function getSortOrder(): int { return 30; }
    public function getMenuItems(): array { return []; }
}
PHP);
    $container = adminModuleContainer(new CachedDiscovery(), $appModule);

    try {
        $container->call(adminModule()['boot']);
    } finally {
        removeAdminWiringAppModule($appModule);
    }

    $sections = $container->get(WiringSectionReader::class)->adminSectionRegistry->all();

    expect($sections)->toHaveCount(1)
        ->and($sections[0]::class)->toBe('AdminWiringBoot\\ReportsSection');
});

it('resolves sections through the container so they can inject dependencies', function (): void {
    $container = adminModuleContainer(new CachedDiscovery([
        AdminSectionCacheContributor::KEY => [
            [
                'className' => WiringInjectedSection::class,
                'id' => 'injected',
                'label' => 'Injected',
                'icon' => '',
                'sortOrder' => 0,
                'permissions' => [],
            ],
        ],
    ]));

    $container->call(adminModule()['boot']);

    expect($container->get(AdminSectionRegistryInterface::class)->get('injected')->getLabel())
        ->toBe('Injected Label');
});

it('registers sections from a warm discovery cache without scanning', function (): void {
    // The only module points at a path that does not exist: a scan would find nothing.
    $missing = new ModuleManifest(
        name: 'app/missing',
        version: '1.0.0',
        path: sys_get_temp_dir() . '/marko-admin-wiring-missing',
    );
    $container = adminModuleContainer(new CachedDiscovery([
        AdminSectionCacheContributor::KEY => [
            [
                'className' => WiringInjectedSection::class,
                'id' => 'injected',
                'label' => 'Injected',
                'icon' => '',
                'sortOrder' => 0,
                'permissions' => [],
            ],
        ],
    ]), $missing);

    $container->call(adminModule()['boot']);

    expect(array_map(
        static fn (AdminSectionInterface $section): string => $section::class,
        $container->get(AdminSectionRegistryInterface::class)->all(),
    ))->toBe([WiringInjectedSection::class]);
});

it('declares DiscoveredAdminSections as a singleton in module.php', function (): void {
    $module = adminModule();
    $container = adminModuleContainer(new CachedDiscovery());

    expect($module['singletons'])->toContain(DiscoveredAdminSections::class)
        ->and($container->get(DiscoveredAdminSections::class))
        ->toBe($container->get(DiscoveredAdminSections::class));
});

it('declares the admin section contributor in module.php', function (): void {
    expect(adminModule()['discovery'])->toBe([AdminSectionCacheContributor::class]);
});

it('names both classes when a duplicate section id is registered', function (): void {
    $container = adminModuleContainer(new CachedDiscovery());
    $registry = $container->get(AdminSectionRegistryInterface::class);
    $registry->register($container->get(WiringInjectedSection::class));

    $duplicate = new readonly class () implements AdminSectionInterface
    {
        public function getId(): string
        {
            return 'injected';
        }

        public function getLabel(): string
        {
            return 'Duplicate';
        }

        public function getIcon(): string
        {
            return '';
        }

        public function getSortOrder(): int
        {
            return 0;
        }

        public function getMenuItems(): array
        {
            return [];
        }
    };

    expect(fn () => $registry->register($duplicate))->toThrow(
        AdminException::class,
        "Admin section with id 'injected' is declared by both '" . WiringInjectedSection::class . "' and '",
    );
});

/**
 * A warm discovery cache holding one section record.
 */
function adminWiringCache(
    string $className,
    string $id,
): CachedDiscovery {
    return new CachedDiscovery([
        AdminSectionCacheContributor::KEY => [
            [
                'className' => $className,
                'id' => $id,
                'label' => 'Label',
                'icon' => '',
                'sortOrder' => 0,
                'permissions' => [],
            ],
        ],
    ]);
}

it('throws sectionIdMismatch when getId differs from the attribute id', function (): void {
    $container = adminModuleContainer(adminWiringCache(WiringInjectedSection::class, 'other'));

    expect(fn () => $container->call(adminModule()['boot']))->toThrow(
        AdminException::class,
        "Admin section '" . WiringInjectedSection::class
        . "' declares id 'other' in #[AdminSection] but getId() returns 'injected'",
    );
});

it('tells you to recompile the discovery cache when a cached section class no longer exists', function (): void {
    $container = adminModuleContainer(adminWiringCache('App\\Admin\\RemovedSection', 'removed'));

    expect(fn () => $container->call(adminModule()['boot']))->toThrow(
        AdminException::class,
        "Admin section class 'App\\Admin\\RemovedSection' does not exist",
    );
});

it('fails boot loudly when a cached section class does not implement AdminSectionInterface', function (): void {
    $container = adminModuleContainer(new CachedDiscovery([
        AdminSectionCacheContributor::KEY => [
            [
                'className' => WiringSectionLabels::class,
                'id' => 'labels',
                'label' => 'Labels',
                'icon' => '',
                'sortOrder' => 0,
                'permissions' => [],
            ],
        ],
    ]));

    expect(fn () => $container->call(adminModule()['boot']))->toThrow(
        AdminException::class,
        "Class '" . WiringSectionLabels::class . "' has #[AdminSection] attribute but does not implement AdminSectionInterface",
    );
});
