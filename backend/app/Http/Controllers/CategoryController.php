<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Transaction categories (the categories.type='expense' rows the "Other
 * Expense" form and BusinessReportService::profit()'s by-category
 * breakdown both read via transactions.category_id) - not to be confused
 * with ProductCategoryController, which manages a separate table for
 * product catalog grouping.
 */
class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');

        return response()->json(
            Category::query()
                ->where('is_active', true)
                ->when($type, fn ($query) => $query->where('type', $type))
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Only ever creates an expense category - the "Other Expense" form is
     * the only place this is called from today, and forcing the type here
     * (rather than trusting the request) keeps that true regardless of
     * what a caller sends.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->where('type', 'expense'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $category = Category::create([
            ...$validated,
            'type' => 'expense',
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }
}
