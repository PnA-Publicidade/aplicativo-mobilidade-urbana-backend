<?php

namespace App\Models;

use Database\Factories\BannerPublicidadeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $titulo
 * @property string $name nome original do arquivo enviado
 * @property string $type extensão
 * @property string $mime_type
 * @property int $size bytes
 * @property string $path caminho no disco config('publicidade.disco')
 * @property-read string $url
 */
class BannerPublicidade extends Model
{
    /** @use HasFactory<BannerPublicidadeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'titulo',
        'name',
        'type',
        'mime_type',
        'size',
        'path',
    ];

    /**
     * A URL sai do disco na hora: trocar de host ou de bucket não deixa
     * endereços velhos gravados no banco.
     *
     * @var list<string>
     */
    protected $appends = ['url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * As cidades onde o app mostra o banner. Sem nenhuma, ele não aparece.
     *
     * @return BelongsToMany<Cidade, $this>
     */
    public function cidades(): BelongsToMany
    {
        return $this->belongsToMany(Cidade::class, 'banner_publicidade_cidade')->withTimestamps();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(
            fn (): string => Storage::disk((string) config('publicidade.disco'))->url($this->path)
        );
    }
}
