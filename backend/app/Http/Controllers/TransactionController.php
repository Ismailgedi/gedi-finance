<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    public function index(): JsonResponse
    {
        $transactions = Transaction::query()
            ->with(['person', 'account', 'destinationAccount', 'category', 'supplier', 'sale', 'purchase', 'items'])
            ->latest('transaction_date')
            ->paginate(25);

        return response()->json($transactions);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create(
            $request->validated(),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Transaction created successfully.',
            'transaction' => $transaction,
        ], 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json(
            $transaction->load(['person', 'account', 'destinationAccount', 'category', 'loan', 'supplier', 'sale', 'purchase', 'items', 'attachments'])
        );
    }
}
