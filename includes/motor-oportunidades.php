<?php
/**
 * =========================================================================
 * MOTOR DE OPORTUNIDADES
 * =========================================================================
 * Automatizaciones sobre talos_opportunity: referencia de cotización,
 * defaults de fechas, filtro de Contacto por Empresa, y la conversión a
 * Cliente cuando la Etapa pasa a "Ganada".
 *
 * Nota sobre fechas (igual que en motor-recurrencia.php): al ESCRIBIR en
 * un campo ACF date_picker siempre se usa formato Ymd, sin importar el
 * return_format configurado para mostrarlo (aquí, Y-m-d). Al LEER, cada
 * campo regresa el formato que tenga configurado su return_format.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================================
 * DIAGNÓSTICO TEMPORAL — quitar en cuanto se resuelva por qué los hooks de
 * este archivo no estaban corriendo (sesión 2026-09-20). El error_log() no
 * llegó a ningún lado accesible en este hosting, así que esta versión pinta
 * la evidencia directo en pantalla dentro de wp-admin, sin depender de logs:
 *
 *  - Pie de página de TODO wp-admin: confirma que este archivo se incluyó
 *    en la petición (independiente de cualquier hook de ACF).
 *  - Aviso amarillo en la pantalla de edición de una Oportunidad: confirma
 *    si acf/save_post realmente disparó al guardar, y cuándo fue la
 *    última vez.
 * =========================================================================
 */
add_filter( 'admin_footer_text', function ( $texto ) {
    return $texto . ' — TALOS_OPP_DEBUG: motor-oportunidades.php cargado (' . current_time( 'H:i:s' ) . ')';
} );

$GLOBALS['talos_opp_traza'] = [];
function talos_opp_traza( $msg ) {
    $GLOBALS['talos_opp_traza'][] = $msg;
}

add_action( 'acf/save_post', function ( $post_id ) {
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        return;
    }
    update_option( '_talos_opp_debug_last_save', current_time( 'mysql' ) . ' (post_id=' . $post_id . ')' );

    // Captura el $_POST crudo ANTES de que nada más lo toque, para ver si el
    // navegador realmente mandó estos valores o si se pierden desde antes.
    $post_crudo = [
        'opportunity_stage (field_6aaf3faf9355c)'   => $_POST['acf']['field_6aaf3faf9355c'] ?? '(no vino en el POST)',
        'quote_created_date (field_6aaf409993561)'  => $_POST['acf']['field_6aaf409993561'] ?? '(no vino en el POST)',
        'opportunity_company (field_6aaf3f709355a)' => $_POST['acf']['field_6aaf3f709355a'] ?? '(no vino en el POST)',
    ];
    update_option( '_talos_opp_debug_post_crudo', $post_crudo );
}, 1 );

// Prioridad 999: corre AL FINAL de todos los hooks de este archivo, guarda
// la traza acumulada para poder mostrarla en el siguiente refresh de la
// pantalla (el admin_notices de este mismo request es DEMASIADO tarde para
// leerla, ya se está pintando el HTML antes de que save_post termine).
add_action( 'acf/save_post', function ( $post_id ) {
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        return;
    }
    update_option( '_talos_opp_debug_traza', $GLOBALS['talos_opp_traza'] );
}, 999 );

add_action( 'admin_notices', function () {
    global $post;
    if ( ! $post || 'talos_opportunity' !== get_post_type( $post ) ) {
        return;
    }
    $marca      = get_option( '_talos_opp_debug_last_save', 'NUNCA' );
    $traza      = get_option( '_talos_opp_debug_traza', [] );
    $filtro     = get_option( '_talos_opp_debug_filtro_contacto', 'NUNCA (el selector de Contacto no se ha abierto desde el último deploy)' );
    $post_crudo = get_option( '_talos_opp_debug_post_crudo', [] );
    echo '<div class="notice notice-warning"><p><strong>TALOS_OPP_DEBUG</strong> — última vez que acf/save_post corrió para una Oportunidad: ' . esc_html( $marca ) . '</p>';
    echo '<p><strong>Filtro de Contacto</strong> — última consulta: ' . esc_html( $filtro ) . '</p>';
    if ( $post_crudo ) {
        echo '<p><strong>$_POST crudo al momento de guardar:</strong></p><pre style="white-space:pre-wrap;">' . esc_html( var_export( $post_crudo, true ) ) . '</pre>';
    }
    if ( $traza ) {
        echo '<pre style="white-space:pre-wrap;">' . esc_html( implode( "\n", $traza ) ) . '</pre>';
    }
    echo '</div>';
} );

/**
 * 1. Referencia de cotización — se genera una sola vez, al crear.
 */
