<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('banner_publicidades', function (Blueprint $table) {
            $table->id();
            $table->integer('cidade_id');
            $table->string('titulo');
            $table->string('name');
            $table->string('type');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('path');
            $table->string('url');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banner_publicidades');
    }
};
