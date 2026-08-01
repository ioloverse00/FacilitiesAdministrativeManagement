<?php

declare(strict_types=1);

final class AuthenticatedUser
{
    /**
     * @param array<string, mixed> $profile
     * @param list<array{code:string,name:string}> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        private readonly array $profile,
        private readonly array $roles,
        private readonly array $permissions
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->profile['id'],
            'username' => $this->profile['username'],
            'employee_id' => $this->profile['employee_id'],
            'employee_number' => $this->profile['employee_number'],
            'full_name' => $this->profile['full_name'],
            'position' => $this->profile['position'],
            'email' => $this->profile['email'],
            'department' => $this->profile['department'],
            'roles' => $this->roles,
            'permissions' => $this->permissions,
        ];
    }
}
