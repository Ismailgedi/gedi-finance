<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialYearClose extends Model
{
    use HasFactory;

    protected $fillable = [
        'financial_year',
        'period_start_date',
        'period_end_date',
        'opening_equity',
        'profit_loss',
        'other_income',
        'owner_contributions',
        'owner_withdrawals',
        'closing_equity',
        'assets',
        'liabilities',
        'cash_accounts',
        'inventory_breakdown',
        'status',
        'created_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'opening_equity' => 'decimal:2',
            'profit_loss' => 'decimal:2',
            'other_income' => 'decimal:2',
            'owner_contributions' => 'decimal:2',
            'owner_withdrawals' => 'decimal:2',
            'closing_equity' => 'decimal:2',
            'assets' => 'array',
            'liabilities' => 'array',
            'cash_accounts' => 'array',
            'inventory_breakdown' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
