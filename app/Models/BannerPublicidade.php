<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BannerPublicidade extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'cidade_id',
        'titulo',
        'foto',
        'foto_thumbnail',
    ];
}
