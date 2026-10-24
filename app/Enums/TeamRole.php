<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Viewer = 'viewer';

    /**
     * Get the human readable name of the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Admin'),
            self::Viewer => __('Viewer'),
        };
    }

    /**
     * Get a short description of what the role allows.
     */
    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Everything an admin can do, plus managing owners and deleting the team.'),
            self::Admin => __('Read everything, invite, remove and change members (not owners).'),
            self::Viewer => __('Read every page of the team\'s sites.'),
        };
    }

    /**
     * Determine if the role may invite, remove and change members.
     */
    public function canManageMembers(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Determine if the role may add, change, move and delete sites.
     */
    public function canManageSites(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Get the roles that this role is allowed to grant to someone else.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => self::cases(),
            self::Admin => [self::Admin, self::Viewer],
            self::Viewer => [],
        };
    }
}
