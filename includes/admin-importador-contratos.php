<?php
/**
 * =========================================================================
 * ADMIN: IMPORTADOR DE SERVICIOS CONTRATADOS (talos_contract)
 * =========================================================================
 * Uso de una sola vez: sube contratos_import.csv (columnas: CompanyID,
 * ServiceID, ServiceNombreOriginal, InvoiceDescription, Frequency,
 * Quantity, Cost, Utility, Price, AppliesIVA, Deferred, DeferredMonths,
 * StartDate, EndDate, Status, ReminderAllowed, Client) y crea un post
 * talos_contract por renglon -- reemplazo del viejo repetidor
 * company_services. Liga a Empresa/Servicio por post ID real (CompanyID/
 * ServiceID, ambos ya vienen resueltos en el CSV).
 *
 * StartDate/EndDate vienen en formato ISO (YYYY-MM-DD); se convierten a
 * Ymd para los date_picker de ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'talos_registrar_pagina_importador_contratos' );
function talos_registrar_pagina_importador_contratos() {
    add_submenu_page(
        'talos-importar-amex',
        'Importar Servicios Contratados',
        'Servicios Contratados',
        'manage_options',
        'talos-importar-contratos',
        'talos_render_pagina_importador_contratos'
    );
}

function talos_contrato_fingerprint( $fila ) {
    $base = implode( '|', [ $fila['CompanyID'] ?? '', $fila['ServiceID'] ?? '', $fila['InvoiceDescription'] ?? '', $fila['StartDate'] ?? '' ] );
    return md5( $base );
}

function talos_contrato_ya_existe( $huella ) {
    $existentes = get_posts( [
        'post_type'      => 'talos_contract',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_talos_contrato_fingerprint', 'value' => $huella ] ],
    ] );
    return ! empty( $existentes );
}

function talos_contrato_fecha_iso_a_ymd( $texto ) {
    $texto = trim( (string) $texto );
    if ( '' === $texto ) {
        return '';
    }
    $fecha = DateTime::createFromFormat( 'Y-m-d', $texto );
    return $fecha ? $fecha->format( 'Ymd' ) : '';
}

function talos_contrato_bool( $valor ) {
    return 0 === strcasecmp( trim( (string) $valor ), 'TRUE' );
}

function talos_procesar_csv_contratos( $ruta_archivo ) {
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
    $encabezados[0] = ltrim( $encabezados[0], "\xEF\xBB\xBF" );
    $idx = array_flip( $encabezados );

    $columnas_necesarias = [ 'CompanyID', 'ServiceID', 'InvoiceDescription', 'Frequency', 'Quantity',
        'Cost', 'Price', 'AppliesIVA', 'Deferred', 'DeferredMonths', 'StartDate', 'EndDate', 'Status', 'ReminderAllowed' ];
    foreach ( $columnas_necesarias as $col ) {
        if ( ! isset( $idx[ $col ] ) ) {
            fclose( $handle );
            return [ 'error' => 'No se encontró la columna "' . $col . '" en el archivo. Usa contratos_import.csv tal cual lo genera el script.' ];
        }
    }

    $creados    = 0;
    $duplicados = 0;
    $sin_servicio = 0;

    while ( ( $renglon = fgetcsv( $handle ) ) !== false ) {
        if ( count( $renglon ) < count( $encabezados ) ) {
            continue;
        }

        $fila = [];
        foreach ( $idx as $nombre => $i ) {
            $fila[ $nombre ] = $renglon[ $i ] ?? '';
        }

        $company_id = (int) $fila['CompanyID'];
        $service_id = (int) $fila['ServiceID'];
        if ( ! $company_id ) {
            continue;
        }
        if ( ! $service_id ) {
            $sin_servicio++;
        }

        $huella = talos_contrato_fingerprint( $fila );
        if ( talos_contrato_ya_existe( $huella ) ) {
            $duplicados++;
            continue;
        }

        $nuevo_id = wp_insert_post( [
            'post_title'  => trim( ( $fila['Client'] ?? '' ) . ' - ' . ( $fila['ServiceNombreOriginal'] ?: $fila['InvoiceDescription'] ) ),
            'post_type'   => 'talos_contract',
            'post_status' => 'publish',
        ] );
        if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
            continue;
        }

        update_field( 'service_company', $company_id, $nuevo_id );
        if ( $service_id ) {
            update_field( 'service_item', $service_id, $nuevo_id );
        }
        update_field( 'service_invoice_description', $fila['InvoiceDescription'], $nuevo_id );
        update_field( 'service_frequency', $fila['Frequency'], $nuevo_id );
        update_field( 'service_quantity', (float) $fila['Quantity'], $nuevo_id );
        update_field( 'service_price', (float) $fila['Price'], $nuevo_id );
        update_field( 'service_applies_iva', talos_contrato_bool( $fila['AppliesIVA'] ), $nuevo_id );
        update_field( 'service_deferred', talos_contrato_bool( $fila['Deferred'] ), $nuevo_id );
        update_field( 'service_deferred_months', (float) $fila['DeferredMonths'], $nuevo_id );
        update_field( 'service_cost', (float) $fila['Cost'], $nuevo_id );
        update_field( 'service_utility', (float) ( $fila['Utility'] ?? 0 ), $nuevo_id );
        update_field( 'service_start_date', talos_contrato_fecha_iso_a_ymd( $fila['StartDate'] ), $nuevo_id );
        update_field( 'service_end_date', talos_contrato_fecha_iso_a_ymd( $fila['EndDate'] ), $nuevo_id );
        update_field( 'service_status', talos_contrato_bool( $fila['Status'] ), $nuevo_id );
        update_field( 'service_reminder_allowed', talos_contrato_bool( $fila['ReminderAllowed'] ), $nuevo_id );
        update_post_meta( $nuevo_id, '_talos_contrato_fingerprint', $huella );

        $creados++;
    }

    fclose( $handle );

    return [
        'creados'      => $creados,
        'duplicados'   => $duplicados,
        'sin_servicio' => $sin_servicio,
    ];
}

function talos_render_pagina_importador_contratos() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para ver esta página.' );
    }

    $resultado = null;

    if ( isset( $_POST['talos_importar_contratos_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['talos_importar_contratos_nonce'] ) ), 'talos_importar_contratos' ) ) {
        if ( ! empty( $_FILES['talos_contratos_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['talos_contratos_csv']['tmp_name'] ) ) {
            $resultado = talos_procesar_csv_contratos( $_FILES['talos_contratos_csv']['tmp_name'] );
        } else {
            $resultado = [ 'error' => 'No se recibió ningún archivo.' ];
        }
    }
    ?>
    <div class="wrap">
        <h1>Importar Servicios Contratados</h1>
        <p>
            Sube <code>contratos_import.csv</code> tal como lo genera el script. Crea un post en
            <strong>Servicios Contratados</strong> (talos_contract) por renglón, ligado a Empresa/Servicio por ID.
            Evita duplicados si vuelves a subir el mismo archivo.
        </p>

        <?php if ( is_array( $resultado ) && isset( $resultado['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $resultado['error'] ); ?></p></div>
        <?php elseif ( is_array( $resultado ) ) : ?>
            <div class="notice notice-success">
                <p><strong>Listo.</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php echo (int) $resultado['creados']; ?> Servicio(s) Contratado(s) nuevo(s) creado(s).</li>
                    <li><?php echo (int) $resultado['duplicados']; ?> renglón(es) omitido(s) por ya estar importado(s) antes.</li>
                    <li><?php echo (int) $resultado['sin_servicio']; ?> se crearon sin Servicio de catálogo ligado (el CSV no traía ID para ese nombre).</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'talos_importar_contratos', 'talos_importar_contratos_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="talos_contratos_csv">contratos_import.csv</label></th>
                    <td><input type="file" id="talos_contratos_csv" name="talos_contratos_csv" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button( 'Importar' ); ?>
        </form>
    </div>
    <?php
}
