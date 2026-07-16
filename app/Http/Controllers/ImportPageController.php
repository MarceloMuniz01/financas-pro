<?php

namespace App\Http\Controllers;

use App\Models\Import;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImportPageController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $imports = Import::query()
            ->where(
                'user_id',
                $request->user()->id
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render(
            'imports/index',
            [
                'imports' => $imports,
            ]
        );
    }
}