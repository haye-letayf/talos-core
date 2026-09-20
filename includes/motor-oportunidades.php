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
 * 5. Conversión a Cliente — cuando la Etapa pasa a "Ganada": copia cada
 * fila de quote_items a company_services de la Empresa (colapsando el
 * descuento del concepto en el Precio final) y sube company_class a
 * "client". Idempotente vía meta interno _talos_opportunity_converted,
 * para no duplicar filas si la Oportunidad se vuelve a guardar.
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

    $conceptos = get_field( 'quote_items', $post_id, false );
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

        update_field( 'company_services', $servicios, $empresa_id );
        talos_calcular_utilidad_empresa( $empresa_id ); // reutiliza el cálculo ya existente
    }

    update_field( 'company_class', 'client', $empresa_id );
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
