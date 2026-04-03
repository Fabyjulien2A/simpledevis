<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
    'user_id',
    'company_name',
    'email',
    'phone',
    'address',
    'siret',
    'tva_number',
    'logo',
];
}

