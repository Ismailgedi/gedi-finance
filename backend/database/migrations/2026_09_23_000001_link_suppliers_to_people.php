<?php

use App\Models\Person;
use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the Business Contact (people) record the canonical identity for
 * suppliers, the same way it already is for customers (sales.customer_id
 * has always pointed straight at people - there is no separate customers
 * table). Purchases/transactions keep pointing at suppliers.id unchanged;
 * suppliers becomes a one-to-one role profile (credit_limit,
 * payment_terms_days, email, notes - genuinely supplier-specific fields)
 * linked to people via a new nullable person_id.
 *
 * Backfill strategy (safe on both a clean database and the current
 * production one):
 *
 *   1. Any existing Person whose `roles` array already contains
 *      "supplier" (the exact state this fixes - a Business Contact
 *      marked as a supplier with nothing backing it) gets is_supplier
 *      set true. Existing rows are never deleted or merged.
 *
 *   2. Any existing Supplier row without a person_id is linked to a
 *      Person by exact, case-insensitive name match - but only when
 *      that match is UNAMBIGUOUS (exactly one active Person with that
 *      name). An ambiguous or missing match never guesses: a new Person
 *      is created instead, carrying the supplier's own name/phone/
 *      address, and the supplier is linked to that new Person. Either
 *      way, no existing supplier_id on any purchase/transaction ever
 *      changes - only a link is added.
 *
 *   3. Any Person left with is_supplier = true but no linked Supplier
 *      row (the Faarax case) gets one auto-created, so they become
 *      immediately selectable in Purchases without anyone having to
 *      re-enter them on the separate Suppliers screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->boolean('is_supplier')->default(false)->after('is_customer');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignId('person_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('people')
                ->nullOnDelete();
        });

        // Step 1: backfill is_supplier from the existing cosmetic `roles`
        // tag array - the only signal that exists today for a Business
        // Contact already marked "supplier" with nothing behind it.
        DB::table('people')->orderBy('id')->chunkById(200, function ($people): void {
            foreach ($people as $row) {
                $roles = json_decode((string) $row->roles, true) ?? [];

                if (in_array('supplier', array_map('strtolower', $roles), true)) {
                    DB::table('people')->where('id', $row->id)->update(['is_supplier' => true]);
                }
            }
        });

        // Step 2: link existing Supplier rows to a Person - never guess
        // across an ambiguous (non-unique) name match.
        Supplier::query()->whereNull('person_id')->orderBy('id')->each(function (Supplier $supplier): void {
            $matches = Person::query()->whereRaw('lower(name) = ?', [mb_strtolower($supplier->name)])->get();

            if ($matches->count() === 1) {
                $person = $matches->first();
                $person->is_supplier = true;
                $roles = $person->roles ?? [];
                if (! in_array('supplier', $roles, true)) {
                    $roles[] = 'supplier';
                }
                $person->roles = $roles;
                $person->save();
            } else {
                // No match, or more than one Person shares this name -
                // do not guess which one (if any) is really this
                // supplier. Create a dedicated Person instead, so the
                // supplier's own historical purchases keep their
                // supplier_id untouched while still gaining a canonical
                // identity.
                $person = Person::create([
                    'name' => $supplier->name,
                    'phone' => $supplier->phone,
                    'address' => $supplier->address,
                    'roles' => ['supplier'],
                    'is_active' => $supplier->is_active,
                    'is_customer' => false,
                    'is_supplier' => true,
                ]);
            }

            $supplier->person_id = $person->id;
            $supplier->save();
        });

        // Step 3: the actual fix - any Person marked as a supplier who
        // still has no linked Supplier profile gets one, so they are
        // immediately selectable in Purchases.
        Person::query()
            ->where('is_supplier', true)
            ->whereDoesntHave('supplier')
            ->orderBy('id')
            ->each(function (Person $person): void {
                Supplier::provisionForPerson($person);
            });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('person_id');
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('is_supplier');
        });
    }
};
