<?php

namespace App\Http\Controllers\Produto;

use App\Http\Controllers\Controller;
use App\Models\ProdutosCorrida;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ProdutosCorridaController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, ProdutosCorrida>
     */
    public function index(): LengthAwarePaginator
    {
        return ProdutosCorrida::paginate();
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
    public function show(ProdutosCorrida $produtosCorrida): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProdutosCorrida $produtosCorrida): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProdutosCorrida $produtosCorrida): void
    {
        //
    }
}
