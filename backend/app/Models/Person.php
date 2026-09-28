<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'notes',
        'roles',
        'is_active',
            'customer_code',
        'credit_limit',
        'payment_terms_days',
        'is_customer',
        'is_supplier',
        'is_owner',
];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'is_active' => 'boolean',
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'is_owner' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'customer_id');
    }

    /**
     * The linked Supplier role-profile (credit_limit, payment_terms_days,
     * email, notes) for this Person, if they've been marked as a
     * supplier - see Supplier::provisionForPerson().
     */
    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }

}
