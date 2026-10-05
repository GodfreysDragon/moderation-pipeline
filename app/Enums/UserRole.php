<?php

namespace App\Enums;

enum UserRole: string
{
    case Member = 'member';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function isStaff(): bool
    {
        return $this !== self::Member;
    }
}
