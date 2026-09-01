<?php

namespace App\Http\Controllers\Produto;

use App\Http\Controllers\Controller;
use App\Models\ProdutoCategoria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ProdutoCategoriaController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, ProdutoCategoria>
     */
    public function index(): LengthAwarePaginator
    {
        return ProdutoCategoria::with('produto')->paginate();
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
    public function show(ProdutoCategoria $produtoCategoria): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProdutoCategoria $produtoCategoria): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProdutoCategoria $produtoCategoria): void
    {
        //
    }
}
