<?php

namespace App\Models;

use App\Traits\ApiQuery;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

class StakingInvest extends Model
{
    use Searchable, ApiQuery;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
