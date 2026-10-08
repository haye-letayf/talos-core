<?php
/**
 * Rol "Consulta (Talos)": acceso de solo lectura para alguien que necesita ver
 * Empresas/Contactos/Bitácora sin tocar nada financiero ni editar datos (pensado
 * para Fer/Dany). add_role() no hace nada si el rol ya existe, así que es seguro
 * dejarlo corriendo en cada carga. El front (talos-theme) usa manage_options como
 * criterio de "acceso completo" — mismo patrón ya usado ahí para botones admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function talos_registrar_rol_consulta() {
    add_role( 'talos_consulta', 'Consulta (Talos)', array(
        'read' => true,
    ) );
}
add_action( 'init', 'talos_registrar_rol_consulta' );
