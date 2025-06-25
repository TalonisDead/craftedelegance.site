<?php
define('WP_CACHE', true);

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
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'handmade' );

/** Database username */
define( 'DB_USER', 'long' );

/** Database password */
define( 'DB_PASSWORD', '123456' );

/** Database hostname */
define( 'DB_HOST', 'db:3306' );

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
define( 'AUTH_KEY',         '1LY1/Cq7qu3Szj`*`hw[$docL&X~GztxAA@jue!kDH#>7f^}ls!EXOa(DFM#@Wtq' );
define( 'SECURE_AUTH_KEY',  'Cfekohw%qut u[I;Ds68X]_[x/[c7D~M5.P~G&#/5$;<WgkJ>7Utw!1T}*.bHM6Q' );
define( 'LOGGED_IN_KEY',    'lf/h@tAGqy12F-5YbHA>P^aPp!,cp~<w4=R@jw0F[(,>+@0*#WDjqjlZogmv4=lQ' );
define( 'NONCE_KEY',        '*,i Ua6 qD,4eV)9ftM64v:EgHH&W-TM8`FjmT^^*tc#X1w;i+te{sTK<g:Sq?/ ' );
define( 'AUTH_SALT',        'au[G.c !3F e)xhCU@H;h/3/X~rZrmya>XUWNA7/kWOguQj`KUj,03w*6H ,I#%c' );
define( 'SECURE_AUTH_SALT', '|}jm{m`+?4IO$XTXl/q@I..^0y)xQPb)S{A08u~3R>7#48vS0nwZd6mR</w+XX<Y' );
define( 'LOGGED_IN_SALT',   '0(n5+YC#&L.l|(K*`k{Hy73 `V|#b28QaYhv!oYvL2vV${6O2&}|u_Vkb7z|KhgG' );
define( 'NONCE_SALT',       '-0CG{/<9( ZF2.Ar@N&#Y_7&OwTqFIR|R[i7%ZL:s8u#a4W[VVov2$W;;sd?g.N=' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
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
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );

/* Add any custom values between this line and the "stop editing" line. */
define ( 'WP_MEMORY_LIMIT','384M');
define('WP_HOME', 'https://craftedelegance.site');
define('WP_SITEURL', 'https://craftedelegance.site');
/*
define('WP_HOME', 'http://34.57.153.220');
define('WP_SITEURL', 'http://34.57.153.220');
*/
define('FORCE_SSL_ADMIN', true);
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}


define( 'DUPLICATOR_AUTH_KEY', 'Ay&b=hM(r-:}(SjJRbl!u3jwB9oSM-2CE)R8@^ mPKy]^1cdp6e!(#mH)^vASJu4' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
