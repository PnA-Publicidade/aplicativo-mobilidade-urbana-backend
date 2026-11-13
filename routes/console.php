<?php

// CODEX: 9 linhas alteradas; avaliação, embarque e cancelamentos. Remover após validação.

use App\Services\Compartilhada\GrupoCompartilhadoService;
use App\Services\Compartilhada\SalaCompartilhadaService;
use App\Services\DespachoCorridaService;
use App\Services\PagamentoCorridaService;
use App\Services\RestricaoTaxaService;
use App\Services\TaxasMotoristaService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(DespachoCorridaService::class)->cancelarEsperasExpiradas())
    ->name('corridas:cancelar-esperas-expiradas')
    ->everyTenSeconds()
    ->withoutOverlapping();

// pré-pagos (Pix/cartão) não pagos no prazo: cancela sem cobrança
// a cada 15 s: o prazo de pagamento do grupo compartilhado é de 3 min
Schedule::call(fn () => app(PagamentoCorridaService::class)->expirarPagamentosVencidos())
    ->name('corridas:expirar-pagamentos')
    ->everyFifteenSeconds()
    ->withoutOverlapping();

// salas compartilhadas: fecha grupos prontos e expira as vencidas
Schedule::call(fn () => app(SalaCompartilhadaService::class)->processar())
    ->name('compartilhada:processar-salas')
    ->everyFiveSeconds()
    ->withoutOverlapping();

// fim da escada de despacho: grupo sem motorista vira corrida normal para cada um
Schedule::call(fn () => app(GrupoCompartilhadoService::class)->dissolverSemMotorista())
    ->name('compartilhada:dissolver-sem-motorista')
    ->everyTenSeconds()
    ->withoutOverlapping();

// estornos que ficaram sem resposta da AbacatePay
Schedule::call(fn () => app(PagamentoCorridaService::class)->reprocessarEstornosPendentes())
    ->name('pagamentos:reprocessar-estornos')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// taxas dos motoristas: chamada que tocou e ficou sem resposta vira tempo esgotado
Schedule::call(fn () => app(TaxasMotoristaService::class)->fecharChamadasSemResposta())
    ->name('taxas:fechar-chamadas-sem-resposta')
    ->everyTenSeconds()
    ->withoutOverlapping();

// retrato diário das taxas, com o dia anterior já completo
Schedule::command('taxas:fechar-dia')
    ->name('taxas:fechar-dia')
    ->dailyAt('00:10')
    ->withoutOverlapping();

// restrições por taxa de finalização: prazos vencidos viram restrição e as vencidas terminam
Schedule::call(function () {
    $restricoes = app(RestricaoTaxaService::class);
    $restricoes->processarPrazos();
    $restricoes->encerrarRestricoesVencidas();
})
    ->name('taxas:processar-restricoes')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// a taxa muda também quando eventos saem da janela de 30 dias
Schedule::call(fn () => app(RestricaoTaxaService::class)->varrer())
    ->name('taxas:varrer-restricoes')
    ->dailyAt('00:20')
    ->withoutOverlapping();
