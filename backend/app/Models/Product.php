<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'supplier_id',
        'base_unit_id',
        'name',
        'sku',
        'default_cost_price',
        'default_selling_price',
        'default_wholesale_price',
        'minimum_stock',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_cost_price' => 'decimal:4',
            'default_selling_price' => 'decimal:4',
            'default_wholesale_price' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * The supplier this product is normally bought from - a catalog-level
     * default only, purely informational. Purchases always record the
     * actual supplier independently via Purchase::supplier_id and never
     * read or write this field.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function inventoryAdjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }
}
