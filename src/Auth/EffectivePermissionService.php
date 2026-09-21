<?php

declare(strict_types=1);

final class EffectivePermissionService
{
    public static function existsSql(string $userAlias = 'ua'): string
    {
        return "EXISTS (
            SELECT 1
            FROM user_role ep_ur
            INNER JOIN role ep_r
                ON ep_r.role_id = ep_ur.role_id
               AND ep_r.status = 'ACTIVE'
            INNER JOIN role_permission ep_rp
                ON ep_rp.role_id = ep_r.role_id
            INNER JOIN permission ep_p
                ON ep_p.permission_id = ep_rp.permission_id
            WHERE ep_ur.user_account_id = {$userAlias}.user_account_id
              AND (ep_ur.expires_at IS NULL OR ep_ur.expires_at > NOW())
              AND ep_p.permission_code = :permission
        )
        OR EXISTS (
            SELECT 1
            FROM user_permission ep_up
            INNER JOIN permission ep_user_p
                ON ep_user_p.permission_id = ep_up.permission_id
            WHERE ep_up.user_account_id = {$userAlias}.user_account_id
              AND (ep_up.expires_at IS NULL OR ep_up.expires_at > NOW())
              AND ep_user_p.permission_code = :permission
        )";
    }

    public static function inSql(array $permissions, string $userAlias = 'ua'): string
    {
        $quoted = implode(',', array_map(
            static fn (string $permission): string => "'" . str_replace("'", "''", $permission) . "'",
            $permissions
        ));

        return "EXISTS (
            SELECT 1
            FROM user_role ep_ur
            INNER JOIN role ep_r
                ON ep_r.role_id = ep_ur.role_id
               AND ep_r.status = 'ACTIVE'
            INNER JOIN role_permission ep_rp
                ON ep_rp.role_id = ep_r.role_id
            INNER JOIN permission ep_p
                ON ep_p.permission_id = ep_rp.permission_id
            WHERE ep_ur.user_account_id = {$userAlias}.user_account_id
              AND (ep_ur.expires_at IS NULL OR ep_ur.expires_at > NOW())
              AND ep_p.permission_code IN ($quoted)
        )
        OR EXISTS (
            SELECT 1
            FROM user_permission ep_up
            INNER JOIN permission ep_user_p
                ON ep_user_p.permission_id = ep_up.permission_id
            WHERE ep_up.user_account_id = {$userAlias}.user_account_id
              AND (ep_up.expires_at IS NULL OR ep_up.expires_at > NOW())
              AND ep_user_p.permission_code IN ($quoted)
        )";
    }
}
