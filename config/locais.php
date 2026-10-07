<?php

return [
    // "Em alta" na busca de destino: lugares mais pedidos perto de quem busca
    'populares' => [
        'raio_km' => (float) env('LOCAIS_POPULARES_RAIO_KM', 15),
        'dias' => (int) env('LOCAIS_POPULARES_DIAS', 30),
        // abaixo disso o lugar pode ser a casa de alguém, e não aparece
        'minimo_passageiros' => (int) env('LOCAIS_POPULARES_MINIMO_PASSAGEIROS', 3),
        'limite' => (int) env('LOCAIS_POPULARES_LIMITE', 3),
    ],
];
