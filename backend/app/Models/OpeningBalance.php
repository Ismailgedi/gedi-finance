<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class OpeningBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'as_of_date',
        'opening_equity',
        'status',
        'created_by',
        'locked_at',
        'locked_by',
        'reopened_at',
        'reopened_by',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'opening_equity' => 'decimal:2',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpeningBalanceItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * Once a locked OpeningBalance exists, nothing may be dated on or
     * before its as_of_date - that boundary is exactly what "opening
     * balance" means (the position immediately before Gedi Finance's own
     * transaction/inventory history begins). Originally implemented only
     * inside TransactionService::assertNotBeforeOpeningBalance() (a plain
     * query there rather than injecting this model's own service, since
     * OpeningBalanceService itself depends on TransactionService - see
     * that method's own doc comment); moved here as the single shared
     * check once InventoryAdjustmentService needed the exact same rule
     * (see the accounting audit's H2 finding) - a second, duplicated copy
     * of this query would have been exactly the kind of drift-prone
     * duplication the audit warned about.
     */
    public static function assertDateNotBeforeLock(string|CarbonInterface $date): void
    {
        $locked = static::query()->where('status', 'locked')->first();

        if (!$locked) {
            return;
        }

        $normalized = $date instanceof CarbonInterface ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');
        // Eloquent's date cast serializes as_of_date with a full datetime
        // format when saved (e.g. "2026-01-01 00:00:00" on SQLite) - both
        // sides are normalized to Y-m-d before comparing.
        $asOfDate = Carbon::parse($locked->as_of_date)->format('Y-m-d');

        if ($normalized <= $asOfDate) {
            throw ValidationException::withMessages([
                'transaction_date' => "This date ({$normalized}) is on or before the locked Opening Balance date "
                    . "({$asOfDate}). No transaction or inventory adjustment may be dated on or before the "
                    . 'opening balance - a Super Admin must reopen and correct the Opening Balance instead.',
            ]);
        }
    }
}
