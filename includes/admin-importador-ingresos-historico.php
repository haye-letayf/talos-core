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

function talos_diagnostico_ingresos_historico() {
    global $wpdb;

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'talos_income' AND post_status != 'trash'"
    );

    $de_este_importador = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_talos_historico_fingerprint'
         WHERE p.post_type = 'talos_income' AND p.post_status != 'trash'"
    );

    $del_motor_recurrencia = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'talos_income' AND post_status != 'trash' AND post_title LIKE %s",
            'Income %'
        )
    );

    $rango = $wpdb->get_row(
        "SELECT MIN(m.meta_value) AS min_mes, MAX(m.meta_value) AS max_mes
         FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = 'income_month' AND p.post_type = 'talos_income' AND p.post_status != 'trash'"
    );

    // Duplicado REAL = misma Empresa + mismo mes + mismo Total, una vez creado
    // por este importador y otra vez por cualquier otro origen -- esto no
    // depende del titulo del post, compara por contenido. Se hace en dos
    // consultas planas (sin cruzar en SQL) y se compara en PHP -- un JOIN
    // de 3 vias contra si misma sobre postmeta se cuelga en hosting
    // compartido con ~3000 posts x ~15 filas de meta cada uno.
    $filas_meta = $wpdb->get_results(
        "SELECT p.ID AS post_id, m.meta_key, m.meta_value,
                (fp.meta_id IS NOT NULL) AS es_mio
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
            AND m.meta_key IN ('income_company', 'income_month', 'income_total')
         LEFT JOIN {$wpdb->postmeta} fp ON fp.post_id = p.ID AND fp.meta_key = '_talos_historico_fingerprint'
         WHERE p.post_type = 'talos_income' AND p.post_status != 'trash'"
    );

    $por_post = [];
    foreach ( $filas_meta as $f ) {
        $por_post[ $f->post_id ]['es_mio'] = (bool) $f->es_mio;
        $por_post[ $f->post_id ][ $f->meta_key ] = $f->meta_value;
    }

    $mios_por_clave  = [];
    $otros_por_clave = [];
    foreach ( $por_post as $post_id => $d ) {
        $empresa = $d['income_company'] ?? '';
        if ( '' === $empresa ) {
            continue;
        }
        $clave = $empresa . '|' . ( $d['income_month'] ?? '' ) . '|' . ( $d['income_total'] ?? '' );
        if ( ! empty( $d['es_mio'] ) ) {
            $mios_por_clave[ $clave ] = $post_id;
        } else {
            $otros_por_clave[ $clave ] = $post_id;
        }
    }

    $posibles_duplicados = [];
    foreach ( $mios_por_clave as $clave => $mio_id ) {
        if ( isset( $otros_por_clave[ $clave ] ) ) {
            [ $empresa, $mes, $total ] = explode( '|', $clave );
            $posibles_duplicados[] = (object) [
                'mio_id' => $mio_id, 'otro_id' => $otros_por_clave[ $clave ],
                'empresa' => $empresa, 'mes' => $mes, 'total' => $total,
            ];
        }
    }

    // Desglose de "Otros" -- sin pegarle otra vez a la base, usando lo que
    // ya se trajo arriba. Si tienen Empresa vacia, la comparacion de arriba
    // nunca los pudo ver como duplicados aunque lo fueran.
    $otros_con_empresa    = 0;
    $otros_sin_empresa    = 0;
    $muestra_otros        = [];
    foreach ( $por_post as $post_id => $d ) {
        if ( ! empty( $d['es_mio'] ) ) {
            continue;
        }
        $empresa = $d['income_company'] ?? '';
        if ( '' === $empresa ) {
            $otros_sin_empresa++;
        } else {
            $otros_con_empresa++;
            if ( count( $muestra_otros ) < 15 ) {
                $muestra_otros[] = (object) [
                    'post_id' => $post_id, 'empresa' => $empresa,
                    'mes' => $d['income_month'] ?? '', 'total' => $d['income_total'] ?? '',
                ];
            }
        }
    }

    return [
        'total'                 => $total,
        'otros_con_empresa'     => $otros_con_empresa,
        'otros_sin_empresa'     => $otros_sin_empresa,
        'muestra_otros'         => $muestra_otros,
        'de_este_importador'    => $de_este_importador,
        'posibles_duplicados'   => $posibles_duplicados,
        'del_motor_recurrencia' => $del_motor_recurrencia,
        'otros'                 => $total - $de_este_importador - $del_motor_recurrencia,
        'mes_min'               => $rango->min_mes ?? '',
        'mes_max'               => $rango->max_mes ?? '',
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
    <?php $diag = talos_diagnostico_ingresos_historico(); ?>
    <div class="wrap">
        <h1>Importar Ingresos Históricos</h1>

        <div class="notice notice-info" style="padding: 10px 15px;">
            <p><strong>Diagnóstico actual (antes de subir nada):</strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>Total de posts Ingreso (talos_income) en Talos: <strong><?php echo (int) $diag['total']; ?></strong></li>
                <li>Creados por este importador (huella histórica): <strong><?php echo (int) $diag['de_este_importador']; ?></strong></li>
                <li>Generados por el motor de recurrencia ("Income YYYY-MM-DD - Empresa #ID"): <strong><?php echo (int) $diag['del_motor_recurrencia']; ?></strong></li>
                <li>Otros (manuales, u otro origen): <strong><?php echo (int) $diag['otros']; ?></strong></li>
                <li>Rango de meses (income_month) ya presentes: <strong><?php echo esc_html( $diag['mes_min'] ?: '—' ); ?></strong> a <strong><?php echo esc_html( $diag['mes_max'] ?: '—' ); ?></strong></li>
                <li>Posibles duplicados reales (misma Empresa + mes + Total, uno de este importador y otro de otro origen): <strong><?php echo count( $diag['posibles_duplicados'] ); ?></strong></li>
                <li>De los "Otros": <strong><?php echo (int) $diag['otros_con_empresa']; ?></strong> sí tienen Empresa ligada, <strong><?php echo (int) $diag['otros_sin_empresa']; ?></strong> NO tienen Empresa (por eso nunca pudieron compararse arriba, aunque fueran duplicados)</li>
            </ul>
            <?php if ( ! empty( $diag['muestra_otros'] ) ) : ?>
                <p><strong>Muestra de "Otros" CON empresa (post ID / Empresa ID / Mes / Total):</strong></p>
                <table class="widefat" style="max-width: 700px;">
                    <tr><th>Post ID</th><th>Empresa ID</th><th>Mes</th><th>Total</th></tr>
                    <?php foreach ( $diag['muestra_otros'] as $d ) : ?>
                        <tr>
                            <td><?php echo (int) $d->post_id; ?></td>
                            <td><?php echo esc_html( $d->empresa ); ?></td>
                            <td><?php echo esc_html( $d->mes ); ?></td>
                            <td><?php echo esc_html( $d->total ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            <?php if ( ! empty( $diag['posibles_duplicados'] ) ) : ?>
                <p><strong>Primeros <?php echo min( 15, count( $diag['posibles_duplicados'] ) ); ?> ejemplos (Empresa ID / Mes / Total / post mío / post otro):</strong></p>
                <table class="widefat" style="max-width: 700px;">
                    <tr><th>Empresa</th><th>Mes</th><th>Total</th><th>Post mío</th><th>Post otro</th></tr>
                    <?php foreach ( array_slice( $diag['posibles_duplicados'], 0, 15 ) as $d ) : ?>
                        <tr>
                            <td><?php echo esc_html( $d->empresa ); ?></td>
                            <td><?php echo esc_html( $d->mes ); ?></td>
                            <td><?php echo esc_html( $d->total ); ?></td>
                            <td><?php echo (int) $d->mio_id; ?></td>
                            <td><?php echo (int) $d->otro_id; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

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
