<?php
/**
 * =========================================================================
 * ADMIN: AUTO-RELLENO DE CONCEPTOS EN OPORTUNIDAD
 * =========================================================================
 * Gemelo de admin-autofill-servicios.php, apuntando al repetidor
 * "Conceptos" (quote_items) de una Oportunidad en vez de "Servicios
 * Contratados" de una Empresa. Funciona con el mismo catálogo y el mismo
 * JS porque quote_items reutiliza a propósito los mismos nombres de campo
 * (service_item / service_invoice_description / service_price /
 * service_frequency) que company_services, para que una Oportunidad Ganada
 * pueda copiarse a Servicios sin remapear nombres.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'acf/input/admin_footer', 'talos_autofill_oportunidad_admin_footer' );
function talos_autofill_oportunidad_admin_footer() {
    global $post;

    if ( ! $post || 'talos_opportunity' !== get_post_type( $post ) ) {
        return;
    }

    $servicios = get_posts( [
        'post_type'      => 'talos_service_cat',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ] );

    $catalogo = [];
    foreach ( $servicios as $servicio ) {
        $catalogo[ $servicio->ID ] = [
            'descripcion' => (string) get_field( 'service_description', $servicio->ID ),
            'precio_mxn'  => (float) get_field( 'service_ref_price_mxn', $servicio->ID ),
            'frecuencia'  => (string) get_field( 'service_ref_frequency', $servicio->ID ),
        ];
    }
    ?>
    <script type="text/javascript">
    ( function( $ ) {
        var talosCatalogoServicios = <?php echo wp_json_encode( $catalogo ); ?>;

        $( document ).on( 'change', '.acf-field[data-name="service_item"] select', function() {
            var id = $( this ).val();
            if ( ! id || ! talosCatalogoServicios[ id ] ) {
                return;
            }

            var datos = talosCatalogoServicios[ id ];
            var $fila = $( this ).closest( '.acf-row' );

            var $desc = $fila.find( '.acf-field[data-name="service_invoice_description"] textarea' );
            var $precio = $fila.find( '.acf-field[data-name="service_price"] input[type="number"]' );
            var $frecuencia = $fila.find( '.acf-field[data-name="service_frequency"] select' );

            if ( $desc.length && datos.descripcion ) {
                $desc.val( datos.descripcion ).trigger( 'change' );
            }
            if ( $precio.length && datos.precio_mxn ) {
                $precio.val( datos.precio_mxn ).trigger( 'change' );
            }
            if ( $frecuencia.length && datos.frecuencia ) {
                $frecuencia.val( datos.frecuencia ).trigger( 'change' );
            }
        } );
    } )( jQuery );
    </script>
    <?php
}
