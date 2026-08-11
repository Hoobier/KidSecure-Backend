<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Model
{
    use Notifiable, HasApiTokens;

    protected $connection = 'mongodb';
    protected $collection = 'admins';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];
}