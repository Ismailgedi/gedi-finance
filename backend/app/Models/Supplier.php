<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'person_id',
        'supplier_code',
        'name',
        'phone',
        'email',
        'address',
        'credit_limit',
        'payment_terms_days',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * The canonical Business Contact this supplier profile belongs to.
     * name/phone/address are kept in sync from this Person in both
     * directions (see PersonController and SupplierController) -
     * credit_limit, payment_terms_days, email and notes stay
     * supplier-specific and live only here.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Get-or-create the Supplier profile for a Person, so marking someone
     * a supplier makes them immediately selectable in Purchases without
     * a separate manual "Add Supplier" step. Idempotent: calling this
     * again for a Person who already has a profile just returns it
     * unchanged (name/phone/address syncing for an existing profile is
     * the caller's responsibility - see PersonController::update()).
     */
    public static function provisionForPerson(Person $person): self
    {
        $existing = static::query()->where('person_id', $person->id)->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'person_id' => $person->id,
            'supplier_code' => static::nextSupplierCode(),
            'name' => $person->name,
            'phone' => $person->phone,
            'address' => $person->address,
            'is_active' => true,
        ]);
    }

    public static function nextSupplierCode(): string
    {
        $prefix = 'SUP-';
        $last = static::query()
            ->where('supplier_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('supplier_code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
