<?php
/**
 * =========================================================================
 * MOTOR DE RECURRENCIA
 * =========================================================================
 * Puerto de generarIngresos()/generarGastos() de Talos 1.0 (Apps Script),
 * con dos mejoras deliberadas sobre el original:
 *
 *  - Verifica que un registro no exista ya para esa empresa+servicio+periodo
 *    (o esa plantilla+periodo) antes de crearlo. En 1.0 correr "Generar Mes"
 *    dos veces duplicaba todas las filas sin avisar.
 *  - Calcula correctamente las 6 frecuencias del catálogo (Mensual,
 *    Quincenal, Semanal, Trimestral, Semestral, Anual). En 1.0 solo Mensual
 *    y Anual realmente disparaban una cobranza; el resto existía como
 *    opción en el dropdown pero nunca generaba nada solo.
 *
 * Nota sobre fechas: al ESCRIBIR en un campo ACF date_picker siempre se usa
 * formato Ymd — así es como ACF lo guarda internamente sin importar el
 * "return_format" configurado para mostrarlo. Al LEER, cada campo regresa
 * el formato que tenga configurado su return_format (por eso las fechas de
 * Servicios vienen en d/m/Y y las de Gastos en Y-m-d) — hay que parsear
 * cada quien con su propio formato, nunca asumir uno solo.
 *
 * Este archivo solo define funciones. No se ejecuta nada automáticamente
 * todavía — el disparador manual ("Generar Mes") se agrega aparte.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================================
 * HELPERS DE FECHA
 * =========================================================================
 */

function talos_parse_fecha_acf( $valor, $formato ) {
    if ( empty( $valor ) ) {
        return null;
    }
    $fecha = DateTime::createFromFormat( $formato, $valor );
    return $fecha ?: null;
}

function talos_periodo_vencido( ?DateTime $fecha_fin, DateTime $mes_destino ) {
    if ( ! $fecha_fin ) {
        return false; // sin fecha de fin = nunca vence
    }
    $fin_mes     = ( clone $fecha_fin )->modify( 'first day of this month' )->setTime( 0, 0 );
    $destino_mes = ( clone $mes_destino )->modify( 'first day of this month' )->setTime( 0, 0 );
    return $destino_mes > $fin_mes;
}

/**
 * Devuelve un array de DateTime — las fechas dentro de $mes_destino en las
 * que $frecuencia "cae", a partir del ancla $fecha_inicio.
 *
 * Convenciones:
 *  - Quincenal: día 1 y día 16 del mes (nómina MX estándar), no un ciclo
 *    rodante de 14 días desde la fecha de inicio.
 *  - Semanal: ciclo rodante real de 7 días desde $fecha_inicio, reportando
 *    cada corte que caiga dentro del mes destino (puede ser 4 o 5 al mes).
 *  - Trimestral/Semestral/Anual: se ancla al mes de $fecha_inicio y solo
 *    dispara cuando la diferencia en meses contra $mes_destino es múltiplo
 *    exacto del ciclo.
 *  - Pago Único: nunca la genera el motor, se captura a mano.
 */
