<?php
/**
 * =========================================================================
 * ADMIN: IMPORTADOR DE ESTADO DE CUENTA AMEX
 * =========================================================================
 * Sube el CSV tal como lo exporta AMEX (sin editar columnas) y:
 *  - Ignora pagos/abonos a la tarjeta y cualquier crédito (Importe <= 0).
 *  - Clasifica cada concepto contra amex_classification_rules (primera
 *    regla que haga match por substring, sin importar mayúsculas/minúsculas).
 *  - Lo no clasificado queda en subcategoría "pendiente" para revisión.
 *  - Evita duplicados usando la columna Referencia (folio único de AMEX)
 *    como huella — seguro volver a subir el mismo periodo por accidente.
 *  - Los cargos a nombre de TALOS_AMEX_TITULAR_REEMBOLSO se registran como
 *    Gasto normal (sí salieron de la tarjeta) y ADEMÁS se suman en un
 *    Ingreso mensual "Reembolso Tarjeta Adicional" — así el neto real
 *    (lo que ella no reembolsa) queda visible en vez de perdido.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Nombre exacto como aparece en la columna "Titular de la Tarjeta" del CSV de AMEX.
define( 'TALOS_AMEX_TITULAR_REEMBOLSO', 'GUIMEL Z CABELLO CARRILLO' );

add_action( 'admin_menu', 'talos_registrar_pagina_importador_amex' );
function talos_registrar_pagina_importador_amex() {
    add_menu_page(
        'Importar AMEX',
        'Importar AMEX',
        'manage_options',
        'talos-importar-amex',
        'talos_render_pagina_importador_amex',
        'dashicons-upload'
    );
}

function talos_normalizar_utf8( $texto ) {
    if ( '' === $texto || null === $texto ) {
        return $texto;
    }
    if ( ! mb_check_encoding( $texto, 'UTF-8' ) ) {
        $texto = mb_convert_encoding( $texto, 'UTF-8', 'Windows-1252' );
    }
    return $texto;
}

function talos_quitar_acentos( $texto ) {
    $con_acento = [ 'á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ' ];
    $sin_acento = [ 'a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N' ];
    return str_replace( $con_acento, $sin_acento, $texto );
}

function talos_clasificar_concepto_amex( $descripcion ) {
    $reglas = get_field( 'amex_classification_rules', 'option' );
    $descripcion_normalizada = talos_quitar_acentos( $descripcion );

    if ( $reglas ) {
        foreach ( $reglas as $regla ) {
            if ( empty( $regla['rule_keyword'] ) ) {
                continue;
            }
            $keyword_normalizado = talos_quitar_acentos( $regla['rule_keyword'] );
            if ( false !== stripos( $descripcion_normalizada, $keyword_normalizado ) ) {
                return [
                    'categoria'    => $regla['rule_category'],
                    'subcategoria' => $regla['rule_subcategory'],
                ];
            }
        }
    }

    return [ 'categoria' => '', 'subcategoria' => 'pendiente' ];
}

function talos_amex_transaccion_ya_existe( $referencia ) {
    if ( ! $referencia ) {
        return false;
    }
    $existentes = get_posts( [
        'post_type'      => 'talos_expense',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => '_talos_amex_referencia', 'value' => $referencia ],
        ],
    ] );
    return ! empty( $existentes );
}

function talos_registrar_reembolso_mensual( $mes_key, $monto ) {
    $fecha_mes = DateTime::createFromFormat( 'Y-m-d', $mes_key . '-01' );
    if ( ! $fecha_mes ) {
        return;
    }

    $existentes = get_posts( [
        'post_type'      => 'talos_income',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => '_talos_reembolso_mes', 'value' => $mes_key ],
        ],
    ] );

    if ( ! empty( $existentes ) ) {
        $income_id     = $existentes[0];
        $total_actual  = (float) get_field( 'income_total', $income_id );
        $nuevo_total   = $total_actual + $monto;
        update_field( 'income_total', $nuevo_total, $income_id );
        update_field( 'income_subtotal', $nuevo_total, $income_id );
        update_field( 'income_unit_price', $nuevo_total, $income_id );
        return;
    }

    $income_id = wp_insert_post( [
        'post_title'  => 'Reembolso Tarjeta Adicional - ' . $fecha_mes->format( 'Y-m' ),
        'post_type'   => 'talos_income',
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $income_id ) || ! $income_id ) {
        return;
    }

    update_field( 'income_description', 'Reembolso Tarjeta Adicional (' . TALOS_AMEX_TITULAR_REEMBOLSO . ')', $income_id );
    update_field( 'income_month', $fecha_mes->format( 'Ymd' ), $income_id );
    update_field( 'income_quantity', 1, $income_id );
    update_field( 'income_unit_price', $monto, $income_id );
    update_field( 'income_applies_iva', false, $income_id );
    update_field( 'income_subtotal', $monto, $income_id );
    update_field( 'income_total', $monto, $income_id );
    update_field( 'income_doc_type', 'nota_venta', $income_id );
    update_field( 'income_sent', false, $income_id );
    update_field( 'income_paid', false, $income_id );
    update_post_meta( $income_id, '_talos_reembolso_mes', $mes_key );
}

function talos_procesar_csv_amex( $ruta_archivo ) {
    // Estados de cuenta históricos pueden traer miles de renglones —
    // le damos más margen que el límite por defecto del servidor.
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 300 );
    }

    $handle = fopen( $ruta_archivo, 'r' );
    if ( ! $handle ) {
        return [ 'error' => 'No se pudo abrir el archivo.' ];
    }

    $encabezados = fgetcsv( $handle );
    if ( ! $encabezados ) {
        fclose( $handle );
        return [ 'error' => 'El archivo está vacío o no es un CSV válido.' ];
    }

    foreach ( $encabezados as $i => $h ) {
        $h = talos_normalizar_utf8( trim( $h ) );
        if ( 0 === $i ) {
            $h = ltrim( $h, "\xEF\xBB\xBF" ); // por si trae BOM al inicio del archivo
        }
        $encabezados[ $i ] = $h;
    }

    $idx = array_flip( $encabezados );

    $columnas_necesarias = [ 'Fecha de Compra', 'Descripción', 'Titular de la Tarjeta', 'Importe', 'Referencia' ];
    foreach ( $columnas_necesarias as $col ) {
        if ( ! isset( $idx[ $col ] ) ) {
            fclose( $handle );
            return [ 'error' => 'No se encontró la columna "' . $col . '" en el archivo. Revisa que sea el export original de AMEX sin editar.' ];
        }
    }

    $gastos_creados        = 0;
    $duplicados_omitidos   = 0;
    $pendientes_clasificar = 0;
    $totales_reembolso     = []; // 'Y-m' => monto acumulado

    while ( ( $fila = fgetcsv( $handle ) ) !== false ) {
        if ( count( $fila ) < count( $encabezados ) ) {
            continue; // renglón mal formado, se ignora
        }

        foreach ( $fila as $i => $valor ) {
            $fila[ $i ] = talos_normalizar_utf8( $valor );
        }

        $importe = (float) str_replace( ',', '', $fila[ $idx['Importe'] ] );
        if ( $importe <= 0 ) {
            continue; // pago/abono a la tarjeta o crédito, no es un gasto
        }

        $referencia = trim( $fila[ $idx['Referencia'] ], " '\t\n\r\0\x0B" );

        if ( talos_amex_transaccion_ya_existe( $referencia ) ) {
            $duplicados_omitidos++;
            continue;
        }

        $fecha_compra = DateTime::createFromFormat( 'd M Y', trim( $fila[ $idx['Fecha de Compra'] ] ) );
        if ( ! $fecha_compra ) {
            continue;
        }

        $descripcion = trim( $fila[ $idx['Descripción'] ] );
        $titular     = trim( $fila[ $idx['Titular de la Tarjeta'] ] );

        $clasificacion = talos_clasificar_concepto_amex( $descripcion );
        if ( 'pendiente' === $clasificacion['subcategoria'] ) {
            $pendientes_clasificar++;
        }

        $nuevo_id = wp_insert_post( [
            'post_title'  => $descripcion . ' - ' . $fecha_compra->format( 'Y-m-d' ),
            'post_type'   => 'talos_expense',
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
            continue;
        }

        update_field( 'expense_type', 'transaccion', $nuevo_id );
        update_field( 'expense_category', $clasificacion['categoria'], $nuevo_id );
        update_field( 'expense_subcategory', $clasificacion['subcategoria'], $nuevo_id );
        update_field( 'expense_supplier', $descripcion, $nuevo_id );
        update_field( 'expense_description', $descripcion, $nuevo_id );
        update_field( 'expense_amount', $importe, $nuevo_id );
        update_field( 'expense_frequency', 'pago_unico', $nuevo_id );
        update_field( 'expense_period', $fecha_compra->format( 'Ymd' ), $nuevo_id );
        update_field( 'expense_status', true, $nuevo_id );
        update_field( 'expense_paid', true, $nuevo_id );
        update_field( 'expense_payment_method', 'amex', $nuevo_id );
        update_field( 'expense_payment_date', $fecha_compra->format( 'Ymd' ), $nuevo_id );
        update_post_meta( $nuevo_id, '_talos_amex_referencia', $referencia );

        $gastos_creados++;

        if ( 0 === strcasecmp( $titular, TALOS_AMEX_TITULAR_REEMBOLSO ) ) {
            $mes_key = $fecha_compra->format( 'Y-m' );
            if ( ! isset( $totales_reembolso[ $mes_key ] ) ) {
                $totales_reembolso[ $mes_key ] = 0;
            }
            $totales_reembolso[ $mes_key ] += $importe;
        }
    }

    fclose( $handle );

    $meses_con_reembolso = 0;
    foreach ( $totales_reembolso as $mes_key => $monto ) {
        talos_registrar_reembolso_mensual( $mes_key, $monto );
        $meses_con_reembolso++;
    }

    return [
        'gastos_creados'        => $gastos_creados,
        'duplicados_omitidos'   => $duplicados_omitidos,
        'pendientes_clasificar' => $pendientes_clasificar,
        'meses_con_reembolso'   => $meses_con_reembolso,
    ];
}

function talos_render_pagina_importador_amex() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para ver esta página.' );
    }

    $resultado = null;

    if ( isset( $_POST['talos_importar_amex_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['talos_importar_amex_nonce'] ) ), 'talos_importar_amex' ) ) {
        if ( ! empty( $_FILES['talos_amex_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['talos_amex_csv']['tmp_name'] ) ) {
            $resultado = talos_procesar_csv_amex( $_FILES['talos_amex_csv']['tmp_name'] );
        } else {
            $resultado = [ 'error' => 'No se recibió ningún archivo.' ];
        }
    }
    ?>
    <div class="wrap">
        <h1>Importar Estado de Cuenta AMEX</h1>
        <p>
            Sube el CSV tal como lo descargas de AMEX, sin editar columnas. El sistema ignora los pagos/abonos a la
            tarjeta, clasifica cada concepto contra tus Reglas AMEX, evita duplicados si vuelves a subir el mismo
            periodo, y agrupa los cargos de <strong><?php echo esc_html( TALOS_AMEX_TITULAR_REEMBOLSO ); ?></strong>
            en un Ingreso mensual de reembolso.
        </p>

        <?php if ( is_array( $resultado ) && isset( $resultado['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $resultado['error'] ); ?></p></div>
        <?php elseif ( is_array( $resultado ) ) : ?>
            <div class="notice notice-success">
                <p><strong>Listo.</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php echo (int) $resultado['gastos_creados']; ?> Gasto(s) nuevo(s) creado(s).</li>
                    <li><?php echo (int) $resultado['duplicados_omitidos']; ?> renglón(es) omitido(s) por ya estar importado(s) antes.</li>
                    <li><?php echo (int) $resultado['pendientes_clasificar']; ?> quedaron como "⚠️ Pendiente de Clasificar" — revísalos para ver si hace falta una regla nueva.</li>
                    <li><?php echo (int) $resultado['meses_con_reembolso']; ?> mes(es) con Ingreso de reembolso creado o actualizado.</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'talos_importar_amex', 'talos_importar_amex_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="talos_amex_csv">Archivo CSV de AMEX</label></th>
                    <td><input type="file" id="talos_amex_csv" name="talos_amex_csv" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button( 'Importar' ); ?>
        </form>
    </div>
    <?php
}
