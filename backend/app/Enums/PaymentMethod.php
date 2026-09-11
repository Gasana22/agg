<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MobileMoney = 'mobile_money';
    case Cheque = 'cheque';
    case Other = 'other';
}
