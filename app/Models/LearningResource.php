<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function ratings(): HasMany
    {
        return $this->hasMany(ResourceRating::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ResourceComment::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(ResourceBookmark::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ResourceLike::class);
    }

    // Hidden reviewers can only be opened by their owner or a teacher.
    public function isViewableBy(?User $user): bool
    {
        return $this->status === 'approved'
            || ($user && ($user->role === 'teacher' || $user->id === $this->user_id));
    }
}
