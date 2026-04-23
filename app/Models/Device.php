<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_number',
        'code_a',
        'code_b',
        'code_c',
        'code_d',
        'code_e',
        'code_f',
        'code_ruka',
    ];
}
