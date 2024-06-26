<?php

namespace App\Models;

use App\Traits\ApiQuery;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

class PoolInvest extends Model
{
    use Searchable, ApiQuery;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pool()
    {
        return $this->belongsTo(Pool::class);
    }
}
