<?php

return [
    'valor_por_km' => (float) env('PRECIFICACAO_VALOR_POR_KM', 1.50),
    'valor_por_minuto' => (float) env('PRECIFICACAO_VALOR_POR_MINUTO', 0.25),
    'taxa_plataforma_percentual' => (float) env('PRECIFICACAO_TAXA_PLATAFORMA', 0.06),

    'distancia_maxima_km' => (float) env('PRECIFICACAO_DISTANCIA_MAXIMA_KM', 5000),
    'tempo_maximo_min' => (float) env('PRECIFICACAO_TEMPO_MAXIMO_MIN', 10080),

    'taxa_plataforma_maxima' => 0.95,

    'raio_busca_padrao_km' => (float) env('PRECIFICACAO_RAIO_BUSCA_PADRAO_KM', 5),
    'intervalo_expansao_raio_segundos' => (int) env('PRECIFICACAO_INTERVALO_EXPANSAO_RAIO_SEGUNDOS', 30),
    'incremento_raio_busca_km' => (float) env('PRECIFICACAO_INCREMENTO_RAIO_BUSCA_KM', 2),
    'raio_busca_maximo_km' => (float) env('PRECIFICACAO_RAIO_BUSCA_MAXIMO_KM', 20),

    'motorista_online_expira_segundos' => (int) env('MOTORISTA_ONLINE_EXPIRA_SEGUNDOS', 90),

    'cotacao_validade_minutos' => (int) env('PRECIFICACAO_COTACAO_VALIDADE_MIN', 10),

    'espera_tolerancia_segundos' => (int) env('PRECIFICACAO_ESPERA_TOLERANCIA_SEGUNDOS', 120),
    'espera_limite_cobranca_segundos' => (int) env('PRECIFICACAO_ESPERA_LIMITE_COBRANCA_SEGUNDOS', 720),
];
