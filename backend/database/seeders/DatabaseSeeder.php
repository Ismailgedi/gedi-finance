<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Every permission in the system, grouped by business area purely for
     * readability here - Spatie stores them as a flat, ungrouped table.
     * Kept deliberately short (business-area granularity, not one
     * permission per controller action) so the four roles below stay
     * something a non-technical admin can actually reason about.
     *
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        'view_dashboard',
        'view_products', 'manage_products',
        'view_inventory', 'manage_inventory',
        'view_customers', 'manage_customers',
        'view_suppliers', 'manage_suppliers',
        'create_sales', 'view_sales', 'void_sales',
        'create_purchases', 'view_purchases', 'void_purchases',
        'view_accounts', 'manage_accounts',
        'view_transactions', 'manage_transactions',
        'view_financial_reports', 'view_sales_reports', 'view_purchase_reports', 'view_inventory_reports',
        'manage_users', 'manage_roles', 'view_audit_logs',
        'manage_opening_balances', 'manage_financial_year',
    ];

    /**
     * The role -> permission matrix. Super Admin is handled separately
     * below (every permission that exists, always - see its own comment)
     * rather than listed here, so a newly added permission can never be
     * silently left out of Super Admin's access by someone forgetting to
     * add it to this array too.
     *
     * There is deliberately no separate "view/manage_loans" permission:
     * there is no dedicated Loan controller/route (see
     * database/migrations/2026_09_13_000004_create_loans_table.php's
     * actual usage) - "Loans & Debts" is just Transactions against a
     * Person, so access to it is already view_transactions/
     * manage_transactions + view_customers/view_suppliers, and a separate
     * permission here would gate nothing a route actually checks.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_PERMISSIONS = [
        // The legacy role every account held before this permission
        // system existed. No longer assigned to any NEW account (see the
        // four roles below instead), but kept at Manager's exact
        // permission set rather than deleted or left at zero permissions -
        // many existing feature tests (and potentially a real pre-existing
        // account) rely on "an ordinary User" being able to do every
        // day-to-day action (sales, purchases, payments, expenses, loans,
        // inventory adjustments) that wasn't already Super-Admin-only
        // under the old flat role:Super Admin-only gate, and this is the
        // smallest change that keeps every one of those exactly working.
        'User' => [
            'view_dashboard',
            'view_products', 'manage_products',
            'view_inventory', 'manage_inventory',
            'view_customers', 'manage_customers',
            'view_suppliers', 'manage_suppliers',
            'create_sales', 'view_sales',
            'create_purchases', 'view_purchases',
            'view_accounts', 'manage_accounts',
            'view_transactions', 'manage_transactions',
            'view_financial_reports', 'view_sales_reports', 'view_purchase_reports', 'view_inventory_reports',
        ],
        // manage_accounts here only ever reaches AccountController::update()
        // - renaming/retyping an account (e.g. fixing a typo in its display
        // name). It can never touch opening_balance (deliberately excluded
        // from that endpoint's own validated fields) or create/delete an
        // account (no such routes exist) - genuinely master-data upkeep,
        // the same category as the manage_products/manage_suppliers/
        // manage_customers Manager already has, not a financial-balance
        // capability (that's view_accounts, separately gated).
        'Manager' => [
            'view_dashboard',
            'view_products', 'manage_products',
            'view_inventory', 'manage_inventory',
            'view_customers', 'manage_customers',
            'view_suppliers', 'manage_suppliers',
            'create_sales', 'view_sales',
            'create_purchases', 'view_purchases',
            'view_accounts', 'manage_accounts',
            'view_transactions', 'manage_transactions',
            'view_financial_reports', 'view_sales_reports', 'view_purchase_reports', 'view_inventory_reports',
        ],
        'Finance' => [
            'view_dashboard',
            'view_customers', 'manage_customers',
            'view_suppliers', 'manage_suppliers',
            'view_sales', 'view_purchases',
            'view_accounts', 'manage_accounts',
            'view_transactions', 'manage_transactions',
            'view_financial_reports', 'view_sales_reports', 'view_purchase_reports',
        ],
        'Sales & Inventory' => [
            'view_products', 'manage_products',
            'view_inventory', 'manage_inventory',
            'create_sales', 'view_sales',
            'view_customers', 'manage_customers',
        ],
    ];

    /**
     * Just the roles/permissions half of seeding, with no ADMIN_PASSWORD
     * requirement and no other side effects (accounts/categories/units) -
     * pulled out so feature tests that build their own fixtures (most of
     * this suite predates this permission system and creates its roles
     * directly) can get a real, permission-bearing role the exact same
     * way production does, instead of each test re-deriving its own
     * permission list by hand.
     */
    public static function seedRolesAndPermissions(): void
    {
        // Roles are required for the "role"/"permission" route middleware
        // and the Admin\UserController's `exists:roles,name` validation to
        // work at all. Without this, no one can hold "Super Admin" and
        // User Management is unreachable. Idempotent so re-seeding is
        // safe.
        foreach (['Super Admin', 'User', 'Manager', 'Finance', 'Sales & Inventory'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        // Super Admin always gets every permission that exists, full stop -
        // never a hand-maintained list that a future permission could be
        // left off of by accident. syncPermissions() is idempotent: running
        // this again with the same (or a grown) permission list leaves
        // Super Admin with exactly that set, no duplicates.
        Role::findByName('Super Admin', 'web')->syncPermissions(self::PERMISSIONS);

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::findByName($roleName, 'web')->syncPermissions($permissions);
        }

        // The original "User" role predates this permission system (see
        // ROLE_PERMISSIONS['User']'s own comment above) and is no longer
        // assigned to any new account - only the four roles above are.
    }

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

        self::seedRolesAndPermissions();

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

        // Optional second Super Admin (Ismail). Unlike the primary admin
        // above, a missing password here does not fail the seed - this is
        // an additional account, not the one required to administer the
        // app at all, so environments that haven't provisioned it yet
        // should still get Dad + the reference data below. No source-code
        // default password: set ISMAIL_ADMIN_PASSWORD explicitly to
        // provision (or reset) this account.
        $ismailPassword = env('ISMAIL_ADMIN_PASSWORD');

        if (blank($ismailPassword)) {
            $this->command?->warn(
                'ISMAIL_ADMIN_PASSWORD not set - skipping the Ismail Super Admin account.'
            );
        } else {
            $ismail = User::updateOrCreate(
                ['email' => env('ISMAIL_ADMIN_EMAIL', 'i.gedi99@gmail.com')],
                [
                    'name' => 'Ismail',
                    'password' => $ismailPassword,
                    'is_active' => true,
                ],
            );

            if (! $ismail->hasRole('Super Admin')) {
                $ismail->assignRole('Super Admin');
            }
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
