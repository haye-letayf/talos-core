<?php
/**
 * Plugin Name: Talos Core ERP
 * Description: Infraestructura central, Custom Post Types y lógica relacional para Talos 2.0.
 * Version: 2.0.0
 * Author: Once24
 */

// Seguridad: Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================================
 * 1. REGISTRO DE MÓDULOS BASE (CUSTOM POST TYPES)
 * =========================================================================
 */
add_action( 'init', 'talos_register_core_modules' );
function talos_register_core_modules() {
    
    // Nuestro mapa definitivo de módulos
    $modules = [
        'talos_company'     => ['plural' => 'Empresas', 'singular' => 'Empresa', 'icon' => 'dashicons-building'],
        'talos_contact'     => ['plural' => 'Contactos', 'singular' => 'Contacto', 'icon' => 'dashicons-id-alt'],
        'talos_service_cat' => ['plural' => 'Cat. Servicios', 'singular' => 'Servicio', 'icon' => 'dashicons-hammer'],
        'talos_team'        => ['plural' => 'Equipo', 'singular' => 'Miembro', 'icon' => 'dashicons-groups'],
        'talos_expense'     => ['plural' => 'Gastos', 'singular' => 'Gasto', 'icon' => 'dashicons-cart'],
        'talos_income'      => ['plural' => 'Ingresos', 'singular' => 'Ingreso', 'icon' => 'dashicons-money-alt'],
    ];

    foreach ( $modules as $slug => $labels ) {
        $args = [
            'labels' => [
                'name'          => $labels['plural'],
                'singular_name' => $labels['singular'],
                'menu_name'     => $labels['plural'],
                'all_items'     => 'Todos (' . $labels['plural'] . ')',
                'add_new'       => 'Añadir Nuevo',
                'add_new_item'  => 'Añadir ' . $labels['singular'],
            ],
            'public'              => false, // Es un ERP interno, no queremos que Google los indexe
            'show_ui'             => true,  // Mostrar en el panel de admin
            'show_in_menu'        => true,
            'supports'            => ['title'], // Solo ocupamos el título, ACF se encarga del resto
            'menu_icon'           => $labels['icon'],
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false, // Apagamos Gutenberg para máxima velocidad
        ];

        // Regla especial: Empresas deben ser jerárquicas (para tener matriz/sucursales)
        if ( $slug === 'talos_company' ) {
            $args['hierarchical'] = true;
            $args['supports'][]   = 'page-attributes'; 
        }

        register_post_type( $slug, $args );
    }
}

/**
 * =========================================================================
 * 2. CONFIGURACIÓN ACF LOCAL JSON (WPO EXTREMO)
 * =========================================================================
 */

// Guardar campos en nuestro plugin en vez de la base de datos
add_filter('acf/settings/save_json', 'talos_acf_json_save_point');
function talos_acf_json_save_point( $path ) {
    return plugin_dir_path( __FILE__ ) . 'acf-json';
}

// Cargar campos desde nuestro plugin
add_filter('acf/settings/load_json', 'talos_acf_json_load_point');
function talos_acf_json_load_point( $paths ) {
    unset($paths[0]); // Eliminamos la ruta por defecto del theme
    $paths[] = plugin_dir_path( __FILE__ ) . 'acf-json';
    return $paths;
}

/**
 * =========================================================================
 * 3. AUTOMATIZACIONES Y CÁLCULOS (MÓDULO EMPRESAS)
 * =========================================================================
 */
add_action('acf/save_post', 'talos_calcular_utilidad_empresa', 20);
function talos_calcular_utilidad_empresa( $post_id ) {
    
    // Solo ejecutamos esto si estamos guardando un post del tipo "Empresa"
    if ( get_post_type($post_id) !== 'talos_company' ) {
        return;
    }

    // Obtenemos todas las filas del repetidor de servicios de esta empresa
    $servicios = get_field('company_services', $post_id);
    
    // Si la empresa tiene servicios contratados, hacemos la matemática
    if ( $servicios ) {
        $hubo_cambios = false;
        
        // Recorremos cada servicio uno por uno
        foreach ( $servicios as $index => $row ) {
            $precio = (float) $row['service_price'];
            $costo  = (float) $row['service_cost'];
            $utilidad_calculada = $precio - $costo;
            
            // Verificamos si la utilidad necesita actualizarse para no hacer guardados innecesarios (WPO)
            if ( !isset($row['service_utility']) || (float) $row['service_utility'] !== $utilidad_calculada ) {
                $servicios[$index]['service_utility'] = $utilidad_calculada;
                $hubo_cambios = true;
            }
        }
        
        // Si calculamos utilidades nuevas, actualizamos el repetidor completo de forma silenciosa
        if ( $hubo_cambios ) {
            update_field('company_services', $servicios, $post_id);
        }
    }
}

/**
 * =========================================================================
 * 4. SINCRONIZACIÓN CASCADA: EMPRESA -> CONTACTOS
 * =========================================================================
 */
add_action('acf/save_post', 'talos_sincronizar_empresa_contactos', 20);
function talos_sincronizar_empresa_contactos( $post_id ) {
    
    // Solo ejecutamos esto si estamos guardando un post del tipo "Empresa"
    if ( get_post_type($post_id) !== 'talos_company' ) {
        return;
    }

    // 1. Obtenemos el estatus y clase recién guardados en la Empresa
    $nuevo_estatus = get_field('company_status', $post_id);
    $nueva_clase   = get_field('company_class', $post_id);

    // 2. Buscamos todos los contactos que tengan esta empresa asignada
    $args = array(
        'post_type'      => 'talos_contact',
        'posts_per_page' => -1, // Traer todos sin límite
        'meta_query'     => array(
            array(
                'key'     => 'contact_company', // Tu campo relacional en Contactos
                'value'   => $post_id,          // El ID de esta empresa
                'compare' => '='
            )
        )
    );
    
    $contactos = get_posts($args);

    // 3. Si encontramos contactos, los recorremos y los actualizamos
    if ( $contactos ) {
        foreach ( $contactos as $contacto ) {
            // update_field es silencioso y ultra rápido
            update_field('contact_status', $nuevo_estatus, $contacto->ID);
            update_field('contact_class', $nueva_clase, $contacto->ID);
        }
    }
}