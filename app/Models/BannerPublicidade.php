<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BannerPublicidade extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'titulo',
        'name',
        'type',
        'mime_type',
        'size',
        'path',
        'url',
    ];
}
