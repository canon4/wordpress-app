<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

// Adds theme support for post formats.
if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

// Enqueues editor-style.css in the editors.
if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style() {
		add_editor_style( 'assets/css/editor-style.css' );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

// Enqueues the theme stylesheet on the front.
if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	/**
	 * Enqueues the theme stylesheet on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';
		$src    = 'style' . $suffix . '.css';

		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri( $src ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
		wp_style_add_data(
			'twentytwentyfive-style',
			'path',
			get_parent_theme_file_path( $src )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

// Enqueue Tailwind CSS CDN and Material Symbols required for Amazonia Header
function amazonia_enqueue_assets() {
	// Tailwind CSS via CDN (Only recommended for development/testing)
	wp_enqueue_script( 'tailwindcss', 'https://cdn.tailwindcss.com', array(), null, false );

	// Material Symbols Outlined
	wp_enqueue_style( 'material-symbols-outlined', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200', array(), null );
	
	// Configuración de colores de Tailwind para el Header
	$tailwind_config = "
		tailwind.config = {
			darkMode: 'class',
			theme: {
				extend: {
					colors: {
						primary: '#2E7D32',
						'primary-light': '#4CAF50',
						'primary-dark': '#1B5E20',
						'background-dark': '#0f172a'
					}
				}
			}
		}
	";
	wp_add_inline_script( 'tailwindcss', $tailwind_config, 'after' );
}
add_action( 'wp_enqueue_scripts', 'amazonia_enqueue_assets', 5 );

// Registers custom block styles.
if ( ! function_exists( 'twentytwentyfive_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfive' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_block_styles' );

// Registers pattern categories.
if ( ! function_exists( 'twentytwentyfive_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfive' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfive' ),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label'       => __( 'Post formats', 'twentytwentyfive' ),
				'description' => __( 'A collection of post format patterns.', 'twentytwentyfive' ),
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_pattern_categories' );

// Registers block binding sources.
if ( ! function_exists( 'twentytwentyfive_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings() {
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive' ),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_register_block_bindings' );

// Registers block binding callback function for the post format name.
if ( ! function_exists( 'twentytwentyfive_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;

// Register Amazonia Header Shortcode
function amazonia_header_shortcode() {
	$cart_count = 0;
	if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
		$cart_count = WC()->cart->get_cart_contents_count();
	}
	$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#';
	$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '#';
	$home_url = home_url( '/' );

	ob_start();
	?>
	<header class="sticky top-0 z-50 bg-white/80 dark:bg-background-dark/80 backdrop-blur-md border-b border-primary/10 px-4 md:px-10 lg:px-20 py-3 w-full">
	<div class="max-w-[1440px] mx-auto flex items-center justify-between gap-8">
	<div class="flex items-center gap-8">
	<a href="<?php echo esc_url($home_url); ?>" class="flex items-center gap-2 text-primary no-underline">
	<span class="material-symbols-outlined text-3xl font-bold">eco</span>
	<h2 class="text-slate-900 dark:text-slate-100 text-xl font-black leading-tight tracking-tight mb-0">Amazonia Market</h2>
	</a>
	<nav class="hidden lg:flex items-center gap-8">
	<a class="text-slate-600 dark:text-slate-400 text-sm font-medium hover:text-primary transition-colors no-underline" href="#">Categorías</a>
	<a class="text-slate-600 dark:text-slate-400 text-sm font-medium hover:text-primary transition-colors no-underline" href="#">Comunidades</a>
	<a class="text-slate-600 dark:text-slate-400 text-sm font-medium hover:text-primary transition-colors no-underline" href="#">Impacto</a>
	</nav>
	</div>
	<div class="flex flex-1 justify-end items-center gap-4">
	<form action="<?php echo esc_url($home_url); ?>" method="get" class="hidden md:flex flex-col min-w-40 h-10 max-w-md w-full m-0">
	<div class="flex w-full flex-1 items-stretch rounded-full h-full bg-primary/5 border border-primary/10 overflow-hidden">
	<button type="submit" class="text-primary/60 flex items-center justify-center pl-4 bg-transparent border-none cursor-pointer">
	<span class="material-symbols-outlined">search</span>
	</button>
	<input type="search" name="s" class="w-full bg-transparent border-none focus:ring-0 text-sm placeholder:text-primary/40 px-3 m-0" placeholder="Buscar aceites, semillas, artesanías..." value="<?php echo get_search_query(); ?>"/>
	<?php if (class_exists('WooCommerce')): ?>
		<input type="hidden" name="post_type" value="product" />
	<?php endif; ?>
	</div>
	</form>
	<div class="flex items-center gap-2">
	<a href="#" class="flex items-center justify-center rounded-full h-10 w-10 bg-primary/10 text-slate-900 dark:text-slate-100 hover:bg-primary hover:text-white transition-all no-underline">
	<span class="material-symbols-outlined text-[20px]">favorite</span>
	</a>
	<a href="<?php echo esc_url($cart_url); ?>" class="flex items-center justify-center rounded-full h-10 w-10 bg-primary/10 text-slate-900 dark:text-slate-100 hover:bg-primary hover:text-white transition-all relative no-underline" title="Ver Carrito">
	<span class="material-symbols-outlined text-[20px]">shopping_cart</span>
	<span class="absolute -top-1 -right-1 bg-primary text-[10px] font-bold text-white h-4 w-4 rounded-full flex items-center justify-center amazonia-cart-count <?php echo ($cart_count > 0) ? '' : 'hidden'; ?>"><?php echo esc_html($cart_count); ?></span>
	</a>
	<a href="<?php echo esc_url($account_url); ?>" class="flex items-center justify-center rounded-full h-10 w-10 bg-primary/10 text-slate-900 dark:text-slate-100 no-underline" title="Mi Cuenta">
	<span class="material-symbols-outlined text-[24px]">account_circle</span>
	</a>
	</div>
	</div>
	</div>
	</header>
	<?php
	return ob_get_clean();
}
add_shortcode( 'amazonia_header', 'amazonia_header_shortcode' );

// AJAX fragments for WooCommerce to update the custom header cart count
function amazonia_cart_count_fragments( $fragments ) {
	if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
		$cart_count = WC()->cart->get_cart_contents_count();
		ob_start();
		?>
		<span class="absolute -top-1 -right-1 bg-primary text-[10px] font-bold text-white h-4 w-4 rounded-full flex items-center justify-center amazonia-cart-count <?php echo ($cart_count > 0) ? '' : 'hidden'; ?>"><?php echo esc_html($cart_count); ?></span>
		<?php
		$fragments['span.amazonia-cart-count'] = ob_get_clean();
	}
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'amazonia_cart_count_fragments' );

