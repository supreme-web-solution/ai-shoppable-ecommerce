<?php

namespace App\Support;

use App\Models\User;

class PlatformAdmin
{
    /**
     * @return list<string>
     */
    public static function allowedEmails(): array
    {
        return config('admin.emails', []);
    }

    public static function isAllowedEmail(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        return in_array($email, self::allowedEmails(), true);
    }

    public static function isPlatformAdmin(?User $user): bool
    {
        return $user !== null && self::isAllowedEmail($user->email);
    }
}
