<?php

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Viewer = 'viewer';
}
