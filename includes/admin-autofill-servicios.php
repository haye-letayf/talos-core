<?php
/**
 * =========================================================================
 * ADMIN: AUTO-RELLENO DE SERVICIOS EN EMPRESA
 * =========================================================================
 * Al elegir un Servicio en el repetidor "Servicios Contratados" de una
 * Empresa, precarga Descripción / Precio (MXN) / Frecuencia desde el
 * catálogo (talos_service_cat) como punto de partida — Jorge ajusta
 * manualmente si ese cliente en particular necesita un precio o texto
 * distinto. Solo aplica al precio en MXN; el de USD queda como referencia
 * de consulta, sin auto-relleno (company_services no distingue moneda).
 *
 * Es un catálogo pequeño incrustado directo en la página (sin llamada
 * AJAX por cada selección) — WPO, y evita depender de nonces extra para
 * algo que no escribe nada en el servidor.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'acf/input/admin_footer', 'talos_autofill_servicios_admin_footer' );
function talos_autofill_servicios_admin_footer() {
    global $post;

    if ( ! $post || 'talos_company' !== get_post_type( $post ) ) {
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

        if ( typeof acf === 'undefined' ) {
            return;
        }

        acf.addAction( 'change_field/name=service_item', function( field ) {
            var id = field.val();
            if ( ! id || ! talosCatalogoServicios[ id ] ) {
                return;
            }

            var datos = talosCatalogoServicios[ id ];
            var $fila = field.$el.closest( '.acf-row' );

            var descField  = acf.getField( $fila.find( '.acf-field[data-name="service_invoice_description"]' ) );
            var priceField = acf.getField( $fila.find( '.acf-field[data-name="service_price"]' ) );
            var freqField  = acf.getField( $fila.find( '.acf-field[data-name="service_frequency"]' ) );

            if ( descField && datos.descripcion ) {
                descField.val( datos.descripcion );
            }
            if ( priceField && datos.precio_mxn ) {
                priceField.val( datos.precio_mxn );
            }
            if ( freqField && datos.frecuencia ) {
                freqField.val( datos.frecuencia );
            }
        } );
    } )( jQuery );
    </script>
    <?php
}