function talos_fechas_cobro_en_periodo( $frecuencia, DateTime $fecha_inicio, DateTime $mes_destino ) {
    $fechas = [];

    $primer_dia_mes = ( clone $mes_destino )->modify( 'first day of this month' )->setTime( 0, 0 );
    $ultimo_dia_mes = ( clone $mes_destino )->modify( 'last day of this month' )->setTime( 0, 0 );
    $inicio_norm    = ( clone $fecha_inicio )->setTime( 0, 0 );

    if ( $inicio_norm > $ultimo_dia_mes ) {
        return $fechas; // todavía no arranca en este periodo
    }

    switch ( $frecuencia ) {

        case 'mensual':
            $fechas[] = clone $primer_dia_mes;
            break;

        case 'quincenal':
            $fechas[] = clone $primer_dia_mes;
            $dia16 = ( clone $primer_dia_mes )->modify( '+15 days' );
            if ( $dia16 <= $ultimo_dia_mes ) {
                $fechas[] = $dia16;
            }
            break;

        case 'semanal':
            $cursor = clone $inicio_norm;
            while ( $cursor < $primer_dia_mes ) {
                $cursor->modify( '+7 days' );
            }
            while ( $cursor <= $ultimo_dia_mes ) {
                $fechas[] = clone $cursor;
                $cursor->modify( '+7 days' );
            }
            break;

        case 'trimestral':
        case 'semestral':
        case 'anual':
            $meses_ciclo = [ 'trimestral' => 3, 'semestral' => 6, 'anual' => 12 ][ $frecuencia ];
            $diff_meses  = ( ( (int) $mes_destino->format( 'Y' ) - (int) $fecha_inicio->format( 'Y' ) ) * 12 )
                         + ( (int) $mes_destino->format( 'n' ) - (int) $fecha_inicio->format( 'n' ) );
            if ( $diff_meses >= 0 && $diff_meses % $meses_ciclo === 0 ) {
                $fechas[] = clone $primer_dia_mes;
            }
            break;

        case 'pago_unico':
        default:
            break;
    }

    return $fechas;
}

/**
 * Réplica de la leyenda "(Pago X de Y)" de Talos 1.0: solo aparece cuando
 * el servicio tiene fecha de inicio Y fin definidas (un contrato de
 * duración fija), contando el mes actual dentro de esa ventana.
 */
function talos_leyenda_pago_x_de_y( ?DateTime $inicio, ?DateTime $fin, DateTime $mes_destino ) {
    if ( ! $inicio || ! $fin ) {
        return '';
    }

    $total_meses = ( ( (int) $fin->format( 'Y' ) - (int) $inicio->format( 'Y' ) ) * 12 )
                 + ( (int) $fin->format( 'n' ) - (int) $inicio->format( 'n' ) ) + 1;

    $mes_actual = ( ( (int) $mes_destino->format( 'Y' ) - (int) $inicio->format( 'Y' ) ) * 12 )
                + ( (int) $mes_destino->format( 'n' ) - (int) $inicio->format( 'n' ) ) + 1;

    if ( $total_meses > 1 && $mes_actual >= 1 && $mes_actual <= $total_meses ) {
        return sprintf( '(Pago %d de %d)', $mes_actual, $total_meses );
    }

    return '';
}

/**
 * =========================================================================
 * GENERADOR DE INCOME (Empresas -> company_services -> talos_income)
 * =========================================================================
 */

function talos_income_ya_existe( $empresa_id, $servicio_id, DateTime $fecha_cobro ) {
    $existentes = get_posts( [
        'post_type'      => 'talos_income',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => 'income_company', 'value' => $empresa_id ],
            [ 'key' => 'income_service', 'value' => $servicio_id ],
            [ 'key' => 'income_month', 'value' => $fecha_cobro->format( 'Ymd' ) ],
        ],
    ] );
    return ! empty( $existentes );
}

