<?php

namespace WPM\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup language params, locales, configs on load WordPress
 *
 * Class WPM_Setup
 * @package  WPM\Core
 * @author   VaLeXaR
 * @version  1.3.0
 */
class WPM_Setup {

	/**
	 * Original url
	 *
	 * @var string
	 */
	private $original_home_url = '';

	/**
	 * Original uri
	 *
	 * @var string
	 */
	private $site_request_uri = '';

	/**
	 * Default locale
	 *
	 * @var string
	 */
	private $default_locale = '';

	/**
	 * Languages
	 *
	 * @var array
	 */
	private $languages = array();

	/**
	 * Options
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Installed languages
	 *
	 * @var array
	 */
	private $installed_languages = array();

	/**
	 * User language
	 *
	 * @var string
	 */
	private $user_language = '';

	/**
	 * Available translations
	 *
	 * @var array
	 */
	private $translations = array();

	/**
	 * Config
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * The single instance of the class.
	 *
	 * @var WPM_Setup
	 */
	protected static $_instance = null;

	/**
	 * Main WPM_Setup Instance.
	 *
	 * @static
	 * @return WPM_Setup - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * WPM_Setup constructor.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'set_lang_var' ) );
		add_filter( 'option_home', array( $this, 'set_home_url' ), 99 );
		if ( defined( 'DOMAIN_MAPPING' ) ) {
			add_filter( 'pre_option_home', array( $this, 'set_home_url' ), 99 );
		}
		add_action( 'change_locale', array( $this, 'change_locale' ), 0 );
		add_action( 'after_switch_theme', array( __NAMESPACE__ . '\WPM_Config', 'load_config_run' ) );
		add_action( 'activated_plugin', array( __NAMESPACE__ . '\WPM_Config', 'load_config_run' ) );
		add_action( 'upgrader_process_complete', array( __NAMESPACE__ . '\WPM_Config', 'load_config_run' ) );
		add_action( 'wpm_init', array( $this, 'load_vendor' ) );
		add_action( 'template_redirect', array( $this, 'set_not_found' ) );
		add_action( 'plugins_loaded', array( $this, 'set_locale' ), 0 );
		add_action( 'parse_request', array( $this, 'setup_query_var' ), 0 );
		add_action( 'wp', array( $this, 'redirect_to_user_language' ) );
		add_action( 'request', array( $this, 'set_home_page' ) );
	}


	/**
	 * Load options from base
	 *
	 * @return array|string
	 */
	public function get_options() {
		if ( ! $this->options ) {
			$this->options = get_option( 'wpm_languages' );
		}

		return $this->options;
	}


	/**
	 * Get original home url
	 *
	 * @since 1.7.0
	 *
	 * @return string
	 */
	public function get_original_home_url() {
		if ( ! $this->original_home_url ) {
			$this->original_home_url = home_url();
		}

		return $this->original_home_url;
	}


	/**
	 * Get original request url
	 *
	 * @since 1.7.0
	 *
	 * @return string
	 */
	public function get_site_request_uri() {
		return $this->site_request_uri;
	}


	/**
	 * Get installed languages
	 *
	 * @return array
	 */
	public function get_installed_languages() {
		if ( ! $this->installed_languages ) {
			$this->installed_languages = wp_parse_args( get_available_languages(), array( 'en_US' ) );
		}

		return $this->installed_languages;
	}


	/**
	 * Get enables languages. Add installed languages to options.
	 *
	 * @return array
	 */
	public function get_languages() {
		if ( ! $this->languages ) {

			$options             = $this->get_options();
			$installed_languages = $this->get_installed_languages();

			foreach ( $options as $locale => $language ) {
				if ( $language['enable'] ) {
					$this->languages[ $locale ] = $language['slug'];
				}
			}

			foreach ( $installed_languages as $language ) {
				if ( ! isset( $this->languages[ $language ] ) && ! isset( $options[ $language ] ) ) {
					$translations                 = $this->get_translations();
					$this->languages[ $language ] = current( $translations[ $language ]['iso'] );
					$options[ $language ]         = array(
						'name'   => $translations[ $language ]['native_name'],
						'slug'   => current( $translations[ $language ]['iso'] ),
						'flag'   => current( $translations[ $language ]['iso'] ),
						'enable' => 1,
					);
				}
			}

			if ( wpm_array_diff_recursive( $options, $this->get_options() ) ) {
				update_option( 'wpm_languages', $options );
				$this->options = $options;
			}
		}

		return $this->languages;
	}

	/**
	 * Get default locale from options
	 *
	 * @return string
	 */
	public function get_default_locale() {
		if ( ! $this->default_locale ) {
			$this->default_locale = get_option( 'WPLANG' ) ? get_option( 'WPLANG' ) : 'en_US';
		}

		return $this->default_locale;
	}


	/**
	 * Get user language
	 * @return string
	 */
	public function get_user_language() {
		if ( ! $this->user_language ) {
			$this->set_user_language();
		}

		return $this->user_language;
	}

