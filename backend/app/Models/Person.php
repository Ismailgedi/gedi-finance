<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'is_active' => 'boolean',
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_customer' => 'boolean',
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

}
