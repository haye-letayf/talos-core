<?php
// =========================================================================
// MÓDULO: CONTACTOS (talos_contact) - Esquema de Base de Datos
// =========================================================================

add_action( 'acf/init', 'talos_registrar_campos_contactos' );

function talos_registrar_campos_contactos() {
    if ( function_exists( 'acf_add_local_field_group' ) ) {

        acf_add_local_field_group( array(
            'key' => 'group_talos_contact_data',
            'title' => 'Detalles del Contacto',
            'fields' => array(
                // 1. Email Principal
                array(
                    'key' => 'field_contact_email',
                    'label' => 'Email Principal',
                    'name' => 'email_principal',
                    'type' => 'email',
                    'required' => 1,
                    'wrapper' => array( 'width' => '50%' ),
                ),
                // 2. Email Secundario
                array(
                    'key' => 'field_contact_email_sec',
                    'label' => 'Email Secundario',
                    'name' => 'email_secundario',
                    'type' => 'email',
                    'wrapper' => array( 'width' => '50%' ),
                ),
                // 3. Relación con Empresa
                array(
                    'key' => 'field_contact_company',
                    'label' => 'Empresa / Cuenta',
                    'name' => 'empresa_id',
                    'type' => 'post_object',
                    'post_type' => array( 'talos_company' ),
                    'return_format' => 'id',
                    'ui' => 1, // Habilita búsqueda con autocompletar
                    'instructions' => 'Selecciona a qué empresa pertenece este contacto.',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'talos_contact',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'seamless',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
        ) );

    }
}