function talos_crear_income_desde_servicio( $empresa_id, array $fila, $servicio_id, DateTime $fecha_cobro, ?DateTime $inicio, ?DateTime $fin, DateTime $mes_destino ) {
    $cantidad   = (float) ( $fila['service_quantity'] ?: 1 );
    $precio     = (float) ( $fila['service_price'] ?: 0 );
    $subtotal   = $cantidad * $precio;
    $aplica_iva = ! empty( $fila['service_applies_iva'] );
    $total      = $aplica_iva ? round( $subtotal * 1.16, 2 ) : $subtotal;
    $tipo_doc   = $aplica_iva ? 'factura' : 'nota_venta';

    $descripcion = (string) ( $fila['service_invoice_description'] ?: '' );
    $leyenda     = talos_leyenda_pago_x_de_y( $inicio, $fin, $mes_destino );
    if ( $leyenda ) {
        $descripcion = trim( $descripcion . ' ' . $leyenda );
    }

    $nuevo_id = wp_insert_post( [
        'post_title'  => sprintf( 'Income %s - Empresa #%d', $fecha_cobro->format( 'Y-m-d' ), $empresa_id ),
        'post_type'   => 'talos_income',
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
        return;
    }

    update_field( 'income_company', $empresa_id, $nuevo_id );
    update_field( 'income_service', $servicio_id, $nuevo_id );
    update_field( 'income_description', $descripcion, $nuevo_id );
    update_field( 'income_month', $fecha_cobro->format( 'Ymd' ), $nuevo_id );
    update_field( 'income_quantity', $cantidad, $nuevo_id );
    update_field( 'income_unit_price', $precio, $nuevo_id );
    update_field( 'income_applies_iva', $aplica_iva, $nuevo_id );
    update_field( 'income_subtotal', $subtotal, $nuevo_id );
    update_field( 'income_total', $total, $nuevo_id );
    update_field( 'income_doc_type', $tipo_doc, $nuevo_id );
    update_field( 'income_sent', false, $nuevo_id );
    update_field( 'income_paid', false, $nuevo_id );
    update_field( 'income_reminder_allowed', ! empty( $fila['service_reminder_allowed'] ), $nuevo_id );
}

function talos_generar_income_del_periodo( DateTime $mes_destino ) {
    $creados      = 0;
    $desactivados = 0;

    $empresas = get_posts( [
        'post_type'      => 'talos_company',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ] );

    foreach ( $empresas as $empresa ) {
        $servicios = get_field( 'company_services', $empresa->ID );
        if ( ! $servicios ) {
            continue;
        }

        $hubo_cambios = false;

        foreach ( $servicios as $index => $fila ) {
            if ( empty( $fila['service_status'] ) ) {
                continue;
            }

            $fecha_fin = talos_parse_fecha_acf( $fila['service_end_date'] ?? '', 'd/m/Y' );
            if ( talos_periodo_vencido( $fecha_fin, $mes_destino ) ) {
                $servicios[ $index ]['service_status'] = 0;
                $hubo_cambios = true;
                continue;
            }

            $fecha_inicio = talos_parse_fecha_acf( $fila['service_start_date'] ?? '', 'd/m/Y' );
            if ( ! $fecha_inicio ) {
                continue; // sin ancla no se puede calcular recurrencia
            }

            $servicio_obj = $fila['service_item'] ?? null;
            $servicio_id  = ( $servicio_obj instanceof WP_Post ) ? $servicio_obj->ID : (int) $servicio_obj;
            if ( ! $servicio_id ) {
                continue;
            }

            $fechas_cobro = talos_fechas_cobro_en_periodo( $fila['service_frequency'], $fecha_inicio, $mes_destino );

            foreach ( $fechas_cobro as $fecha_cobro ) {
                if ( talos_income_ya_existe( $empresa->ID, $servicio_id, $fecha_cobro ) ) {
                    continue;
                }
                talos_crear_income_desde_servicio( $empresa->ID, $fila, $servicio_id, $fecha_cobro, $fecha_inicio, $fecha_fin, $mes_destino );
                $creados++;
            }
        }

        if ( $hubo_cambios ) {
            update_field( 'company_services', $servicios, $empresa->ID );
            $desactivados++;
        }
    }

    return [ 'income_creados' => $creados, 'servicios_desactivados' => $desactivados ];
}

/**
 * =========================================================================
 * GENERADOR DE GASTOS (Plantillas recurrentes -> Transacciones)
 * =========================================================================
 */

function talos_gasto_ya_existe( $plantilla_id, DateTime $fecha_periodo ) {
    $existentes = get_posts( [
        'post_type'      => 'talos_expense',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => 'expense_source_template', 'value' => $plantilla_id ],
            [ 'key' => 'expense_period', 'value' => $fecha_periodo->format( 'Ymd' ) ],
        ],
    ] );
    return ! empty( $existentes );
}

