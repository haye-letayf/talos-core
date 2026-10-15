<?php
/**
 * =========================================================================
 * ADMIN: IMPORTADOR DE INGRESOS HISTÓRICOS
 * =========================================================================
 * Uso de una sola vez: sube ingresos_maestro.csv (columnas: Month,MonthISO,
 * Client,ClientWPID,Service,ServiceID,Description,DocType,DocTypeSlug,
 * AppliesIVA,Qty,UnitPrice,SubTotal,TOTAL,Sent,Paid,PaymentDate,Origen,Flags)
 * y crea un post talos_income por fila. Liga Empresa/Servicio por ID real
 * (ClientWPID/ServiceID) cuando vienen, los deja sin ligar si no (ej.
 * "Walid", "Principal" -- confirmado con Jorge que no pasa nada).
 * Evita duplicados con una huella por fila (Origen+Month+Client+Service+
 * Description+TOTAL) -- seguro volver a subir el mismo CSV por accidente.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'talos_registrar_pagina_importador_ingresos_historico' );
function talos_registrar_pagina_importador_ingresos_historico() {
    add_submenu_page(
        'talos-importar-amex',
        'Importar Ingresos Históricos',
        'Ingresos Históricos',
        'manage_options',
        'talos-importar-ingresos-historico',
        'talos_render_pagina_importador_ingresos_historico'
    );
}

function talos_ingreso_historico_fingerprint( $fila ) {
    $base = implode( '|', [
        $fila['Origen'] ?? '', $fila['Month'] ?? '', $fila['Client'] ?? '',
        $fila['Service'] ?? '', $fila['Description'] ?? '', $fila['TOTAL'] ?? '',
    ] );
    return md5( $base );
}

function talos_ingreso_historico_ya_existe( $huella ) {
    $existentes = get_posts( [
        'post_type'      => 'talos_income',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => '_talos_historico_fingerprint', 'value' => $huella ],
        ],
    ] );
    return ! empty( $existentes );
}

function talos_ingreso_historico_bool( $valor ) {
    $valor = strtoupper( trim( (string) $valor ) );
    return in_array( $valor, [ 'TRUE', '1', 'SI', 'VERDADERO' ], true );
}

// Convierte "1/ene/2025" o "24/abr/2026" (formato de la hoja de Jorge) a Ymd.
function talos_ingreso_historico_fecha_es( $texto ) {
    $texto = trim( (string) $texto );
    if ( '' === $texto ) {
        return '';
    }
    $meses = [
        'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04',
        'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12',
    ];
    $partes = explode( '/', $texto );
    if ( 3 !== count( $partes ) ) {
        return '';
    }
    [ $dia, $mes_txt, $anio ] = $partes;
    $mes = $meses[ strtolower( trim( $mes_txt ) ) ] ?? null;
    if ( ! $mes || ! ctype_digit( $dia ) || ! ctype_digit( $anio ) ) {
        return '';
    }
    return sprintf( '%04d%s%02d', (int) $anio, $mes, (int) $dia );
}

function talos_procesar_csv_ingresos_historico( $ruta_archivo ) {
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
    $encabezados[0] = ltrim( $encabezados[0], "\xEF\xBB\xBF" ); // por si trae BOM
    $idx = array_flip( $encabezados );

    $columnas_necesarias = [ 'Month', 'MonthISO', 'Client', 'ClientWPID', 'Service', 'ServiceID',
        'Description', 'DocTypeSlug', 'AppliesIVA', 'Qty', 'UnitPrice', 'SubTotal', 'TOTAL',
        'Sent', 'Paid', 'PaymentDate', 'Origen' ];
    foreach ( $columnas_necesarias as $col ) {
        if ( ! isset( $idx[ $col ] ) ) {
            fclose( $handle );
            return [ 'error' => 'No se encontró la columna "' . $col . '" en el archivo. Usa ingresos_maestro.csv tal cual lo genera el script, sin editar columnas.' ];
        }
    }

    $creados    = 0;
    $duplicados = 0;
    $sin_ligar  = 0;

    while ( ( $renglon = fgetcsv( $handle ) ) !== false ) {
        if ( count( $renglon ) < count( $encabezados ) ) {
            continue;
        }

        $fila = [];
        foreach ( $idx as $nombre => $i ) {
            $fila[ $nombre ] = $renglon[ $i ] ?? '';
        }

        if ( '' === trim( $fila['MonthISO'] ) ) {
            continue; // fila sin mes valido, no se puede importar
        }

        $huella = talos_ingreso_historico_fingerprint( $fila );
        if ( talos_ingreso_historico_ya_existe( $huella ) ) {
            $duplicados++;
            continue;
        }

        $titulo = trim( $fila['Client'] . ' - ' . ( $fila['Service'] ?: $fila['Description'] ) . ' - ' . $fila['Month'] );

        $nuevo_id = wp_insert_post( [
            'post_title'  => $titulo,
            'post_type'   => 'talos_income',
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
            continue;
        }

        $company_id = (int) $fila['ClientWPID'];
        $service_id = (int) $fila['ServiceID'];

        if ( $company_id ) {
            update_field( 'income_company', $company_id, $nuevo_id );
        } else {
            $sin_ligar++;
        }
        if ( $service_id ) {
            update_field( 'income_service', $service_id, $nuevo_id );
        }

        update_field( 'income_description', $fila['Description'] ?: $fila['Service'], $nuevo_id );
        update_field( 'income_month', str_replace( '-', '', $fila['MonthISO'] ), $nuevo_id );
        update_field( 'income_quantity', (float) $fila['Qty'], $nuevo_id );
        update_field( 'income_unit_price', (float) $fila['UnitPrice'], $nuevo_id );
        update_field( 'income_applies_iva', talos_ingreso_historico_bool( $fila['AppliesIVA'] ), $nuevo_id );
        update_field( 'income_subtotal', (float) $fila['SubTotal'], $nuevo_id );
        update_field( 'income_total', (float) $fila['TOTAL'], $nuevo_id );
        update_field( 'income_doc_type', $fila['DocTypeSlug'], $nuevo_id );
        update_field( 'income_sent', talos_ingreso_historico_bool( $fila['Sent'] ), $nuevo_id );
        update_field( 'income_paid', talos_ingreso_historico_bool( $fila['Paid'] ), $nuevo_id );

        $fecha_pago = talos_ingreso_historico_fecha_es( $fila['PaymentDate'] );
        if ( $fecha_pago ) {
            update_field( 'income_payment_date', $fecha_pago, $nuevo_id );
        }

        update_post_meta( $nuevo_id, '_talos_historico_fingerprint', $huella );

        $creados++;
    }

    fclose( $handle );

    return [
        'creados'    => $creados,
        'duplicados' => $duplicados,
        'sin_ligar'  => $sin_ligar,
    ];
}

function talos_render_pagina_importador_ingresos_historico() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para ver esta página.' );
    }

    $resultado = null;

    if ( isset( $_POST['talos_importar_ingresos_historico_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['talos_importar_ingresos_historico_nonce'] ) ), 'talos_importar_ingresos_historico' ) ) {
        if ( ! empty( $_FILES['talos_ingresos_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['talos_ingresos_csv']['tmp_name'] ) ) {
            $resultado = talos_procesar_csv_ingresos_historico( $_FILES['talos_ingresos_csv']['tmp_name'] );
        } else {
            $resultado = [ 'error' => 'No se recibió ningún archivo.' ];
        }
    }
    ?>
    <div class="wrap">
        <h1>Importar Ingresos Históricos</h1>
        <p>
            Sube <code>ingresos_maestro.csv</code> tal como lo genera el script, sin editar columnas. Crea un Ingreso
            por renglón, ligado a Empresa/Servicio cuando el CSV trae su ID. Si una fila no trae ID de Empresa, el
            Ingreso se crea igual, solo sin esa liga. Evita duplicados si vuelves a subir el mismo archivo.
        </p>

        <?php if ( is_array( $resultado ) && isset( $resultado['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $resultado['error'] ); ?></p></div>
        <?php elseif ( is_array( $resultado ) ) : ?>
            <div class="notice notice-success">
                <p><strong>Listo.</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php echo (int) $resultado['creados']; ?> Ingreso(s) nuevo(s) creado(s).</li>
                    <li><?php echo (int) $resultado['duplicados']; ?> renglón(es) omitido(s) por ya estar importado(s) antes.</li>
                    <li><?php echo (int) $resultado['sin_ligar']; ?> se crearon sin Empresa ligada (el CSV no traía ID para ese cliente).</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'talos_importar_ingresos_historico', 'talos_importar_ingresos_historico_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="talos_ingresos_csv">ingresos_maestro.csv</label></th>
                    <td><input type="file" id="talos_ingresos_csv" name="talos_ingresos_csv" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button( 'Importar' ); ?>
        </form>
    </div>
    <?php
}
