<?php

namespace App\Enums;

enum BusinessRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
