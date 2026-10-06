<?php

declare(strict_types=1);

namespace Marko\Admin\Discovery;

use Marko\Admin\Attributes\AdminPermission;
use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;
use Marko\Admin\Exceptions\AdminException;
use Marko\Core\Discovery\ClassFileParser;
use Marko\Core\Module\ModuleManifest;
use ReflectionClass;
use ReflectionException;

readonly class AdminSectionDiscovery
{
    public function __construct(
        private ClassFileParser $classFileParser = new ClassFileParser(),
    ) {}

    /**
     * Discover files declaring a class marked with #[AdminSection] in a module's src directory.
     *
     * A cheap text match on "#[AdminSection" selects candidate files; each candidate's class
     * is then loaded and confirmed with reflection. Files that only mention the attribute
     * (in a comment, or via a longer attribute name such as #[AdminSectionWidget]) are skipped.
     * A class that really carries the attribute is always reported, even when it is invalid,
     * so parseAdminSectionClass() can fail loudly on it.
     *
     * @return array<string> List of absolute paths to PHP files containing admin sections
     */
    public function discoverInModule(
        ModuleManifest $manifest,
    ): array {
        $sectionFiles = [];

        foreach ($this->classFileParser->findPhpFiles($manifest->path . '/src') as $file) {
            $filePath = $file->getPathname();
            $content = file_get_contents($filePath);

            if ($content === false || !str_contains($content, '#[AdminSection')) {
                continue;
            }

            if ($this->declaresAdminSection($filePath)) {
                $sectionFiles[] = $filePath;
            }
        }

        return $sectionFiles;
    }

    private function declaresAdminSection(
        string $filePath,
    ): bool {
        $className = $this->classFileParser->extractClassName($filePath);

        if ($className === null || !$this->classFileParser->loadClass($filePath, $className)) {
            return false;
        }

        /** @var class-string $className */
        return (new ReflectionClass($className))->getAttributes(AdminSection::class) !== [];
    }

    /**
     * Parse a class with AdminSection attribute into a definition.
     *
     * @param class-string $className
     * @throws AdminException|ReflectionException
     */
    public function parseAdminSectionClass(
        string $className,
    ): AdminSectionDefinition {
        $reflection = new ReflectionClass($className);
        $sectionAttributes = $reflection->getAttributes(AdminSection::class);

        if ($sectionAttributes === []) {
            throw AdminException::missingSectionAttribute($className);
        }

        if (!$reflection->implementsInterface(AdminSectionInterface::class)) {
            throw AdminException::sectionMustImplementInterface($className);
        }

        $sectionAttribute = $sectionAttributes[0]->newInstance();

        $permissions = [];
        $permissionAttributes = $reflection->getAttributes(AdminPermission::class);
        foreach ($permissionAttributes as $permissionAttribute) {
            $permission = $permissionAttribute->newInstance();
            $permissions[] = new AdminPermissionDefinition(
                id: $permission->id,
                label: $permission->label,
            );
        }

        return new AdminSectionDefinition(
            className: $className,
            id: $sectionAttribute->id,
            label: $sectionAttribute->label,
            icon: $sectionAttribute->icon,
            sortOrder: $sectionAttribute->sortOrder,
            permissions: $permissions,
        );
    }
}
