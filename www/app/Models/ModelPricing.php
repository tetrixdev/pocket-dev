<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelPricing extends Model
{
    protected $table = 'model_pricing';

    protected $fillable = [
        'provider',
        'model_id',
        'name',
        'input_price',
        'output_price',
        'cache_write_price',
        'cache_read_price',
    ];

    protected $casts = [
        'input_price' => 'decimal:4',
        'output_price' => 'decimal:4',
        'cache_write_price' => 'decimal:4',
        'cache_read_price' => 'decimal:4',
    ];
}
