<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Chef = 'chef';
    case Inventory = 'inventory';
    case Cashier = 'cashier';
}
