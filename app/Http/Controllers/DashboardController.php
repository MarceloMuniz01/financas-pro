<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $transactions = Transaction::where('user_id', $userId)
            ->latest()
            ->limit(10)
            ->get();

        $total = Transaction::where('user_id', $userId)
            ->sum('amount');

        return Inertia::render('Dashboard', [
            'transactions' => $transactions,
            'total' => $total,
        ]);
    }
}