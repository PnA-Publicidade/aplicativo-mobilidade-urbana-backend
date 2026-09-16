<?php

namespace App\Http\Controllers\Publicidade;

use App\Http\Requests\StoreBannerPublicidadeRequest;
use App\Http\Requests\UpdateBannerPublicidadeRequest;
use App\Models\BannerPublicidade;
use App\Http\Controllers\Controller;

class BannerPublicidadeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return BannerPublicidade::get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBannerPublicidadeRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(BannerPublicidade $bannerPublicidade)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBannerPublicidadeRequest $request, BannerPublicidade $bannerPublicidade)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BannerPublicidade $bannerPublicidade)
    {
        //
    }
}
