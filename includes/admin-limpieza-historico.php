<?php
/**
 * =========================================================================
 * ADMIN: LIMPIEZA DE INGRESOS/GASTOS (reinicio del histórico financiero)
 * =========================================================================
 * Uso de una sola vez: manda a la Papelera TODOS los talos_income y
 * talos_expense (NO toca talos_service_cat ni talos_company) antes de
 * reimportar todo desde cero con datos consistentes.
 *
 * Por lotes via AJAX, no en una sola peticion -- con ~6,600 posts, hacerlo
 * de un jalon es exactamente lo que congelo el importador de Ingresos
 * Historicos antes. Un clic dispara N lotes chicos hasta terminar.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TALOS_LIMPIEZA_LOTE', 150 );

add_action( 'admin_menu', 'talos_registrar_pagina_limpieza_historico' );
function talos_registrar_pagina_limpieza_historico() {
    add_submenu_page(
        'talos-importar-amex',
        'Limpieza de Histórico',
        'Limpieza de Histórico',
        'manage_options',
        'talos-limpieza-historico',
        'talos_render_pagina_limpieza_historico'
    );
}

function talos_limpieza_historico_conteo() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type IN ('talos_income', 'talos_expense') AND post_status != 'trash'"
    );
}

add_action( 'wp_ajax_talos_limpiar_historico_lote', 'talos_ajax_limpiar_historico_lote' );
function talos_ajax_limpiar_historico_lote() {
    if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'talos_limpieza_historico', 'nonce', false ) ) {
        wp_send_json_error( array( 'mensaje' => 'Sesión inválida.' ), 403 );
    }

    $ids = get_posts( array(
        'post_type'      => array( 'talos_income', 'talos_expense' ),
        'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
        'posts_per_page' => TALOS_LIMPIEZA_LOTE,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ) );

    $procesados = 0;
    foreach ( $ids as $id ) {
        if ( wp_trash_post( $id ) ) {
            $procesados++;
        }
    }

    wp_send_json_success( array(
        'procesados' => $procesados,
        'restantes'  => talos_limpieza_historico_conteo(),
    ) );
}

function talos_render_pagina_limpieza_historico() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para ver esta página.' );
    }

    $total_inicial = talos_limpieza_historico_conteo();
    $nonce         = wp_create_nonce( 'talos_limpieza_historico' );
    ?>
    <div class="wrap">
        <h1>Limpieza de Histórico (Ingresos + Gastos)</h1>
        <p>
            Manda a la <strong>Papelera</strong> (no borra permanente, recuperable 30 días) todos los posts de
            <strong>Ingresos</strong> y <strong>Gastos</strong>. No toca Empresas ni el Catálogo de Servicios.
            Se hace en lotes de <?php echo (int) TALOS_LIMPIEZA_LOTE; ?> para no congelar el servidor.
        </p>

        <div class="notice notice-warning" style="padding: 10px 15px;">
            <p><strong>Hay <span id="talos-limpieza-total"><?php echo (int) $total_inicial; ?></span> posts por limpiar ahora mismo.</strong></p>
        </div>

        <div style="max-width: 500px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; height: 24px; margin: 15px 0; overflow: hidden;">
            <div id="talos-limpieza-barra" style="height: 100%; width: 0%; background: #2271b1; transition: width .2s;"></div>
        </div>
        <p id="talos-limpieza-status">Listo para comenzar.</p>

        <button type="button" class="button button-primary" id="talos-limpieza-boton" <?php echo $total_inicial === 0 ? 'disabled' : ''; ?>>
            Comenzar Limpieza
        </button>

        <script>
        (function() {
            var total = <?php echo (int) $total_inicial; ?>;
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var boton = document.getElementById('talos-limpieza-boton');
            var barra = document.getElementById('talos-limpieza-barra');
            var status = document.getElementById('talos-limpieza-status');
            var totalLabel = document.getElementById('talos-limpieza-total');
            var procesadosTotal = 0;

            function siguienteLote() {
                var fd = new FormData();
                fd.append('action', 'talos_limpiar_historico_lote');
                fd.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) {
                            status.textContent = 'Error: ' + (res.data && res.data.mensaje ? res.data.mensaje : 'desconocido');
                            return;
                        }
                        procesadosTotal += res.data.procesados;
                        var restantes = res.data.restantes;
                        var pct = total > 0 ? Math.min(100, Math.round((procesadosTotal / total) * 100)) : 100;
                        barra.style.width = pct + '%';
                        totalLabel.textContent = restantes;
                        status.textContent = procesadosTotal + ' enviados a la Papelera, ' + restantes + ' restantes...';

                        if (res.data.procesados > 0 && restantes > 0) {
                            siguienteLote();
                        } else {
                            status.textContent = 'Listo. ' + procesadosTotal + ' posts enviados a la Papelera.';
                            boton.disabled = true;
                        }
                    })
                    .catch(function(err) {
                        status.textContent = 'Error de red: ' + err;
                    });
            }

            boton.addEventListener('click', function() {
                boton.disabled = true;
                status.textContent = 'Limpiando...';
                siguienteLote();
            });
        })();
        </script>
    </div>
    <?php
}
