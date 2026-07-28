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