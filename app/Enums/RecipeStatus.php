<?php

namespace App\Enums;

enum RecipeStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
