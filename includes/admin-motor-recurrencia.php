<?php
/**
 * =========================================================================
 * ADMIN: PANTALLA "GENERAR MES"
 * =========================================================================
 * Disparador manual del motor de recurrencia (includes/motor-recurrencia.php).
 * Deliberadamente manual, no automático por cron — Talos maneja dinero real
 * y Jorge decide cuándo correr cada mes, igual que con el menú de Sheets.
 *
 * Es seguro darle "Generar Mes" varias veces para el mismo mes: el motor ya
 * verifica que un registro no exista antes de crearlo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'talos_registrar_pagina_motor_recurrencia' );
function talos_registrar_pagina_motor_recurrencia() {
    add_menu_page(
        'Generar Mes',
        'Generar Mes',
        'manage_options',
        'talos-generar-mes',
        'talos_render_pagina_motor_recurrencia',
        'dashicons-update'
    );
}

function talos_render_pagina_motor_recurrencia() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para ver esta página.' );
    }

    $resultado = null;

    if ( isset( $_POST['talos_generar_mes_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['talos_generar_mes_nonce'] ) ), 'talos_generar_mes' ) ) {

        $mes_input   = isset( $_POST['talos_mes_destino'] ) ? sanitize_text_field( wp_unslash( $_POST['talos_mes_destino'] ) ) : '';
        $mes_destino = DateTime::createFromFormat( 'Y-m-d', $mes_input . '-01' );

        if ( $mes_destino ) {
            $resultado = talos_generar_periodo( $mes_destino );
        } else {
            $resultado = 'error_fecha';
        }
    }

    $mes_actual_valor = date( 'Y-m' );
    ?>
    <div class="wrap">
        <h1>Generar Mes</h1>
        <p>
            Genera los <strong>Ingresos</strong> (desde los Servicios contratados de cada Empresa) y los
            <strong>Gastos</strong> (desde las Plantillas Recurrentes) que le toca cobrar/pagar al mes que elijas.
            También desactiva automáticamente los servicios y plantillas cuya fecha de fin ya pasó.
        </p>
        <p>
            Es seguro correrlo varias veces para el mismo mes — no va a duplicar nada de lo que ya se generó antes.
        </p>

        <?php if ( 'error_fecha' === $resultado ) : ?>
            <div class="notice notice-error"><p>No se pudo interpretar el mes seleccionado. Intenta de nuevo.</p></div>
        <?php elseif ( is_array( $resultado ) ) : ?>
            <div class="notice notice-success">
                <p><strong>Listo.</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php echo (int) $resultado['income_creados']; ?> Ingreso(s) nuevo(s) creado(s).</li>
                    <li><?php echo (int) $resultado['servicios_desactivados']; ?> Servicio(s) desactivado(s) por vencimiento.</li>
                    <li><?php echo (int) $resultado['gastos_creados']; ?> Gasto(s) nuevo(s) creado(s).</li>
                    <li><?php echo (int) $resultado['plantillas_desactivadas']; ?> Plantilla(s) de Gasto desactivada(s) por vencimiento.</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'talos_generar_mes', 'talos_generar_mes_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="talos_mes_destino">Mes a generar</label></th>
                    <td><input type="month" id="talos_mes_destino" name="talos_mes_destino" value="<?php echo esc_attr( $mes_actual_valor ); ?>" required></td>
                </tr>
            </table>
            <?php submit_button( 'Generar Mes' ); ?>
        </form>
    </div>
    <?php
}
