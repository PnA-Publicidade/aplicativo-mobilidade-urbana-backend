<?php

namespace App\Http\Controllers\Passageiro;

use App\Http\Controllers\Controller;
use App\Models\Passageiro;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class PassageiroController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Passageiro>
     */
    public function index(): LengthAwarePaginator
    {
        return Passageiro::with('user')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): void
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Passageiro $passageiro): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Passageiro $passageiro): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Passageiro $passageiro): void
    {
        //
    }

    /**
     * @return LengthAwarePaginator<int, Passageiro>
     */
    public function passageirosArquivados(): LengthAwarePaginator
    {
        return Passageiro::onlyTrashed()
            ->latest('updated_at')
            ->paginate();
    }
}
