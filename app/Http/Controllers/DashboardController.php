<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        $transactions = Transaction::query()
            ->where('user_id', $userId)
            ->orderByDesc('transaction_date')
            ->limit(10)
            ->get();

        $total = Transaction::query()
            ->where('user_id', $userId)
            ->sum('amount');

        return Inertia::render('dashboard', [
            'transactions' => $transactions,
            'total' => $total,
        ]);
    }
}
