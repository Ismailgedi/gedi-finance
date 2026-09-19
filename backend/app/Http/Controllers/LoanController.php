<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use Illuminate\Http\JsonResponse;

class LoanController extends Controller
{
    public function index(): JsonResponse
    {
        $loans = Loan::query()
            ->with('person')
            ->latest('start_date')
            ->paginate(25);

        return response()->json($loans);
    }
}
