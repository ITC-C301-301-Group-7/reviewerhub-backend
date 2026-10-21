<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningResource extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'course_code',
        'college',
        'description',
        'original_filename',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'moderation_note',
        'moderated_by',
        'moderated_at',
    ];

    protected function casts(): array
    {
        return ['moderated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
