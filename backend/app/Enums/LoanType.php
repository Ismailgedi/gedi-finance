<?php

namespace App\Enums;

enum LoanType: string
{
    case Given = 'given';
    case Received = 'received';
}
