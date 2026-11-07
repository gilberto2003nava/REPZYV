<?php
function test($cantidadFrascos, $totalRecetasAhora)
{
    echo "Probando con Total: $cantidadFrascos y Recetas: $totalRecetasAhora\n";
    $resultados = [];
    for ($i = 0; $i < $totalRecetasAhora; $i++) {
        if ($i < $totalRecetasAhora - 1) {
            $cantEsta = 20;
        } else {
            $cantEsta = $cantidadFrascos - ($i * 20);
            if ($cantEsta < 1) $cantEsta = 1;
        }
        $resultados[] = "Receta $i (Folio " . ($i + 1) . "): $cantEsta frascos";
    }
    echo implode("\n", $resultados) . "\n";
    echo "Suma total: " . array_sum(array_map('intval', explode(':', implode(':', $resultados)))) . " (esto es manual, mejor sumamos las variables)\n";

    $sumaVerdadera = 0;
    for ($i = 0; $i < $totalRecetasAhora; $i++) {
        if ($i < $totalRecetasAhora - 1) {
            $c = 20;
        } else {
            $c = $cantidadFrascos - ($i * 20);
            if ($c < 1) $c = 1;
        }
        $sumaVerdadera += $c;
    }
    echo "SUMA REAL: $sumaVerdadera\n\n";
}

test(45, 3);
test(15, 3);
test(25, 2);
test(25, 3);
