<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ZoomIntegrationSetting extends Model
{
    use SoftDeletes;
    protected $fillable = ['account_id', 'client_id', 'client_secret'];

    protected function casts(): array
    {
        return [
            'account_id' => 'encrypted',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
        ];
    }
}
