<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'return_number',
        'return_date',
        'reason',
        'settlement_method',
        'refund_account_id',
        'total_quantity',
        'total_value',
        'total_cost',
        'applied_to_receivable',
        'refund_amount',
        'credited_amount',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'total_quantity' => 'decimal:4',
            'total_value' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'applied_to_receivable' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function refundAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'refund_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
