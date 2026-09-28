<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'unit_id',
        'system_quantity',
        'physical_quantity',
        'quantity_difference',
        'unit_cost',
        'total_adjustment_value',
        'reason',
        'adjustment_date',
        'inventory_movement_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:4',
            'physical_quantity' => 'decimal:4',
            'quantity_difference' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_adjustment_value' => 'decimal:2',
            'adjustment_date' => 'date',
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

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
