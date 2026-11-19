<?php

return [
    // fila de prioridade ligada por padrão; a gestão pode desligar. Os testes
    // de montagem de ofertas rodam com ela desligada (DESPACHO_FILA_ATIVA=false)
    'fila_ativa' => (bool) env('DESPACHO_FILA_ATIVA', true),
];
