<?php

declare(strict_types=1);

namespace Marko\Admin\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class AdminException extends MarkoException
{
    public static function duplicateSection(string $id): self
    {
        return new self(
            message: "Admin section with id '$id' is already registered",
            context: "While registering admin section '$id'",
            suggestion: 'Ensure each admin section has a unique id',
        );
    }

    public static function sectionNotFound(string $id): self
    {
        return new self(
            message: "Admin section '$id' not found",
            context: "While retrieving admin section '$id'",
            suggestion: 'Ensure the admin section is registered before accessing it',
        );
    }

    public static function missingSectionAttribute(string $className): self
    {
        return new self(
            message: "Class '$className' is not marked with #[AdminSection]",
            context: "While parsing admin section class '$className'",
            suggestion: "Add #[AdminSection(id: ..., label: ...)] to the class, or don't pass it to admin section discovery",
        );
    }

    public static function sectionMustImplementInterface(string $className): self
    {
        return new self(
            message: "Class '$className' has #[AdminSection] attribute but does not implement AdminSectionInterface",
            context: "While discovering admin sections in class '$className'",
            suggestion: "Ensure '$className' implements Marko\\Admin\\Contracts\\AdminSectionInterface",
        );
    }
}
