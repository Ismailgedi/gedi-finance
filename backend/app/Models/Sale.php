<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'sale_date',
        'due_date',
        'subtotal',
        'discount',
        'total',
        'cost_of_goods_sold',
        'gross_profit',
        'amount_paid',
        'balance_due',
        'payment_status',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_of_goods_sold' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
