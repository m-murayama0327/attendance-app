<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalBreak extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'break_in',
        'break_out',
    ];

    /**
     * この休憩を直したい申請を返す。
     *
     * @return BelongsTo この休憩を直したい申請
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
