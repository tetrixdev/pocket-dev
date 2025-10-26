<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelPricing extends Model
{
    protected $table = 'model_pricing';

    protected $fillable = [
        'model_name',
        'input_price_per_million',
        'cache_write_multiplier',
        'cache_read_multiplier',
        'output_price_per_million',
    ];

    protected $casts = [
        'input_price_per_million' => 'decimal:6',
        'cache_write_multiplier' => 'decimal:3',
        'cache_read_multiplier' => 'decimal:3',
        'output_price_per_million' => 'decimal:6',
    ];
}
