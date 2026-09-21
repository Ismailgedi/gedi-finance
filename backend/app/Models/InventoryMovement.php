<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'unit_id',
        'quantity',
        'base_quantity',
        'movement_type',
        'reference_type',
        'reference_id',
        'occurred_at',
        'reason',
        'notes',
        'created_by',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