	/**
	 * Set user language for frontend from url or browser
	 * Set admin language from cookie or url
	 */
	public function set_user_language() {

		$languages      = $this->get_languages();
		$default_locale = $this->get_default_locale();
		$url            = '';

		if ( wp_doing_ajax() ) {
			$referrer = wp_get_raw_referer();

			if ( $referrer ) {
				if ( strpos( $referrer, admin_url() ) === false ) {
					$url = $referrer;
					add_filter( 'get_user_metadata', array( $this, 'set_user_locale' ), 10, 4 );
				}
			} else {
				add_filter( 'get_user_metadata', array( $this, 'set_user_locale' ), 10, 4 );
			}
		}

		if ( ! is_admin() ) {
			$url = wpm_get_current_url();
		}

		if ( $url ) {
			$this->site_request_uri = str_replace( $this->get_original_home_url(), '', $url );

			if ( preg_match( '!^/([a-z]{2})(/|$)!i', $this->site_request_uri, $match ) ) {
				$this->user_language = $match[1];
			}
		}

		if ( isset( $_REQUEST['lang'] ) ) {
			$lang = wpm_clean( $_REQUEST['lang'] );
			if ( in_array( $lang, $languages ) ) {
				$this->user_language = $lang;
			}

			if ( is_admin() && ! wp_doing_ajax() ) {
				wpm_setcookie( 'language', $lang, time() + MONTH_IN_SECONDS );
			}
		} else {
			if ( is_admin() && ! wp_doing_ajax() ) {
				if ( isset( $_COOKIE['language'] ) ) {
					$lang = wpm_clean( $_COOKIE['language'] );
					if ( in_array( $lang, $languages, true ) ) {
						$this->user_language = $lang;
					}
				} else {
					wpm_setcookie( 'language', $languages[ $default_locale ], time() + MONTH_IN_SECONDS );
				}
			}
		}
	}


	/**
	 * Set locale from user language
	 */
	public function set_locale() {
		global $locale;

		$languages      = $this->get_languages();
		$default_locale = $this->get_default_locale();

		foreach ( $languages as $key => $value ) {
			$user_language = $this->get_user_language();
			if ( ( $value === $user_language ) ) {
				$locale = $key;
				if ( $key === $default_locale && ! is_admin() && ! isset( $_REQUEST['lang'] ) ) {
					wp_redirect( home_url( str_replace( '/' . $user_language . '/', '/', $this->site_request_uri ) ) );
					exit;
				}
				break;
			}
		}

		if ( ! $this->user_language || ! in_array( $this->user_language, $languages, true ) ) {
			$this->user_language = $languages[ $default_locale ];
		}
	}

	/**
	 * Get available translations
	 * @return array
	 */
	public function get_translations() {

		if ( ! $this->translations ) {
			require_once( ABSPATH . 'wp-admin/includes/translation-install.php' );
			$available_translations          = wp_get_available_translations();
			$available_translations['en_US'] = array(
				'native_name' => 'English (US)',
				'iso'         => array( 'en' ),
			);

			$this->translations = $available_translations;
		}

		return $this->translations;
	}


	/**
	 * Get config from options
	 * @return array
	 */
	public function get_config() {

		if ( ! $this->config ) {
			$config = get_option( 'wpm_config' );
			$config = apply_filters( 'wpm_load_config', $config );

			$posts_config = apply_filters( 'wpm_posts_config', $config['post_types'] );
			$post_types   = get_post_types( '', 'names' );

			foreach ( $post_types as $post_type ) {
				$posts_config[ $post_type ] = apply_filters( "wpm_post_{$post_type}_config", isset( $posts_config[ $post_type ] ) ? $posts_config[ $post_type ] : null );
			}

			$config['post_types'] = $posts_config;

			$taxonomies_config = apply_filters( 'wpm_taxonomies_config', $config['taxonomies'] );
			$taxonomies        = get_taxonomies();

			foreach ( $taxonomies as $taxonomy ) {
				$taxonomies_config[ $taxonomy ] = apply_filters( "wpm_taxonomy_{$taxonomy}_config", isset( $taxonomies_config[ $taxonomy ] ) ? $taxonomies_config[ $taxonomy ] : null );
			}

			$config['taxonomies'] = $taxonomies_config;

			$config['options'] = apply_filters( 'wpm_options_config', $config['options'] );

			if ( is_multisite() ) {
				$config['site_options'] = apply_filters( 'wpm_site_options_config', $config['site_options'] );
			} else {
				unset( $config['site_options'] );
			}

			$config['widgets'] = apply_filters( 'wpm_widgets_config', $config['widgets'] );

			$this->config = $config;
		}

		return $this->config;
	}

	/**
	 * Set global $locale when change locale
	 *
	 * @param $new_locale
	 */
	public function change_locale( $new_locale ) {
		global $locale;
		$locale = $new_locale;
	}


