<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_number',
        'type',
        'person_id',
        'account_id',
        'destination_account_id',
        'category_id',
        'loan_id',
        'supplier_id',
        'sale_id',
        'purchase_id',
        'amount',
        'currency',
        'person_balance_effect',
        'account_balance_effect',
        'destination_account_effect',
        'supplier_balance_effect',
        'description',
        'reference',
        'transaction_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'person_balance_effect' => 'decimal:2',
            'account_balance_effect' => 'decimal:2',
            // Cast so the transfer "From -> To" pair on the receipt (and any
            // other display of this field) formats consistently with the
            // other signed effect columns above. Purely a display/formatting
            // cast - it does not change the stored value or any balance
            // calculation, which all already work in floats/decimals.
            'destination_account_effect' => 'decimal:2',
            'supplier_balance_effect' => 'decimal:2',
            'transaction_date' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'destination_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }


    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
