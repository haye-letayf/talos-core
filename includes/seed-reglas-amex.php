<?php
/**
 * =========================================================================
 * SEED: REGLAS DE CLASIFICACIÓN AMEX
 * =========================================================================
 * Inyecta, UNA SOLA VEZ, las 123 reglas de clasificación que Jorge ya tenía
 * probadas en las fórmulas REGEXMATCH de Google Sheets, dentro del
 * repetidor `amex_classification_rules` de la Página de Opciones
 * "Reglas AMEX".
 *
 * El orden de las reglas SÍ importa: el motor de clasificación (a construir
 * después) va a revisar de arriba hacia abajo y quedarse con la primera que
 * haga match. Por eso reglas más específicas van antes que las genéricas
 * relacionadas, ej. "OXXO GAS" antes que "OXXO", "COMISION FEDERAL" antes
 * que "COMISION". Si agregas reglas nuevas parecidas a estas, ponlas en el
 * mismo orden (específica primero, genérica después).
 *
 * Nota: "MAX" (Suscripciones) es una palabra muy corta/genérica — vigila si
 * empieza a clasificar mal algún concepto que contenga "MAX" por casualidad.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'talos_inyectar_reglas_amex' );
function talos_inyectar_reglas_amex() {

    // Candado: si ya se ejecutó una vez, no se repite.
    if ( get_option( 'talos_reglas_amex_importadas_v1' ) ) {
        return;
    }

    // No hacer nada si la Página de Opciones / ACF todavía no están listas.
    if ( ! function_exists( 'update_field' ) ) {
        return;
    }

    // Formato de cada renglón: [ palabra clave, categoría, subcategoría ]
    $reglas_crudas = [
        [ 'PASE', 'personal', 'casetas' ],
        [ 'GAS ALMUDENA', 'personal', 'gasolina' ],
        [ 'GASOLINERA', 'personal', 'gasolina' ],
        [ 'PEMEX', 'personal', 'gasolina' ],
        [ 'OXXO GAS', 'personal', 'gasolina' ],
        [ 'SHELL', 'personal', 'gasolina' ],
        [ 'BP', 'personal', 'gasolina' ],
        [ 'G500', 'personal', 'gasolina' ],
        [ 'NISSAN SUC', 'personal', 'auto_transporte' ],
        [ 'SERVICIO LA QUINTA', 'personal', 'auto_transporte' ],
        [ 'UBER TRIPS', 'personal', 'auto_transporte' ],
        [ 'DIDI T', 'personal', 'auto_transporte' ],
        [ 'CABIFY', 'personal', 'auto_transporte' ],
        [ 'CAFE SIRENA', 'personal', 'alimentos_bebidas' ],
        [ 'MC DONALDS', 'personal', 'alimentos_bebidas' ],
        [ 'LAS BRASAS', 'personal', 'alimentos_bebidas' ],
        [ 'REST', 'personal', 'alimentos_bebidas' ],
        [ 'RESTAURANT', 'personal', 'alimentos_bebidas' ],
        [ 'TOKS', 'personal', 'alimentos_bebidas' ],
        [ 'VIPS', 'personal', 'alimentos_bebidas' ],
        [ 'UBER EATS', 'personal', 'alimentos_bebidas' ],
        [ 'CASA TONO', 'personal', 'alimentos_bebidas' ],
        [ 'RAPPI', 'personal', 'alimentos_bebidas' ],
        [ 'DIDI FOOD', 'personal', 'alimentos_bebidas' ],
        [ 'OXXO', 'personal', 'alimentos_bebidas' ],
        [ '7 ELEVEN', 'personal', 'alimentos_bebidas' ],
        [ 'ITALIANNI', 'personal', 'alimentos_bebidas' ],
        [ 'BARBACOA', 'personal', 'alimentos_bebidas' ],
        [ 'ALTAVISTA 05', 'personal', 'alimentos_bebidas' ],
        [ 'JUSTO 7E', 'personal', 'alimentos_bebidas' ],
        [ 'COM RAP', 'personal', 'alimentos_bebidas' ],
        [ 'GO MART', 'personal', 'alimentos_bebidas' ],
        [ 'IHOP', 'personal', 'alimentos_bebidas' ],
        [ 'MAS CAFE', 'personal', 'alimentos_bebidas' ],
        [ 'TORINO', 'personal', 'alimentos_bebidas' ],
        [ 'COSTCO', 'personal', 'super' ],
        [ 'CHEDRAUI', 'personal', 'super' ],
        [ 'SUPERCENTER', 'personal', 'super' ],
        [ 'SUPERAMA', 'personal', 'super' ],
        [ 'GETJUSTOMX', 'personal', 'super' ],
        [ 'WALMART', 'personal', 'super' ],
        [ 'LA COMER', 'personal', 'super' ],
        [ 'HEB', 'personal', 'super' ],
        [ 'CITY MARKET', 'personal', 'super' ],
        [ 'FRESKO', 'personal', 'super' ],
        [ 'APPLE', 'personal', 'suscripciones' ],
        [ 'DISNEY', 'personal', 'suscripciones' ],
        [ 'NETFLIX', 'personal', 'suscripciones' ],
        [ 'SPOTIFY', 'personal', 'suscripciones' ],
        [ 'YOUTUBE', 'personal', 'suscripciones' ],
        [ 'MAX', 'personal', 'suscripciones' ],
        [ 'TELCEL', 'personal', 'telefonia' ],
        [ 'AT&T', 'personal', 'telefonia' ],
        [ 'MOVISTAR', 'personal', 'telefonia' ],
        [ 'COMISION FEDERAL', 'personal', 'servicios_hogar' ],
        [ 'CFE', 'personal', 'servicios_hogar' ],
        [ 'SPORT CITY', 'personal', 'deporte' ],
        [ 'VIVE NADANDO', 'personal', 'deporte' ],
        [ 'DECATHLON', 'personal', 'deporte' ],
        [ 'ADAMANTA', 'personal', 'deporte' ],
        [ 'BACKCOUNTRY', 'personal', 'deporte' ],
        [ 'COPPEL PLAZA', 'personal', 'deporte' ],
        [ 'RABBIT MOUNTAIN', 'personal', 'deporte' ],
        [ 'FARMACIA SAN PABLO', 'personal', 'medicamentos' ],
        [ 'FARMACIA', 'personal', 'medicamentos' ],
        [ 'GUADALAJARA', 'personal', 'medicamentos' ],
        [ 'AHORRO', 'personal', 'medicamentos' ],
        [ 'BENAVIDES', 'personal', 'medicamentos' ],
        [ 'FACEBK', 'operacion', 'publicidad' ],
        [ 'FACEBOOK', 'operacion', 'publicidad' ],
        [ 'INDEED', 'operacion', 'publicidad' ],
        [ 'AKKY', 'operacion', 'dominios_web' ],
        [ 'GODADDY', 'operacion', 'dominios_web' ],
        [ 'HOSTINGER', 'operacion', 'dominios_web' ],
        [ 'CPANEL', 'operacion', 'servidores_hosting' ],
        [ 'LIQUID WEB', 'operacion', 'servidores_hosting' ],
        [ 'AWS', 'operacion', 'servidores_hosting' ],
        [ 'DIGITALOCEAN', 'operacion', 'servidores_hosting' ],
        [ 'ELEGANTTHEMES', 'operacion', 'software_plugins' ],
        [ 'CLIN BALAN', 'personal', 'consultas_medicas' ],
        [ 'JOTFORM', 'operacion', 'licencias_software' ],
        [ 'MAILCHIMP', 'operacion', 'licencias_software' ],
        [ 'ADOBE', 'operacion', 'licencias_software' ],
        [ 'OPENAI', 'operacion', 'licencias_software' ],
        [ 'PAYU-GOOGLE', 'operacion', 'licencias_software' ],
        [ 'REPORTING NINJA', 'operacion', 'licencias_software' ],
        [ 'MAKE.COM', 'operacion', 'licencias_software' ],
        [ 'WOO-', 'operacion', 'licencias_software' ],
        [ 'ENVATO', 'operacion', 'licencias_software' ],
        [ 'FLUENTBOOKING', 'operacion', 'licencias_software' ],
        [ 'FOOSALES', 'operacion', 'licencias_software' ],
        [ 'HUBSPOT', 'operacion', 'licencias_software' ],
        [ 'METLIFE', 'personal', 'seguros' ],
        [ 'MUEVE CIUDAD', 'personal', 'estacionamientos' ],
        [ 'ESTACIONAMIENTO', 'personal', 'estacionamientos' ],
        [ 'PARKING', 'personal', 'estacionamientos' ],
        [ 'COPEMSA', 'personal', 'estacionamientos' ],
        [ 'TELMEX', 'personal', 'internet' ],
        [ 'IZZI', 'personal', 'internet' ],
        [ 'TOTALPLAY', 'personal', 'internet' ],
        [ 'MEGACABLE', 'personal', 'internet' ],
        [ 'AEROMEXICO', 'personal', 'viajes_hospedaje' ],
        [ 'VOLARIS', 'personal', 'viajes_hospedaje' ],
        [ 'VIVA AEROBUS', 'personal', 'viajes_hospedaje' ],
        [ 'AIRBNB', 'personal', 'viajes_hospedaje' ],
        [ 'HOTEL', 'personal', 'viajes_hospedaje' ],
        [ 'PAGO TARD', 'personal', 'comisiones_bancarias' ],
        [ 'IVA APLICABLE', 'personal', 'comisiones_bancarias' ],
        [ 'COMISION', 'personal', 'comisiones_bancarias' ],
        [ 'ANUALIDAD', 'personal', 'comisiones_bancarias' ],
        [ 'CUOTA', 'personal', 'comisiones_bancarias' ],
        [ 'INTERES', 'personal', 'comisiones_bancarias' ],
        [ 'AMERICAN EAGLE', 'personal', 'ropa_calzado' ],
        [ 'LIV INSURGENTE', 'personal', 'ropa_calzado' ],
        [ 'ZARA', 'personal', 'ropa_calzado' ],
        [ 'H&M', 'personal', 'ropa_calzado' ],
        [ 'LIVERPOOL', 'personal', 'ropa_calzado' ],
        [ 'PALACIO DE HIERRO', 'personal', 'ropa_calzado' ],
        [ 'INDITEX', 'personal', 'ropa_calzado' ],
        [ 'JUGUETRON', 'personal', 'gastos_familiares' ],
        [ 'CINEPOLIS', 'personal', 'entretenimiento' ],
        [ 'MUSEOINTERA', 'personal', 'entretenimiento' ],
        [ 'TOKALON', 'personal', 'cuidado_personal' ],
        [ 'BARBER', 'personal', 'cuidado_personal' ],
    ];

    $filas = [];
    foreach ( $reglas_crudas as $r ) {
        $filas[] = [
            'rule_keyword'     => $r[0],
            'rule_category'    => $r[1],
            'rule_subcategory' => $r[2],
        ];
    }

    update_field( 'amex_classification_rules', $filas, 'option' );

    // Cerramos el candado para siempre.
    update_option( 'talos_reglas_amex_importadas_v1', true );
}