add_action( 'acf/save_post', 'talos_generar_referencia_cotizacion', 5 );
function talos_generar_referencia_cotizacion( $post_id ) {
    talos_opp_traza( '1) referencia: entrando, post_type=' . get_post_type( $post_id ) );
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        talos_opp_traza( '1) referencia: SALIDA por post_type' );
        return;
    }
    $actual = get_field( 'quote_reference', $post_id );
    talos_opp_traza( '1) referencia: valor actual = ' . var_export( $actual, true ) );
    if ( ! empty( $actual ) ) {
        talos_opp_traza( '1) referencia: SALIDA porque ya tenía valor' );
        return;
    }
    $nueva = 'COT-' . current_time( 'YmdHi' );
    $ok    = update_field( 'quote_reference', $nueva, $post_id );
    talos_opp_traza( '1) referencia: update_field(' . $nueva . ') devolvió ' . var_export( $ok, true ) );
}

/**
 * 2. Vigencia de la cotización — default a 30 días después de la fecha de
 * creación, solo si Jorge la dejó vacía (sigue siendo editable a mano).
 */
add_action( 'acf/save_post', 'talos_default_vigencia_cotizacion', 10 );
function talos_default_vigencia_cotizacion( $post_id ) {
    talos_opp_traza( '2) vigencia: entrando' );
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        talos_opp_traza( '2) vigencia: SALIDA por post_type' );
        return;
    }
    $actual = get_field( 'quote_valid_until', $post_id );
    talos_opp_traza( '2) vigencia: valor actual = ' . var_export( $actual, true ) );
    if ( ! empty( $actual ) ) {
        talos_opp_traza( '2) vigencia: SALIDA porque ya tenía valor' );
        return;
    }

    $creada = get_field( 'quote_created_date', $post_id ); // Y-m-d
    talos_opp_traza( '2) vigencia: quote_created_date = ' . var_export( $creada, true ) );
    if ( ! $creada ) {
        talos_opp_traza( '2) vigencia: SALIDA porque no hay fecha de creación' );
        return;
    }

    $fecha = DateTime::createFromFormat( 'Y-m-d', $creada );
    if ( ! $fecha ) {
        talos_opp_traza( '2) vigencia: SALIDA porque DateTime::createFromFormat falló' );
        return;
    }

    $fecha->modify( '+30 days' );
    $ok = update_field( 'quote_valid_until', $fecha->format( 'Ymd' ), $post_id );
    talos_opp_traza( '2) vigencia: update_field(' . $fecha->format( 'Ymd' ) . ') devolvió ' . var_export( $ok, true ) );
}

/**
 * 3. Fecha de envío — se marca la primera vez que "Enviada" se activa.
 * Si se desactiva y reactiva después, no se vuelve a pisar (queda la
 * fecha del primer envío real).
 */
add_action( 'acf/save_post', 'talos_marcar_fecha_envio_cotizacion', 10 );
function talos_marcar_fecha_envio_cotizacion( $post_id ) {
    talos_opp_traza( '3) fecha envio: entrando' );
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        talos_opp_traza( '3) fecha envio: SALIDA por post_type' );
        return;
    }
    $enviada = get_field( 'quote_sent', $post_id );
    talos_opp_traza( '3) fecha envio: quote_sent = ' . var_export( $enviada, true ) );
    if ( ! $enviada ) {
        talos_opp_traza( '3) fecha envio: SALIDA porque quote_sent es falsy' );
        return;
    }
    $actual = get_field( 'quote_sent_date', $post_id );
    talos_opp_traza( '3) fecha envio: valor actual = ' . var_export( $actual, true ) );
    if ( ! empty( $actual ) ) {
        talos_opp_traza( '3) fecha envio: SALIDA porque ya tenía valor' );
        return;
    }
    $ok = update_field( 'quote_sent_date', current_time( 'Ymd' ), $post_id );
    talos_opp_traza( '3) fecha envio: update_field devolvió ' . var_export( $ok, true ) );
}

/**
 * 4. Filtra el selector de Contacto para mostrar solo los contactos de la
 * Empresa ya elegida en esta Oportunidad.
 *
 * Limitación conocida: como se filtra leyendo el valor ya guardado de
 * opportunity_company, en un borrador nuevo sin guardar todavía no filtra
 * (muestra todos los contactos) — guarda una vez con la Empresa elegida y
 * el filtro entra en el siguiente refresh del selector.
 */
add_filter( 'acf/fields/post_object/query/key=field_6aaf40f993563', 'talos_filtrar_contactos_de_empresa', 10, 3 );
function talos_filtrar_contactos_de_empresa( $args, $field, $post_id ) {
    $empresa_id = (int) get_field( 'opportunity_company', $post_id, false );

    update_option( '_talos_opp_debug_filtro_contacto', current_time( 'mysql' ) . ' | post_id=' . var_export( $post_id, true ) . ' | empresa_id=' . $empresa_id );

    if ( $empresa_id ) {
        $args['meta_query'] = [
            [ 'key' => 'contact_company', 'value' => $empresa_id ],
        ];
    }

    return $args;
}

