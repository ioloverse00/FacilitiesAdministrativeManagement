<?php

declare(strict_types=1);

final class VisitorPolicy
{
    public static function can(array $user, string $permission): bool
    {
        return in_array($permission, $user['permissions'] ?? [], true) || in_array('visitors.manage', $user['permissions'] ?? [], true);
    }

    public static function require(array $user, string $permission): void
    {
        if (!self::can($user, $permission)) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
    }
}
