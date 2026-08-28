<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataProcessor extends Model
{
    protected $guarded = [];

    protected $casts = ['data_categories' => 'array', 'processing_countries' => 'array', 'review_due_at' => 'immutable_date'];
}
