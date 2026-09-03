<?php
/**
 * =========================================================================
 * SEED: CATÁLOGO DE SERVICIOS
 * =========================================================================
 * Inyecta, UNA SOLA VEZ, los 28 servicios base como posts `talos_service_cat`.
 *
 * Movido aquí desde talos-theme/functions.php: es lógica de negocio (siembra
 * de datos), y por Regla de Oro del proyecto nunca debe vivir en el tema —
 * si el tema cambia o se desactiva, esto debe seguir funcionando intacto.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'talos_inyectar_servicios_masivos' );
function talos_inyectar_servicios_masivos() {

    // Candado: si ya se ejecutó una vez (aquí o en su versión anterior del tema), no se repite.
    if ( get_option( 'talos_servicios_importados_v1' ) ) {
        return;
    }

    $servicios = [
        'Admin. Campaña META', 'Admin. Campaña GADS', 'Admin. Campaña LinkedIn',
        'Admin. Redes Sociales', 'Campaign GADS', 'Campaign INDEED',
        'Campaign LinkedIn', 'Campaign META', 'Casillas de Correo',
        'Conferencia Presencial', 'Consultoría', 'Desarrollo Personalizado',
        'Diseño Especial', 'Dominio', 'GTM+GADS Setup', 'Hosting',
        'Hosting+Webmaster', 'Landing Page', 'Licencia Plugin', 'META Setup',
        'OpenREAL Licence', 'OpenREAL Setup', 'OpenREAL Website', 'Página Web',
        'Plan Esencial', 'Tienda en Línea', 'Webinar', 'Webmaster'
    ];

    foreach ( $servicios as $titulo ) {
        wp_insert_post( array(
            'post_title'  => $titulo,
            'post_type'   => 'talos_service_cat',
            'post_status' => 'publish',
        ) );
    }

    update_option( 'talos_servicios_importados_v1', true );
}