/**
 * 5. Conversión a Cliente — cuando la Etapa pasa a "Ganada": copia cada
 * fila de quote_items a company_services de la Empresa (colapsando el
 * descuento del concepto en el Precio final) y sube company_class a
 * "client". Idempotente vía meta interno _talos_opportunity_converted,
 * para no duplicar filas si la Oportunidad se vuelve a guardar.
 */
add_action( 'acf/save_post', 'talos_convertir_oportunidad_ganada', 20 );
function talos_convertir_oportunidad_ganada( $post_id ) {
    talos_opp_traza( '4) ganada: entrando' );
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        talos_opp_traza( '4) ganada: SALIDA por post_type' );
        return;
    }
    $etapa = get_field( 'opportunity_stage', $post_id );
    talos_opp_traza( '4) ganada: opportunity_stage = ' . var_export( $etapa, true ) );
    if ( 'ganada' !== $etapa ) {
        talos_opp_traza( '4) ganada: SALIDA porque etapa no es ganada' );
        return;
    }
    $ya_convertida = get_post_meta( $post_id, '_talos_opportunity_converted', true );
    talos_opp_traza( '4) ganada: _talos_opportunity_converted = ' . var_export( $ya_convertida, true ) );
    if ( $ya_convertida ) {
        talos_opp_traza( '4) ganada: SALIDA porque ya se había convertido' );
        return;
    }

    // format_value=false: traemos el ID crudo, listo para reescribirlo tal cual.
    $empresa_id = (int) get_field( 'opportunity_company', $post_id, false );
    talos_opp_traza( '4) ganada: empresa_id = ' . var_export( $empresa_id, true ) );
    if ( ! $empresa_id ) {
        talos_opp_traza( '4) ganada: SALIDA porque no hay empresa' );
        return;
    }

    $conceptos = get_field( 'quote_items', $post_id, false );
    talos_opp_traza( '4) ganada: quote_items = ' . var_export( $conceptos, true ) );
    if ( $conceptos ) {
        $servicios = get_field( 'company_services', $empresa_id ) ?: [];

        foreach ( $conceptos as $item ) {
            $servicios[] = [
                'service_item'                => $item['service_item'] ?? null,
                'service_invoice_description' => $item['service_invoice_description'] ?? '',
                'service_frequency'           => $item['service_frequency'] ?? '',
                'service_quantity'            => $item['service_quantity'] ?? 1,
                'service_price'               => talos_precio_final_concepto( $item ),
                'service_applies_iva'         => ! empty( $item['service_applies_iva'] ),
                'service_deferred'            => false,
                'service_deferred_months'     => 0,
                'service_cost'                => $item['service_cost'] ?? 0,
                'service_utility'             => 0, // se recalcula abajo
                'service_start_date'          => current_time( 'Ymd' ),
                'service_end_date'            => '',
                'service_status'              => true,
                'service_reminder_allowed'    => false,
            ];
        }

        $ok_servicios = update_field( 'company_services', $servicios, $empresa_id );
        talos_opp_traza( '4) ganada: update_field(company_services) devolvió ' . var_export( $ok_servicios, true ) );
        talos_calcular_utilidad_empresa( $empresa_id ); // reutiliza el cálculo ya existente
    }

    $ok_clase = update_field( 'company_class', 'client', $empresa_id );
    talos_opp_traza( '4) ganada: update_field(company_class) devolvió ' . var_export( $ok_clase, true ) );
    talos_sincronizar_empresa_contactos( $empresa_id ); // cascada manual: update_field() no dispara acf/save_post
    update_post_meta( $post_id, '_talos_opportunity_converted', 1 );
    talos_opp_traza( '4) ganada: TERMINÓ completo' );
}

/**
 * Aplica el descuento del concepto (si lleva) sobre su Precio, para dejar
 * el número final que pasa a Servicios — el descuento en sí no se arrastra
 * como dato permanente del contrato.
 */
function talos_precio_final_concepto( array $item ) {
    $precio = (float) ( $item['service_price'] ?? 0 );

    if ( empty( $item['item_has_discount'] ) ) {
        return $precio;
    }

    $valor = (float) ( $item['item_discount_value'] ?? 0 );

    if ( 'porcentaje' === ( $item['item_discount_type'] ?? '' ) ) {
        $precio -= $precio * ( $valor / 100 );
    } else {
        $precio -= $valor;
    }

    return max( 0, round( $precio, 2 ) );
}
