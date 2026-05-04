<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveTypeUserVisibility extends Model
{
    protected $fillable = [
        'leave_type_id',
        'user_id',
    ];
}
