<?php
/**
 * Modo Pruebas: mientras esté activo, CUALQUIER wp_mail() del sitio (sin
 * importar qué módulo lo dispare, incluido código que se agregue después) se
 * redirige al correo de pruebas en vez del destinatario real. Se intercepta
 * a nivel del filtro 'wp_mail' (no envolviendo cada llamada individual) para
 * que cubra automáticamente cualquier envío futuro sin tener que acordarse
 * de pasar por un helper cada vez.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'talos_registrar_pagina_modo_pruebas' );
function talos_registrar_pagina_modo_pruebas() {
    add_options_page(
        'Talos — Modo Pruebas',
        'Talos: Modo Pruebas',
        'manage_options',
        'talos-modo-pruebas',
        'talos_render_pagina_modo_pruebas'
    );
}

function talos_render_pagina_modo_pruebas() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['talos_modo_pruebas_nonce'] ) && wp_verify_nonce( $_POST['talos_modo_pruebas_nonce'], 'talos_guardar_modo_pruebas' ) ) {
        update_option( 'talos_modo_pruebas_activo', isset( $_POST['talos_modo_pruebas_activo'] ) ? 1 : 0 );
        $correo = sanitize_email( wp_unslash( $_POST['talos_modo_pruebas_correo'] ?? '' ) );
        if ( is_email( $correo ) ) {
            update_option( 'talos_modo_pruebas_correo', $correo );
        }
        echo '<div class="notice notice-success is-dismissible"><p>Guardado.</p></div>';
    }

    $activo = get_option( 'talos_modo_pruebas_activo', 0 );
    $correo = get_option( 'talos_modo_pruebas_correo', 'jorgeletayf@gmail.com' );
    ?>
    <div class="wrap">
        <h1>Talos — Modo Pruebas</h1>
        <p>Mientras el Modo Pruebas esté activo, <strong>todo</strong> correo que Talos intente enviar (notas de venta, comprobantes de pago, etc.) se redirige al correo de pruebas en vez de llegarle al destinatario real. El asunto se marca con <code>[PRUEBA]</code> e indica quién lo habría recibido de verdad, para poder verificar que el contenido sea correcto sin arriesgar que le llegue algo a un cliente.</p>
        <p>Cuando termines de probar, desactívalo aquí mismo para volver a modo producción.</p>
        <form method="post">
            <?php wp_nonce_field( 'talos_guardar_modo_pruebas', 'talos_modo_pruebas_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Modo Pruebas</th>
                    <td>
                        <label><input type="checkbox" name="talos_modo_pruebas_activo" value="1" <?php checked( $activo, 1 ); ?>> Activo — interceptar todos los correos salientes</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Correo de pruebas</th>
                    <td><input type="email" name="talos_modo_pruebas_correo" value="<?php echo esc_attr( $correo ); ?>" class="regular-text" required></td>
                </tr>
            </table>
            <?php submit_button( 'Guardar' ); ?>
        </form>
    </div>
    <?php
}

add_filter( 'wp_mail', 'talos_interceptar_correo_en_modo_pruebas' );
function talos_interceptar_correo_en_modo_pruebas( $args ) {
    if ( ! get_option( 'talos_modo_pruebas_activo', 0 ) ) {
        return $args;
    }

    $correo_pruebas = get_option( 'talos_modo_pruebas_correo', 'jorgeletayf@gmail.com' );
    $destinatarios_originales = implode( ', ', (array) $args['to'] );

    $args['to']      = $correo_pruebas;
    $args['subject'] = '[PRUEBA → ' . $destinatarios_originales . '] ' . $args['subject'];

    return $args;
}

/**
 * talos-theme lee esta función directamente para mostrar un aviso visible
 * en el front mientras el Modo Pruebas esté activo — no solo un ajuste
 * escondido en wp-admin que se pueda olvidar que está prendido.
 */
function talos_modo_pruebas_activo() {
    return (bool) get_option( 'talos_modo_pruebas_activo', 0 );
}
