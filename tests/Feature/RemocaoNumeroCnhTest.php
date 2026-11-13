<?php

use App\Models\Motorista;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::table('motoristas', function (Blueprint $table) {
        $table->string('cnh_numero')->nullable();
    });
    $this->migration = require database_path('migrations/2026_10_06_000003_remove_cnh_numero_from_motoristas_table.php');
});

it('preserva numeros antigos sem sobrescrever registros preenchidos e remove a coluna', function () {
    $vazio = Motorista::factory()->create();
    $preenchido = Motorista::factory()->create(['numero_registro' => '00012345678']);
    DB::table('motoristas')->where('id', $vazio->id)->update(['cnh_numero' => ' 00123456789 ']);
    DB::table('motoristas')->where('id', $preenchido->id)->update(['cnh_numero' => '00987654321']);
    $vazio->delete();

    $this->migration->up();

    expect(Schema::hasColumn('motoristas', 'cnh_numero'))->toBeFalse();
    $this->assertDatabaseHas('motoristas', ['id' => $vazio->id, 'numero_registro' => '00123456789']);
    $this->assertDatabaseHas('motoristas', ['id' => $preenchido->id, 'numero_registro' => '00012345678']);
});

it('rollback restaura a coluna a partir do numero de registro', function () {
    $motorista = Motorista::factory()->create(['numero_registro' => '00123456789']);
    $this->migration->up();
    $this->migration->down();

    expect(Schema::hasColumn('motoristas', 'cnh_numero'))->toBeTrue();
    $this->assertDatabaseHas('motoristas', ['id' => $motorista->id, 'cnh_numero' => '00123456789']);
});

it('recusa a remocao quando um valor antigo excede o limite sem truncar dados', function () {
    $motorista = Motorista::factory()->create();
    DB::table('motoristas')->where('id', $motorista->id)->update(['cnh_numero' => str_repeat('1', 21)]);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'mais de 20 caracteres');
    expect(Schema::hasColumn('motoristas', 'cnh_numero'))->toBeTrue();
    $this->assertDatabaseHas('motoristas', [
        'id' => $motorista->id,
        'cnh_numero' => str_repeat('1', 21),
        'numero_registro' => null,
    ]);
});
