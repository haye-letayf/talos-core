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

        // Delegado sobre el <select> nativo que Select2 deja debajo de su UI:
        // Select2 siempre dispara 'change' ahí para mantener compatibilidad,
        // así que no dependemos de la capa interna de eventos de ACF.
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
