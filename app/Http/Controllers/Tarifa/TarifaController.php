<?php

namespace App\Http\Controllers\Tarifa;

use App\Http\Controllers\Controller;
use App\Models\Tarifa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TarifaController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Tarifa>
     */
    public function index(): LengthAwarePaginator
    {
        return Tarifa::with('produto')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): void
    {
        // Tarifa::create([
        //     'cidade_id' => 18,
        //     'produto_id' => 1
        //     ...
        // ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tarifa $tarifa): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tarifa $tarifa): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tarifa $tarifa): void
    {
        //
    }
}
