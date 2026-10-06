<?php

declare(strict_types=1);

namespace Marko\Admin\Contracts;

use Marko\Admin\Discovery\AdminSectionDefinition;
use Marko\Admin\Exceptions\AdminException;

interface AdminSectionRegistryInterface
{
    /**
     * Register a section that is already built.
     *
     * @throws AdminException
     */
    public function register(AdminSectionInterface $section): void;

    /**
     * Register a section by its definition, without building it. The section is
     * built through the container the first time get() or all() needs it.
     *
     * @throws AdminException
     */
    public function registerDefinition(AdminSectionDefinition $definition): void;

    /**
     * @return array<AdminSectionInterface>
     *
     * @throws AdminException
     */
    public function all(): array;

    /**
     * @throws AdminException
     */
    public function get(string $id): AdminSectionInterface;
}
