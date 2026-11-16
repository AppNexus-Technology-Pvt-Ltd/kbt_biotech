<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    protected $table = 'login_history';

    public $timestamps = false;

    protected $fillable = ['user_id', 'identifier', 'status', 'ip_address', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
