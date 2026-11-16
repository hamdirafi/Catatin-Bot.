<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    protected $fillable = [
        'chat_id',
        'description',
        'amount',
        'category',
    ];
}