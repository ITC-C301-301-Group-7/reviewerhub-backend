<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceLike extends Model
{
    protected $fillable = ['user_id', 'learning_resource_id'];
}
