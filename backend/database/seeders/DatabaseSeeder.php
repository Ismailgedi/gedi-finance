<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // No fallback password. A default here would mean every fresh
        // environment (including a real production deploy) starts with a
        // publicly known credential until someone remembers to change it -
        // fail loudly, before any other seeding happens, instead. The
        // exception message intentionally never includes the offending
        // value.
        $adminPassword = env('ADMIN_PASSWORD');

        if (blank($adminPassword)) {
            throw new RuntimeException(
                'ADMIN_PASSWORD must be set before seeding the Super Admin account. Refusing to seed with a default or empty password.'
            );
        }

        // Roles are required for the "role" route middleware and the
        // Admin\UserController's `exists:roles,name` validation to work at
        // all. Without this, no one can hold "Super Admin" and User
        // Management is unreachable. Idempotent so re-seeding is safe.
        foreach (['Super Admin', 'User'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        $admin = User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@gedi.finance')],
            [
                'name' => env('ADMIN_NAME', 'Dad'),
                'password' => $adminPassword,
                'is_active' => true,
            ],
        );

        if (! $admin->hasRole('Super Admin')) {
            $admin->assignRole('Super Admin');
        }

        $accounts = [
            ['name' => 'Cash', 'type' => 'cash'],
            ['name' => 'Bank', 'type' => 'bank'],
            ['name' => 'EVC', 'type' => 'mobile_money'],
            ['name' => 'eDahab', 'type' => 'mobile_money'],
            ['name' => 'JEEB', 'type' => 'mobile_money'],
        ];

        foreach ($accounts as $account) {
            Account::updateOrCreate(['name' => $account['name']], [
                ...$account,
                'currency' => 'USD',
                'opening_balance' => 0,
                'is_active' => true,
            ]);
        }

        $categories = [
            ['name' => 'Wholesale Sales', 'type' => 'income', 'description' => 'Wholesale sales and customer payments.'],
            ['name' => 'Other Income', 'type' => 'income', 'description' => 'Income not classified as wholesale sales.'],
            ['name' => 'Stock Purchase', 'type' => 'expense', 'description' => 'Purchasing goods for resale.'],
            ['name' => 'Transport', 'type' => 'expense', 'description' => 'Business transportation and delivery.'],
            ['name' => 'Rent', 'type' => 'expense', 'description' => 'Premises or storage rent.'],
            ['name' => 'Utilities', 'type' => 'expense', 'description' => 'Water, electricity, and related utilities.'],
            ['name' => 'Salary', 'type' => 'expense', 'description' => 'Employee or worker payments.'],
            ['name' => 'Other Expense', 'type' => 'expense', 'description' => 'Other business expenses.'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['name' => $category['name'], 'type' => $category['type']],
                $category + ['is_active' => true],
            );
        }

        // Gedi Finance sells wholesale grains by the bag, not the individual
        // piece - "Bag" needs to exist as a selectable unit out of the box
        // rather than requiring every business to create it manually via
        // the inline "+ New unit" form the first time they add a product.
        // Kg/Piece stay available for products that genuinely need them.
        $units = [
            ['name' => 'Bag', 'abbreviation' => 'bag'],
            ['name' => 'Kilogram', 'abbreviation' => 'kg'],
            ['name' => 'Piece', 'abbreviation' => 'pc'],
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(['name' => $unit['name']], $unit);
        }
    }
}