	/**
	 * Add lang slug to home url
	 *
	 * @param $value
	 *
	 * @return string
	 */
	public static function set_home_url( $value ) {

		if ( ( is_admin() && ! wp_doing_ajax() ) || ! did_action( 'wpm_init' ) ) {
			return $value;
		}

		$language       = wpm_get_user_language();
		$languages      = wpm_get_languages();
		$default_locale = wpm_get_default_locale();
		if ( $language !== $languages[ $default_locale ] ) {
			$value .= '/' . $language;
		}

		return $value;
	}


	/**
	 * Add 'lang' param to allow params
	 *
	 * @param $public_query_vars
	 *
	 * @return array
	 */
	public function set_lang_var( $public_query_vars ) {
		$public_query_vars[] = 'lang';

		return $public_query_vars;
	}


	/**
	 * Load vendor classes
	 */
	public function load_vendor() {
		$vendor_path = ( dirname( WPM_PLUGIN_FILE ) . '/core/vendor/' );
		foreach ( glob( $vendor_path . '*.php' ) as $vendor_file ) {
			if ( apply_filters( 'wpm_load_vendor_' . str_replace( '-', '_', basename( $vendor_file, '.php' ) ), true ) ) {
				if ( $vendor_file && is_readable( $vendor_file ) ) {
					include_once( $vendor_file );
				}
			}
		}
	}

	/**
	 * Set 404 headers for not available language
	 */
	public function set_not_found() {
		$languages = $this->get_languages();

		if ( ! in_array( $this->user_language, $languages, true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Add query var 'lang' in global request
	 *
	 * @param $request
	 *
	 * @return object WP
	 */
	public function setup_query_var( $request ) {
		if ( ! empty( $request->query_vars ) ) {
			$request->query_vars['lang'] = $this->get_user_language();
		}

		return $request;
	}

	/**
	 * Redirect to browser language
	 */
	public function redirect_to_user_language() {

		if ( ! is_admin() && ! defined( 'WP_CLI' ) ) {

			if ( ! isset( $_COOKIE['wpm_language'] ) ) {

				wpm_setcookie( 'wpm_language', $this->user_language, time() + YEAR_IN_SECONDS );
				$redirect_to_browser_language = apply_filters( 'wpm_redirect_to_browser_language', true );

				if ( $redirect_to_browser_language ) {

					$browser_language = $this->get_browser_language();
					$default_locale   = $this->get_default_locale();
					$languages        = $this->get_languages();

					if ( $browser_language && ( $browser_language !== $this->user_language ) ) {

						$base_url = $this->get_original_home_url();

						$b_home_url = $base_url . '/' . $browser_language;

						if ( $browser_language === $languages[ $default_locale ] ) {
							$b_home_url = $base_url;
						}

						if ( $this->user_language !== $languages[ $default_locale ] ) {
							$base_url = $base_url . '/' . $this->user_language;
						}

						$url = str_replace( $base_url, $b_home_url, wpm_get_current_url() );

						wp_redirect( $url );
						exit;
					}
				}
			} else {
				if ( $_COOKIE['wpm_language'] != $this->user_language ) {
					wpm_setcookie( 'wpm_language', $this->user_language, time() + YEAR_IN_SECONDS );
					do_action( 'wpm_changed_language' );
				}
			} // End if().
		} // End if().
	}


	/**
	 * Detect browser language
	 *
	 * @return null|string
	 */
	private function get_browser_language() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return null;
		}

		if ( ! preg_match_all( '#([^;,]+)(;[^,0-9]*([0-9\.]+)[^,]*)?#i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		$prefered_languages = array();
		$priority           = 1.0;

		foreach ( $matches as $match ) {
			if ( ! isset( $match[3] ) ) {
				$pr       = $priority;
				$priority -= 0.001;
			} else {
				$pr = floatval( $match[3] );
			}
			$prefered_languages[ str_replace( '-', '_', $match[1] ) ] = $pr;
		}

		arsort( $prefered_languages, SORT_NUMERIC );

		$languages = $this->get_languages();

		foreach ( $prefered_languages as $language => $priority ) {
			if ( in_array( $language, $languages, true ) ) {
				return $language;
			} elseif ( isset( $languages[ $language ] ) ) {
				return $languages[ $language ];
			}
		}

		return null;
	}

	/**
	 * Set user locale for AJAX front requests

	 * @param $check
	 * @param $object_id
	 * @param $meta_key
	 * @param $single
	 *
	 * @return array|string
	 */
	public function set_user_locale( $check, $object_id, $meta_key, $single ) {
		if ( 'locale' == $meta_key ) {
			$locale = get_locale();

			if ( $single ) {
				$check = $locale;
			} else {
				$check = array( $locale );
			}
		}

		return $check;
	}

	/**
	 * Fix home page if isset 'lang' GET parameter
	 *
	 * @param $query_vars
	 *
	 * @return array
	 */
	public function set_home_page( $query_vars ) {
		if ( isset( $_GET['lang'] ) && count( $_GET['lang'] ) == 1 && wp_parse_url( wpm_get_site_request_uri(), PHP_URL_PATH ) == '/' ) {
			$query_vars = array();
		}

		return $query_vars;
	}
}
