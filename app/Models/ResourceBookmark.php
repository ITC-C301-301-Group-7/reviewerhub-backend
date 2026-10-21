<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceBookmark extends Model
{
    protected $fillable = ['user_id', 'learning_resource_id'];
}
