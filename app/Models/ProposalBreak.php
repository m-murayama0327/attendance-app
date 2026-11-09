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

    /**
     * 直したい休憩の入りを H:i の文字で返す。
     *
     * @return string 休憩の入り（例：12:00）
     */
    public function formattedBreakIn(): string
    {
        return substr($this->break_in, 0, 5);
    }

    /**
     * 直したい休憩の戻りを H:i の文字で返す。
     *
     * @return string 休憩の戻り（例：13:00）
     */
    public function formattedBreakOut(): string
    {
        return substr($this->break_out, 0, 5);
    }
}
