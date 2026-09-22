<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Cidade;
use App\Models\StatusBusca;
use Illuminate\Http\Request;

class CidadeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Cidade::get();
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
    public function show(StatusBusca $statusBusca): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, StatusBusca $statusBusca): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StatusBusca $statusBusca): void
    {
        //
    }
}
