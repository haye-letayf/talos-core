<?php
/**
 * =========================================================================
 * SEED: AMPLIACIÓN DE REGLAS AMEX (basada en histórico ya clasificado)
 * =========================================================================
 * Se ejecuta UNA SOLA VEZ:
 *  1. Corrige 2 reglas existentes (SERVICIO LA QUINTA pasa de auto_transporte
 *     a gasolina; UBER TRIPS pierde la S para matchear los conceptos reales).
 *  2. Agrega 51 reglas nuevas extraídas del histórico clasificado a mano en
 *     Google Sheets. AMAZON WEB SERVICES va antes que AMAZON a propósito —
 *     el motor de clasificación se queda con la primera regla que matchee.
 *
 * Requiere que las subcategorías "gastos_generales" y "educacion" ya
 * existan como choices en expense_subcategory y rule_subcategory (eso se
 * agrega a mano en ACF, un script no puede tocar la definición del campo).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'talos_ampliar_reglas_amex_v1' );
function talos_ampliar_reglas_amex_v1() {

    if ( get_option( 'talos_reglas_amex_ampliacion_v1' ) ) {
        return;
    }

    if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
        return;
    }

    $reglas = get_field( 'amex_classification_rules', 'option' );
    if ( ! $reglas ) {
        return; // las reglas base todavía no existen, no hay nada que ampliar
    }

    // --- 1. Correcciones a reglas existentes ---
    foreach ( $reglas as $index => $fila ) {
        if ( 'SERVICIO LA QUINTA' === $fila['rule_keyword'] ) {
            $reglas[ $index ]['rule_category']    = 'personal';
            $reglas[ $index ]['rule_subcategory'] = 'gasolina';
        }
        if ( 'UBER TRIPS' === $fila['rule_keyword'] ) {
            $reglas[ $index ]['rule_keyword'] = 'UBER TRIP';
        }
    }

    // --- 2. Reglas nuevas ---
    $nuevas_crudas = [
        [ 'ARBY', 'personal', 'alimentos_bebidas' ],
        [ 'CASEYS', 'personal', 'alimentos_bebidas' ],
        [ 'COFFEE CUP', 'personal', 'alimentos_bebidas' ],
        [ 'CROW NATION', 'personal', 'alimentos_bebidas' ],
        [ 'EL 9 ARGENTINO', 'personal', 'alimentos_bebidas' ],
        [ 'ESPERANZA JESUS DEL MO', 'personal', 'alimentos_bebidas' ],
        [ 'FP SUNSHINE MARKET', 'personal', 'alimentos_bebidas' ],
        [ 'KRISPY KREME', 'personal', 'alimentos_bebidas' ],
        [ "LOVE'S", 'personal', 'alimentos_bebidas' ],
        [ 'MAPCO', 'personal', 'alimentos_bebidas' ],
        [ "MCDONALD'S", 'personal', 'alimentos_bebidas' ],
        [ 'SUBWAY', 'personal', 'alimentos_bebidas' ],
        [ 'SUSHI TEPPAN', 'personal', 'alimentos_bebidas' ],
        [ 'TAGERS', 'personal', 'alimentos_bebidas' ],
        [ 'TH PASEO INTERLOMAS', 'personal', 'alimentos_bebidas' ],
        [ 'TH SAMARA', 'personal', 'alimentos_bebidas' ],
        [ 'WALGREENS', 'personal', 'alimentos_bebidas' ],
        [ 'WHATABURGER', 'personal', 'alimentos_bebidas' ],
        [ 'WINGSTOP', 'personal', 'alimentos_bebidas' ],
        [ 'LDR S.P.A', 'personal', 'deporte' ],
        [ 'SC INTERLOMAS', 'personal', 'deporte' ],
        [ 'SPORTS WORLD', 'personal', 'deporte' ],
        [ 'WUZI*VERTEX', 'personal', 'deporte' ],
        [ 'COLE ANGLO', 'personal', 'educacion' ],
        [ 'COLEGIO AMERICAN', 'personal', 'educacion' ],
        [ 'CINEMEX', 'personal', 'entretenimiento' ],
        [ 'GRUPO TAMBRE', 'personal', 'gasolina' ],
        [ 'F 130 JESUS DEL MONTE', 'personal', 'gasolina' ],
        [ 'OPERADORA Y ADMINISTRAD', 'personal', 'gasolina' ],
        [ 'SERVICIO ALICANTE', 'personal', 'gasolina' ],
        [ 'ALBOA', 'personal', 'gastos_familiares' ],
        [ 'HASBRO', 'personal', 'gastos_familiares' ],
        [ 'KIDZANIA', 'personal', 'gastos_familiares' ],
        [ 'AMAZON WEB SERVICES', 'operacion', 'servidores_hosting' ], // antes que AMAZON, a propósito
        [ 'AMAZON', 'personal', 'gastos_generales' ],
        [ 'NATURA', 'personal', 'gastos_generales' ],
        [ 'PINTURAS MAR', 'personal', 'gastos_generales' ],
        [ 'FERR FESTER', 'personal', 'gastos_generales' ],
        [ 'MERCADO LIBRE', 'personal', 'gastos_generales' ],
        [ 'MUDANGOMEXI', 'personal', 'gastos_generales' ],
        [ 'SALTOK', 'personal', 'gastos_generales' ],
        [ 'ANTHROPIC', 'operacion', 'licencias_software' ],
        [ 'CANVA', 'operacion', 'licencias_software' ],
        [ 'FLUENTFORM', 'operacion', 'licencias_software' ],
        [ 'MOLLIE', 'operacion', 'licencias_software' ],
        [ 'BLUEHOST', 'operacion', 'servidores_hosting' ],
        [ 'LINKEDIN', 'operacion', 'publicidad' ],
        [ 'LIVE WELL', 'operacion', 'publicidad' ],
        [ 'SEITON', 'personal', 'ropa_calzado' ],
        [ 'ZOHO', 'personal', 'suscripciones' ],
        [ 'HOSTERIA LAS QUINTAS', 'personal', 'viajes_hospedaje' ],
    ];

    foreach ( $nuevas_crudas as $r ) {
        $reglas[] = [
            'rule_keyword'     => $r[0],
            'rule_category'    => $r[1],
            'rule_subcategory' => $r[2],
        ];
    }

    update_field( 'amex_classification_rules', $reglas, 'option' );

    update_option( 'talos_reglas_amex_ampliacion_v1', true );
}
