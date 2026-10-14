<?php
/**
 * =========================================================================
 * ADMIN: IMPORTADOR DE EMPRESAS HISTÓRICAS
 * =========================================================================
 * Uso de una sola vez: crea posts talos_company para clientes que ya no
 * operan hoy pero que sí tienen ingresos históricos (2022-2024) que vamos a
 * importar y necesitan un post real al que ligar vía income_company
 * (post_object). Sube un CSV de una sola columna "NombreEmpresa" (sin
 * encabezado adicional) y crea cada una como talos_company, Estatus
 * "inactive", sin tocar nada más. Evita duplicados por título exacto.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'talos_registrar_pagina_importador_empresas_historico' );
function talos_registrar_pagina_importador_empresas_historico() {
    add_submenu_page(
        'talos-importar-amex',
        'Importar Empresas Históricas',
        'Empresas Históricas',
        'manage_options',
        'talos-importar-empresas-historico',
        'talos_render_pagina_importador_empresas_historico'
    );
}

function talos_empresa_ya_existe_por_titulo( $nombre ) {
    $existentes = get_posts( [
        'post_type'      => 'talos_company',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'title'          => $nombre,
    ] );
    return ! empty( $existentes );
}

function talos_procesar_csv_empresas_historico( $ruta_archivo ) {
    $handle = fopen( $ruta_archivo, 'r' );
    if ( ! $handle ) {
        return [ 'error' => 'No se pudo abrir el archivo.' ];
    }

    $creadas  = 0;
    $omitidas = 0;

    while ( ( $fila = fgetcsv( $handle ) ) !== false ) {
        if ( empty( $fila[0] ) ) {
            continue;
        }
        $nombre = trim( $fila[0] );
        if ( 0 === strcasecmp( $nombre, 'NombreEmpresa' ) ) {
            continue; // encabezado, si vino incluido
        }

        if ( talos_empresa_ya_existe_por_titulo( $nombre ) ) {
            $omitidas++;
            continue;
        }

        $nuevo_id = wp_insert_post( [
            'post_title'  => $nombre,
            'post_type'   => 'talos_company',
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $nuevo_id ) || ! $nuevo_id ) {
            continue;
        }

        update_field( 'company_status', 'inactive', $nuevo_id );
        $creadas++;
    }

    fclose( $handle );

    return [
        'creadas'  => $creadas,
        'omitidas' => $omitidas,
    ];
}

function talos_render_pagina_importador_empresas_historico() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para ver esta página.' );
    }

    $resultado = null;

    if ( isset( $_POST['talos_importar_empresas_historico_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['talos_importar_empresas_historico_nonce'] ) ), 'talos_importar_empresas_historico' ) ) {
        if ( ! empty( $_FILES['talos_empresas_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['talos_empresas_csv']['tmp_name'] ) ) {
            $resultado = talos_procesar_csv_empresas_historico( $_FILES['talos_empresas_csv']['tmp_name'] );
        } else {
            $resultado = [ 'error' => 'No se recibió ningún archivo.' ];
        }
    }
    ?>
    <div class="wrap">
        <h1>Importar Empresas Históricas</h1>
        <p>
            Sube un CSV con una sola columna (el nombre de la empresa, una por renglón). Crea cada una como Empresa
            con Estatus "Inactive". Si una empresa con ese nombre exacto ya existe, se omite — seguro volver a correrlo
            por accidente.
        </p>

        <?php if ( is_array( $resultado ) && isset( $resultado['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $resultado['error'] ); ?></p></div>
        <?php elseif ( is_array( $resultado ) ) : ?>
            <div class="notice notice-success">
                <p><strong>Listo.</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php echo (int) $resultado['creadas']; ?> Empresa(s) nueva(s) creada(s).</li>
                    <li><?php echo (int) $resultado['omitidas']; ?> omitida(s) por ya existir con ese nombre.</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'talos_importar_empresas_historico', 'talos_importar_empresas_historico_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="talos_empresas_csv">Archivo CSV</label></th>
                    <td><input type="file" id="talos_empresas_csv" name="talos_empresas_csv" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button( 'Importar' ); ?>
        </form>
    </div>
    <?php
}
