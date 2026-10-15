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
 *
 * Nota sobre get_field() con format_value=false: en un campo simple regresa
 * el valor crudo por su nombre sin problema (así se usa abajo con
 * opportunity_company). En un REPETIDOR, en cambio, regresa el array
 * indexado por field KEY en vez de por nombre de subcampo — confirmado en
 * pruebas reales. Por eso quote_items se lee con el formato normal
 * (format_value=true, el default) y el ID de service_item se extrae a mano
 * del WP_Post, igual que ya se hace con expense_team_member en
 * motor-recurrencia.php.
 *
 * El field group "Detalles de Oportunidad" originalmente agrupaba estos
 * campos dentro de bloques tipo "Group" de ACF — se detectó (sesión
 * 2026-09-20/10-06) que ACF no estaba procesando correctamente el guardado
 * de campos anidados en esos Group específicos (llegaban bien en $_POST
 * pero nunca se escribían en la base de datos). Se reconstruyó usando
 * campos tipo "Tab" en vez de "Group" para la separación visual — los Tabs
 * no anidan nada en el guardado, así que es inmune a ese problema.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Referencia de cotización — se genera una sola vez, al crear.
 */
add_action( 'acf/save_post', 'talos_generar_referencia_cotizacion', 5 );
function talos_generar_referencia_cotizacion( $post_id ) {
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        return;
    }
    if ( ! empty( get_field( 'quote_reference', $post_id ) ) ) {
        return;
    }
    update_field( 'quote_reference', 'COT-' . current_time( 'YmdHi' ), $post_id );
}

/**
 * 2. Vigencia de la cotización — default a 30 días después de la fecha de
 * creación, solo si Jorge la dejó vacía (sigue siendo editable a mano).
 */
add_action( 'acf/save_post', 'talos_default_vigencia_cotizacion', 10 );
function talos_default_vigencia_cotizacion( $post_id ) {
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        return;
    }
    if ( ! empty( get_field( 'quote_valid_until', $post_id ) ) ) {
        return;
    }

    $creada = get_field( 'quote_created_date', $post_id ); // Y-m-d
    if ( ! $creada ) {
        return;
    }

    $fecha = DateTime::createFromFormat( 'Y-m-d', $creada );
    if ( ! $fecha ) {
        return;
    }

    $fecha->modify( '+30 days' );
    update_field( 'quote_valid_until', $fecha->format( 'Ymd' ), $post_id );
}

/**
 * 3. Fecha de envío — se marca la primera vez que "Enviada" se activa.
 * Si se desactiva y reactiva después, no se vuelve a pisar (queda la
 * fecha del primer envío real).
 */
add_action( 'acf/save_post', 'talos_marcar_fecha_envio_cotizacion', 10 );
function talos_marcar_fecha_envio_cotizacion( $post_id ) {
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        return;
    }
    if ( ! get_field( 'quote_sent', $post_id ) ) {
        return;
    }
    if ( ! empty( get_field( 'quote_sent_date', $post_id ) ) ) {
        return;
    }
    update_field( 'quote_sent_date', current_time( 'Ymd' ), $post_id );
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

    if ( $empresa_id ) {
        $args['meta_query'] = [
            [ 'key' => 'contact_company', 'value' => $empresa_id ],
        ];
    }

    return $args;
}

/**
 * 5. Conversión a Cliente — cuando la Etapa pasa a "Ganada": crea un post
 * talos_company_service por cada fila de quote_items (colapsando el
 * descuento del concepto en el Precio final), ligado a la Empresa via
 * service_company, y sube company_class a "client". Idempotente vía meta
 * interno _talos_opportunity_converted, para no duplicar si la Oportunidad
 * se vuelve a guardar.
 *
 * Antes escribia al repetidor company_services; migrado a CPT propio
 * (talos_company_service) -- ver [[feedback_talos_no_repeaters]].
 */
add_action( 'acf/save_post', 'talos_convertir_oportunidad_ganada', 20 );
function talos_convertir_oportunidad_ganada( $post_id ) {
    if ( 'talos_opportunity' !== get_post_type( $post_id ) ) {
        return;
    }
    if ( 'ganada' !== get_field( 'opportunity_stage', $post_id ) ) {
        return;
    }
    if ( get_post_meta( $post_id, '_talos_opportunity_converted', true ) ) {
        return;
    }

    // format_value=false: traemos el ID crudo, listo para reescribirlo tal cual.
    $empresa_id = (int) get_field( 'opportunity_company', $post_id, false );
    if ( ! $empresa_id ) {
        return;
    }

    $conceptos = get_field( 'quote_items', $post_id );
    if ( $conceptos ) {
        foreach ( $conceptos as $item ) {
            // post_object regresa el WP_Post completo — extraemos el ID crudo a mano.
            $servicio_obj = $item['service_item'] ?? null;
            $servicio_id  = ( $servicio_obj instanceof WP_Post ) ? $servicio_obj->ID : (int) $servicio_obj;

            $nuevo_id = wp_insert_post( [
                'post_title'  => sprintf( '%s - Empresa #%d (desde Oportunidad #%d)', $item['service_invoice_description'] ?? 'Servicio', $empresa_id, $post_id ),
                'post_type'   => 'talos_company_service',
                'post_status' => 'publish',
            ] );
            if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
                continue;
            }

            update_field( 'service_company', $empresa_id, $nuevo_id );
            update_field( 'service_item', $servicio_id, $nuevo_id );
            update_field( 'service_invoice_description', $item['service_invoice_description'] ?? '', $nuevo_id );
            update_field( 'service_frequency', $item['service_frequency'] ?? '', $nuevo_id );
            update_field( 'service_quantity', $item['service_quantity'] ?? 1, $nuevo_id );
            update_field( 'service_price', talos_precio_final_concepto( $item ), $nuevo_id );
            update_field( 'service_applies_iva', ! empty( $item['service_applies_iva'] ), $nuevo_id );
            update_field( 'service_deferred', false, $nuevo_id );
            update_field( 'service_deferred_months', 0, $nuevo_id );
            update_field( 'service_cost', $item['service_cost'] ?? 0, $nuevo_id );
            update_field( 'service_start_date', current_time( 'Ymd' ), $nuevo_id );
            update_field( 'service_end_date', '', $nuevo_id );
            update_field( 'service_status', true, $nuevo_id );
            update_field( 'service_reminder_allowed', false, $nuevo_id );
            talos_calcular_utilidad_servicio_contratado( $nuevo_id ); // reutiliza el calculo ya existente
        }
    }

    update_field( 'company_class', 'client', $empresa_id );
    talos_sincronizar_empresa_contactos( $empresa_id ); // cascada manual: update_field() no dispara acf/save_post
    update_post_meta( $post_id, '_talos_opportunity_converted', 1 );
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
