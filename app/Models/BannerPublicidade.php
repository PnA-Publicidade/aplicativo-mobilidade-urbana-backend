<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BannerPublicidade extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $fillable = [
        'cidade_id',
        'titulo',
        'name',
        'type',
        'mime_type',
        'size',
        'path',
        'url'
    ];

    public function cidade()
    {
        return $this->belongsTo(Cidade::class);
    }
}
