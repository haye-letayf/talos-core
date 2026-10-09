<?php
/**
 * Campos ACF de Bitácora de Peticiones (talos_request), registrados por
 * código en vez de JSON local. Se intentó primero como acf-json/
 * bitacora_peticiones.json (mismo mecanismo que Empresas/Contactos/Equipo)
 * pero en el servidor de Jorge nunca llegó a reconocerse — el CPT se veía
 * bien en wp-admin pero la pantalla de edición solo mostraba el título, sin
 * ningún campo. Se descarta el JSON y se registra directo via
 * acf_add_local_field_group(), mismo patrón que ya usa talos-core.php para
 * la página de opciones "Reglas de Clasificación AMEX" — así no depende de
 * que ACF logre leer un archivo del disco en ese hosting en particular.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'acf/init', 'talos_registrar_campos_bitacora' );
function talos_registrar_campos_bitacora() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    acf_add_local_field_group( array(
        'key'    => 'group_b17c900000001',
        'title'  => 'Detalles de Petición',
        'fields' => array(
            array(
                'key'            => 'field_b17c900000002',
                'label'          => 'Empresa',
                'name'           => 'request_company',
                'type'           => 'post_object',
                'required'       => 1,
                'post_type'      => array( 'talos_company' ),
                'return_format'  => 'object',
                'multiple'       => 0,
                'allow_null'     => 0,
                'ui'             => 1,
            ),
            array(
                'key'            => 'field_b17c900000003',
                'label'          => 'Tipo de Solicitud',
                'name'           => 'request_type',
                'type'           => 'select',
                'required'       => 1,
                'choices'        => array(
                    'calendario'          => 'Cambios a Calendario',
                    'campana_patrocinada' => 'Cambio en Campaña Patrocinada',
                    'diseno_redes'        => 'Diseño Especial para Redes',
                    'diseno_impresion'    => 'Diseño Especial para Impresión',
                    'sitio_web'           => 'Cambio o Ajuste a Sitio Web',
                    'otro'                => 'Otro',
                ),
                'return_format'  => 'value',
            ),
            array(
                'key'               => 'field_b17c900000004',
                'label'             => 'Especifica el tipo de solicitud',
                'name'              => 'request_type_other',
                'type'              => 'text',
                'conditional_logic' => array(
                    array(
                        array(
                            'field'    => 'field_b17c900000003',
                            'operator' => '==',
                            'value'    => 'otro',
                        ),
                    ),
                ),
            ),
            array(
                'key'          => 'field_b17c900000005',
                'label'        => 'Descripción de lo Solicitado',
                'name'         => 'request_description',
                'type'         => 'textarea',
                'instructions' => 'Redacta exactamente lo que pide el cliente, de forma concreta.',
                'required'     => 1,
                'rows'         => 4,
            ),
            array(
                'key'            => 'field_b17c900000006',
                'label'          => 'Prioridad',
                'name'           => 'request_priority',
                'type'           => 'select',
                'required'       => 1,
                'choices'        => array(
                    'urgente' => 'Urgente (menos de 24 hrs)',
                    'media'   => 'Media (1 a 3 días)',
                    'baja'    => 'Baja (3 a 6 días)',
                    'otra'    => 'Otra',
                ),
                'return_format'  => 'value',
            ),
            array(
                'key'               => 'field_b17c900000007',
                'label'             => 'Especifica la prioridad',
                'name'              => 'request_priority_other',
                'type'              => 'text',
                'conditional_logic' => array(
                    array(
                        array(
                            'field'    => 'field_b17c900000006',
                            'operator' => '==',
                            'value'    => 'otra',
                        ),
                    ),
                ),
            ),
            array(
                'key'            => 'field_b17c900000008',
                'label'          => 'Fecha de Solicitud',
                'name'           => 'request_date',
                'type'           => 'date_picker',
                'instructions'   => 'Cuándo la pidió el cliente — hoy o una fecha anterior, nunca futura.',
                'required'       => 1,
                'display_format' => 'd/m/Y',
                'return_format'  => 'Y-m-d',
                'first_day'      => 1,
            ),
            array(
                'key'            => 'field_b17c90000000b',
                'label'          => 'Fecha de Entrega',
                'name'           => 'request_completed_date',
                'type'           => 'date_picker',
                'instructions'   => 'Se registra sola el día que la petición se mueve a Completada — no se captura a mano.',
                'display_format' => 'd/m/Y',
                'return_format'  => 'Y-m-d',
                'first_day'      => 1,
            ),
            array(
                'key'           => 'field_b17c900000009',
                'label'         => 'Estatus',
                'name'          => 'request_status',
                'type'          => 'select',
                'choices'       => array(
                    'pendiente'  => 'Pendiente',
                    'en_proceso' => 'En Proceso',
                    'completada' => 'Completada',
                ),
                'default_value' => 'pendiente',
                'return_format' => 'value',
            ),
            array(
                'key'          => 'field_b17c90000000a',
                'label'        => 'Evidencia (capturas de pantalla)',
                'name'         => 'request_evidence',
                'type'         => 'gallery',
                'instructions' => 'Se sube desde wp-admin, igual que las redes sociales de Empresas — no está en el panel de Talos todavía.',
                'return_format' => 'array',
                'library'      => 'all',
                'max'          => 3,
                'max_size'     => '2',
                'mime_types'   => 'jpg,jpeg,png,gif',
            ),
        ),
        'location' => array(
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'talos_request',
                ),
            ),
        ),
        'active' => true,
    ) );
}
