<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owners are Business Contacts too, marked with a boolean flag - the same
 * pattern is_customer/is_supplier already use (see
 * 2026_09_23_000001_link_suppliers_to_people.php) - rather than a separate
 * identity table. There is no "owner profile" with role-specific fields the
 * way Supplier has credit_limit/payment_terms_days, so no linked profile
 * table is needed: a Person with is_owner=true can immediately be selected
 * as the owner on an owner_contribution/owner_withdrawal transaction
 * (TransactionType::OwnerContribution/OwnerWithdrawal).
 *
 * Purely additive: defaults to false for every existing row, so no
 * existing Person becomes an owner and no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->boolean('is_owner')->default(false)->after('is_supplier');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('is_owner');
        });
    }
};
