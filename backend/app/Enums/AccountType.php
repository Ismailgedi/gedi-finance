<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';
    case Other = 'other';
}
