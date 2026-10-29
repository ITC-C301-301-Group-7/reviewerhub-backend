<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoResource extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'course_code',
        'college',
        'description',
        'url',
        'provider',
        'video_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
