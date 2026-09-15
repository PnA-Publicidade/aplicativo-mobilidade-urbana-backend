<?php

return [
    'valor_por_km' => (float) env('PRECIFICACAO_VALOR_POR_KM', 1.50),
    'valor_por_minuto' => (float) env('PRECIFICACAO_VALOR_POR_MINUTO', 0.25),
    'taxa_plataforma_percentual' => (float) env('PRECIFICACAO_TAXA_PLATAFORMA', 0.06),

    'distancia_maxima_km' => (float) env('PRECIFICACAO_DISTANCIA_MAXIMA_KM', 5000),
    'tempo_maximo_min' => (float) env('PRECIFICACAO_TEMPO_MAXIMO_MIN', 10080),

    'taxa_plataforma_maxima' => 0.95,

    'raio_busca_padrao_km' => (float) env('PRECIFICACAO_RAIO_BUSCA_PADRAO_KM', 5),

    'cotacao_validade_minutos' => (int) env('PRECIFICACAO_COTACAO_VALIDADE_MIN', 10),
];
