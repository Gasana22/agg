<?php

namespace App\Enums;

enum MediaCategory: string
{
    case Photo = 'photo';
    case Document = 'document';
    case Certificate = 'certificate';
    case Invoice = 'invoice';
    case Other = 'other';
}
