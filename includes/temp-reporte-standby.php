<?php
/**
 * =========================================================================
 * TEMPORAL — reporte de registros con Estatus "Standby"
 * =========================================================================
 * Jorge quiere quitar la opción "Standby" de Estatus en Empresas y
 * Contactos (dejar solo Active/Inactive). Antes de tocar el catálogo de
 * ACF, este reporte lista qué registros reales quedarían con un valor
 * huérfano para que Jorge decida a mano a cuál reasignarlos. Se borra
 * este archivo (y su require en talos-core.php) en cuanto se use.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $empresas = get_posts( [
        'post_type'      => 'talos_company',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => [
            [ 'key' => 'company_status', 'value' => 'standby' ],
        ],
    ] );

    $contactos = get_posts( [
        'post_type'      => 'talos_contact',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => [
            [ 'key' => 'contact_status', 'value' => 'standby' ],
        ],
    ] );

    echo '<div class="notice notice-info"><p><strong>TALOS_STANDBY_REPORT</strong></p>';
    echo '<p>Empresas con Estatus "Standby" (' . count( $empresas ) . '):</p><ul>';
    foreach ( $empresas as $empresa ) {
        echo '<li>#' . $empresa->ID . ' — ' . esc_html( $empresa->post_title ) . '</li>';
    }
    echo '</ul>';
    echo '<p>Contactos con Estatus "Standby" (' . count( $contactos ) . '):</p><ul>';
    foreach ( $contactos as $contacto ) {
        echo '<li>#' . $contacto->ID . ' — ' . esc_html( $contacto->post_title ) . '</li>';
    }
    echo '</ul></div>';
} );