function talos_crear_transaccion_desde_plantilla( $plantilla_id, DateTime $fecha_periodo ) {
    $nuevo_id = wp_insert_post( [
        'post_title'  => get_the_title( $plantilla_id ) . ' - ' . $fecha_periodo->format( 'Y-m' ),
        'post_type'   => 'talos_expense',
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
        return;
    }

    // format_value=false: traemos el ID crudo del post_object para reescribirlo tal cual.
    $team_member = get_field( 'expense_team_member', $plantilla_id, false );

    update_field( 'expense_type', 'transaccion', $nuevo_id );
    update_field( 'expense_category', get_field( 'expense_category', $plantilla_id ), $nuevo_id );
    update_field( 'expense_subcategory', get_field( 'expense_subcategory', $plantilla_id ), $nuevo_id );
    update_field( 'expense_supplier', get_field( 'expense_supplier', $plantilla_id ), $nuevo_id );
    update_field( 'expense_team_member', $team_member, $nuevo_id );
    update_field( 'expense_description', get_field( 'expense_description', $plantilla_id ), $nuevo_id );
    update_field( 'expense_amount', get_field( 'expense_amount', $plantilla_id ), $nuevo_id );
    update_field( 'expense_frequency', get_field( 'expense_frequency', $plantilla_id ), $nuevo_id );
    update_field( 'expense_period', $fecha_periodo->format( 'Ymd' ), $nuevo_id );
    update_field( 'expense_source_template', $plantilla_id, $nuevo_id );
    update_field( 'expense_status', true, $nuevo_id );
    update_field( 'expense_paid', false, $nuevo_id );
}

function talos_generar_gastos_del_periodo( DateTime $mes_destino ) {
    $creados      = 0;
    $desactivados = 0;

    $plantillas = get_posts( [
        'post_type'      => 'talos_expense',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [ 'key' => 'expense_type', 'value' => 'plantilla' ],
        ],
    ] );

    foreach ( $plantillas as $plantilla ) {
        if ( ! get_field( 'expense_status', $plantilla->ID ) ) {
            continue;
        }

        $fecha_fin = talos_parse_fecha_acf( get_field( 'expense_end_date', $plantilla->ID ) ?: '', 'Y-m-d' );
        if ( talos_periodo_vencido( $fecha_fin, $mes_destino ) ) {
            update_field( 'expense_status', false, $plantilla->ID );
            $desactivados++;
            continue;
        }

        $fecha_inicio = talos_parse_fecha_acf( get_field( 'expense_start_date', $plantilla->ID ) ?: '', 'Y-m-d' );
        if ( ! $fecha_inicio ) {
            continue;
        }

        $frecuencia  = get_field( 'expense_frequency', $plantilla->ID );
        $fechas_pago = talos_fechas_cobro_en_periodo( $frecuencia, $fecha_inicio, $mes_destino );

        foreach ( $fechas_pago as $fecha_pago ) {
            if ( talos_gasto_ya_existe( $plantilla->ID, $fecha_pago ) ) {
                continue;
            }
            talos_crear_transaccion_desde_plantilla( $plantilla->ID, $fecha_pago );
            $creados++;
        }
    }

    return [ 'gastos_creados' => $creados, 'plantillas_desactivadas' => $desactivados ];
}

/**
 * =========================================================================
 * ORQUESTADOR
 * =========================================================================
 * $mes_destino debe venir posicionado en el día 1 del mes a generar.
 */
function talos_generar_periodo( DateTime $mes_destino ) {
    $resultado_income = talos_generar_income_del_periodo( $mes_destino );
    $resultado_gastos = talos_generar_gastos_del_periodo( $mes_destino );

    return array_merge( $resultado_income, $resultado_gastos );
}
