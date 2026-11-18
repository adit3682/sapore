<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Done = 'done';
}
