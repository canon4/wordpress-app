<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// Detectar HTTPS cuando WordPress corre detrás de un proxy SSL (ngrok, Load Balancer, etc.)
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
    $_SERVER['HTTPS'] = 'on';
}

// Cargar variables del .env para entornos locales (XAMPP no las carga automáticamente)
$_env_file = __DIR__ . '/.env';
if ( file_exists( $_env_file ) ) {
    foreach ( file( $_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $_line ) {
        if ( $_line[0] === '#' || strpos( $_line, '=' ) === false ) continue;
        [ $_k, $_v ] = explode( '=', $_line, 2 );
        $_k = trim( $_k );
        $_v = trim( $_v );
        if ( ! getenv( $_k ) ) {
            putenv( "{$_k}={$_v}" );
            $_ENV[ $_k ] = $_v;
        }
    }
    unset( $_env_file, $_line, $_k, $_v );
}

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME',     getenv('WORDPRESS_DB_NAME')     ?: 'wooecomerce' );

/** Database username */
define( 'DB_USER',     getenv('WORDPRESS_DB_USER')     ?: 'root' );

/** Database password */
define( 'DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'root' );

/** Database hostname */
define( 'DB_HOST',     getenv('WORDPRESS_DB_HOST')     ?: 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '|F^EKNtcL3d/+6|F%Z?KmU)w=TAPY/slVi|$)TL;=B^O@za p?i<5zapXr!r/zX:' );
define( 'SECURE_AUTH_KEY',  'g4c-~c.l@`#!,#ILaf]Pc`8jD-w@%3,3A}{+$^.1rAcN.) +8=V&|>]*u_3r4<fs' );
define( 'LOGGED_IN_KEY',    'DSe*6z1%LnCFcl:$@WwZa!W6(UGvYdS@_^!n Yz9^!S8)ZGC:0K^=GN2a lJ77O/' );
define( 'NONCE_KEY',        ', Ond0.Q$EGfO(_SE_xe}dpLG@8lg98lS8p5]!OF16fq<I(JZ2-)pEyzg$[PkNmr' );
define( 'AUTH_SALT',        'C3gf)tEEWLN1?B($pL~6b-~%wt)9`b .zPqs}/J,3r~fTs5w` xECq^iQ(xOU3MP' );
define( 'SECURE_AUTH_SALT', 'MaH)>Hv:P2W@po5sb(BBo)N-3=0PA>Dfe@qJj[0<)uy(eZ>#`h5%C7[YI`[&5mWI' );
define( 'LOGGED_IN_SALT',   '<O(;_hMg5jHhE7@Q+-O LLm[&/P[nC)U*O0*i+QnCjyYlmLjl6~6zc+^inVfnUqt' );
define( 'NONCE_SALT',       'V(53txDj<H$V56GS9k~yfTgv;#`({nnHQA/ld=U9dQ,a`J#`hf+0QEg;{DB%4r):' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */

if ( getenv('WP_HOME') ) {
    define( 'WP_HOME',    getenv('WP_HOME') );
    define( 'WP_SITEURL', getenv('WP_SITEURL') ?: getenv('WP_HOME') );
}

// COOKIE_DOMAIN dinámico: se ajusta al host actual (localhost o el túnel ngrok)
if ( getenv('WP_HOME') ) {
    define( 'COOKIE_DOMAIN', parse_url( getenv('WP_HOME'), PHP_URL_HOST ) );
} else {
    define( 'COOKIE_DOMAIN', 'localhost' );
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
