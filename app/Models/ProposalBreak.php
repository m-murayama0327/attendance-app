<?php

namespace App\Models;

use App\Models\Concerns\HasBreakTimes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalBreak extends Model
{
    use HasBreakTimes, HasFactory;

    protected $fillable = [
        'application_id',
        'break_in',
        'break_out',
    ];
}
