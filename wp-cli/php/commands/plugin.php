<?php

use \WP_CLI\Utils;

/**
 * Manage plugins.
 *
 * @package wp-cli
 */
class Plugin_Command extends \WP_CLI\CommandWithUpgrade {

	protected $item_type = 'plugin';
	protected $upgrade_refresh = 'wp_update_plugins';
	protected $upgrade_transient = 'update_plugins';

	protected $obj_fields = array(
		'name',
		'status',
		'update',
		'version'
	);

	function __construct() {
		require_once ABSPATH.'wp-admin/includes/plugin.php';
		require_once ABSPATH.'wp-admin/includes/plugin-install.php';

		parent::__construct();

		$this->fetcher = new \WP_CLI\Fetchers\Plugin;
	}

	protected function get_upgrader_class( $force ) {
		return $force ? '\\WP_CLI\\DestructivePluginUpgrader' : 'Plugin_Upgrader';
	}

	/**
	 * See the status of one or all plugins.
	 *
	 * ## OPTIONS
	 *
	 * [<plugin>]
	 * : A particular plugin to show the status for.
	 */
	function status( $args ) {
		parent::status( $args );
	}

	/**
	 * Search the wordpress.org plugin repository.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : The string to search for.
	 *
	 * [--per-page=<per-page>]
	 * : Optional number of results to display. Defaults to 10.
	 *
	 * [--field=<field>]
	 * : Prints the value of a single field for each plugin.
	 *
	 * [--fields=<fields>]
	 * : Ask for specific fields from the API. Defaults to name,slug,author_profile,rating. Acceptable values:
	 *
	 *     **name**: Plugin Name
	 *     **slug**: Plugin Slug
	 *     **version**: Current Version Number
	 *     **author**: Plugin Author
	 *     **author_profile**: Plugin Author Profile
	 *     **contributors**: Plugin Contributors
	 *     **requires**: Plugin Minimum Requirements
	 *     **tested**: Plugin Tested Up To
	 *     **compatibility**: Plugin Compatible With
	 *     **rating**: Plugin Rating
	 *     **num_ratings**: Number of Plugin Ratings
	 *     **homepage**: Plugin Author's Homepage
	 *     **description**: Plugin's Description
	 *     **short_description**: Plugin's Short Description
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin search dsgnwrks --per-page=20 --format=json
	 *
	 *     wp plugin search dsgnwrks --fields=name,version,slug,rating,num_ratings
	 */
	public function search( $args, $assoc_args ) {
		parent::_search( $args, $assoc_args );
	}

	protected function status_single( $args ) {
		$plugin = $this->fetcher->get_check( $args[0] );
		$file = $plugin->file;

		$details = $this->get_details( $file );

		$status = $this->format_status( $this->get_status( $file ), 'long' );

		$version = $details['Version'];

		if ( $this->has_update( $file ) )
			$version .= ' (%gUpdate available%n)';

		echo WP_CLI::colorize( \WP_CLI\Utils\mustache_render( 'plugin-status.mustache', array(
			'slug' => Utils\get_plugin_name( $file ),
			'status' => $status,
			'version' => $version,
			'name' => $details['Name'],
			'author' => $details['Author'],
			'description' => $details['Description']
		) ) );
	}

	protected function get_all_items() {
		$items = $this->get_item_list();

		foreach ( get_mu_plugins() as $file => $mu_plugin ) {
			$items[ $file ] = array(
				'name' => Utils\get_plugin_name( $file ),
				'status' => 'must-use',
				'update' => false
			);
		}

		return $items;
	}

	/**
	 * Activate a plugin.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>...
	 * : One or more plugins to activate.
	 *
	 * [--network]
	 * : If set, the plugin will be activated for the entire multisite network.
	 */
	function activate( $args, $assoc_args = array() ) {
		$network_wide = isset( $assoc_args['network'] );

		foreach ( $this->fetcher->get_many( $args ) as $plugin ) {
			activate_plugin( $plugin->file, '', $network_wide );

			$this->active_output( $plugin->name, $plugin->file, $network_wide, "activate" );
		}
	}

	/**
	 * Deactivate a plugin.
	 *
	 * ## OPTIONS
	 *
	 * [<plugin>...]
	 * : One or more plugins to deactivate.
	 *
	 * [--all]
	 * : If set, all plugins will be deactivated.
	 *
	 * [--network]
	 * : If set, the plugin will be deactivated for the entire multisite network.
	 */
	function deactivate( $args, $assoc_args = array() ) {
		$network_wide = isset( $assoc_args['network'] );
		$disable_all = isset( $assoc_args['all'] );

		if ( $disable_all ) {
			foreach ( get_plugins() as $file => $details ) {
				if ( $this->get_status( $file ) == "inactive" )
					continue;

				$name = Utils\get_plugin_name( $file );

				deactivate_plugins( $file, false, $network_wide );

				$this->active_output( $name, $file, $network_wide, "deactivate" );
			}
		} else {
			foreach ( $this->fetcher->get_many( $args ) as $plugin ) {
				deactivate_plugins( $plugin->file, false, $network_wide );

				$this->active_output( $plugin->name, $plugin->file, $network_wide, "deactivate" );
			}
		}
	}

	/**
	 * Toggle a plugin's activation state.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>...
	 * : One or more plugins to toggle.
	 *
	 * [--network]
	 * : If set, the plugin will be toggled for the entire multisite network.
	 */
	function toggle( $args, $assoc_args = array() ) {
		$network_wide = isset( $assoc_args['network'] );

		foreach ( $this->fetcher->get_many( $args ) as $plugin ) {
			if ( $this->check_active( $plugin->file, $network_wide ) ) {
				$this->deactivate( array( $plugin->name ), $assoc_args );
			} else {
				$this->activate( array( $plugin->name ), $assoc_args );
			}
		}
	}

	/**
	 * Get the path to a plugin or to the plugin directory.
	 *
	 * ## OPTIONS
	 *
	 * [<plugin>]
	 * : The plugin to get the path to. If not set, will return the path to the
	 * plugins directory.
	 *
	 * [--dir]
	 * : If set, get the path to the closest parent directory, instead of the
	 * plugin file.
	 *
	 * ## EXAMPLES
	 *
	 *     cd $(wp plugin path)
	 */
	function path( $args, $assoc_args ) {
		$path = untrailingslashit( WP_PLUGIN_DIR );

		if ( !empty( $args ) ) {
			$plugin = $this->fetcher->get_check( $args[0] );
			$path .= '/' . $plugin->file;

			if ( isset( $assoc_args['dir'] ) )
				$path = dirname( $path );
		}

		WP_CLI::line( $path );
	}

	protected function install_from_repo( $slug, $assoc_args ) {
		$api = plugins_api( 'plugin_information', array( 'slug' => $slug ) );

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		if ( isset( $assoc_args['version'] ) ) {
			self::alter_api_response( $api, $assoc_args['version'] );
		}

		$status = install_plugin_install_status( $api );

		if ( !isset( $assoc_args['force'] ) && 'install' != $status['status'] ) {
			// We know this will fail, so avoid a needless download of the package.
			return new WP_Error( 'already_installed', 'Plugin already installed.' );
		}

		WP_CLI::log( sprintf( 'Installing %s (%s)', $api->name, $api->version ) );
		if ( !isset( $assoc_args['version'] ) || 'dev' !== $assoc_args['version'] ) {
			WP_CLI::get_http_cache_manager()->whitelist_package( $api->download_link, $this->item_type, $api->slug, $api->version );
		}
		$result = $this->get_upgrader( $assoc_args )->install( $api->download_link );

		return $result;
	}

	/**
	 * Update one or more plugins.
	 *
	 * ## OPTIONS
	 *
	 * [<plugin>...]
	 * : One or more plugins to update.
	 *
	 * [--all]
	 * : If set, all plugins that have updates will be updated.
	 *
	 * [--version=<version>]
	 * : If set, the plugin will be updated to the latest development version,
	 * regardless of what version is currently installed.
	 *
	 * [--dry-run]
	 * : Preview which plugins would be updated.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin update bbpress --version=dev
	 *
	 *     wp plugin update --all
	 */
	function update( $args, $assoc_args ) {
		if ( isset( $assoc_args['version'] ) && 'dev' == $assoc_args['version'] ) {
			foreach ( $this->fetcher->get_many( $args ) as $plugin ) {
				$this->_delete( $plugin );
				$this->install( array( $plugin->name ), $assoc_args );
			}
		} else {
			parent::update_many( $args, $assoc_args );
		}
	}

	protected function get_item_list() {
		$items = array();

		foreach ( get_plugins() as $file => $details ) {
			$update_info = $this->get_update_info( $file );

			$items[ $file ] = array(
				'name' => Utils\get_plugin_name( $file ),
				'status' => $this->get_status( $file ),
				'update' => (bool) $update_info,
				'update_version' => $update_info['new_version'],
				'update_package' => $update_info['package'],
				'version' => $details['Version'],
				'update_id' => $file,
				'title' => $details['Name'],
				'description' => $details['Description'],
			);
		}

		return $items;
	}

	protected function filter_item_list( $items, $args ) {
		$basenames = wp_list_pluck( $this->fetcher->get_many( $args ), 'file' );
		return \WP_CLI\Utils\pick_fields( $items, $basenames );
	}

	/**
	 * Install a plugin.
	 *
	 * ## OPTIONS
	 *
	 * <plugin|zip|url>...
	 * : A plugin slug, the path to a local zip file, or URL to a remote zip file.
	 *
	 * [--version=<version>]
	 * : If set, get that particular version from wordpress.org, instead of the
	 * stable version.
	 *
	 * [--force]
	 * : If set, the command will overwrite any installed version of the plugin, without prompting
	 * for confirmation.
	 *
	 * [--activate]
	 * : If set, the plugin will be activated immediately after install.
	 *
	 * ## EXAMPLES
	 *
	 *     # Install the latest version from wordpress.org and activate
	 *     wp plugin install bbpress --activate
	 *
	 *     # Install the development version from wordpress.org
	 *     wp plugin install bbpress --version=dev
	 *
	 *     # Install from a local zip file
	 *     wp plugin install ../my-plugin.zip
	 *
	 *     # Install from a remote zip file
	 *     wp plugin install http://s3.amazonaws.com/bucketname/my-plugin.zip?AWSAccessKeyId=123&Expires=456&Signature=abcdef
	 */
	function install( $args, $assoc_args ) {
		parent::install( $args, $assoc_args );
	}

	/**
	 * Get a plugin.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>
	 * : The plugin to get.
	 *
	 * [--field=<field>]
	 * : Instead of returning the whole plugin, returns the value of a single field.
	 *
	 * [--format=<format>]
	 * : Output list as table or JSON. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin get bbpress --format=json
	 */
	public function get( $args, $assoc_args ) {
		$plugin = $this->fetcher->get_check( $args[0] );
		$file = $plugin->file;

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );

		$plugin_obj = (object)array(
			'name'        => Utils\get_plugin_name( $file ),
			'title'       => $plugin_data['Name'],
			'author'      => $plugin_data['Author'],
			'version'     => $plugin_data['Version'],
			'description' => wordwrap( $plugin_data['Description'] ),
			'status'      => $this->get_status( $file ),
		);

		$formatter = $this->get_formatter( $assoc_args );
		$formatter->display_item( $plugin_obj );
	}

	/**
	 * Uninstall a plugin.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>...
	 * : One or more plugins to uninstall.
	 *
	 * [--no-delete]
	 * : If set, the plugin files will not be deleted. Only the uninstall procedure
	 * will be run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin uninstall hello
	 */
	function uninstall( $args, $assoc_args = array() ) {
		foreach ( $this->fetcher->get_many( $args ) as $plugin ) {
			if ( is_plugin_active( $plugin->file ) ) {
				WP_CLI::warning( "The '{$plugin->name}' plugin is active." );
				continue;
			}

			uninstall_plugin( $plugin->file );

			if ( !isset( $assoc_args['no-delete'] ) && $this->_delete( $plugin ) ) {
				WP_CLI::success( "Uninstalled '$plugin->name' plugin." );
			}
		}
	}

	/**
	 * Check if the plugin is installed.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>
	 * : The plugin to check.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin is-installed hello
	 *
	 * @subcommand is-installed
	 */
	function is_installed( $args, $assoc_args = array() ) {
		if ( $this->fetcher->get( $args[0] ) ) {
			exit( 0 );
		} else {
			exit( 1 );
		}
	}

	/**
	 * Delete plugin files.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>...
	 * : One or more plugins to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin delete hello
	 */
	function delete( $args, $assoc_args = array() ) {
		foreach ( $this->fetcher->get_many( $args ) as $plugin ) {
			if ( $this->_delete( $plugin ) ) {
				WP_CLI::success( "Deleted '{$plugin->name}' plugin." );
			}
		}
	}

	/**
	 * Get a list of plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--<field>=<value>]
	 * : Filter results based on the value of a field.
	 *
	 * [--field=<field>]
	 * : Prints the value of a single field for each plugin.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields. Defaults to name,status,update,version.
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin list --status=active --format=json
	 *
	 * @subcommand list
	 */
	public function list_( $_, $assoc_args ) {
		parent::_list( $_, $assoc_args );
	}

	/* PRIVATES */

	private function check_active( $file, $network_wide ) {
		$required = $network_wide ? 'active-network' : 'active';

		return $required == $this->get_status( $file );
	}

	private function active_output( $name, $file, $network_wide, $action ) {
		$network_wide = $network_wide || is_network_only_plugin( $file );

		$check = $this->check_active( $file, $network_wide );

		if ( ( $action == "activate" ) ? $check : ! $check ) {
			if ( $network_wide )
				WP_CLI::success( "Plugin '{$name}' network {$action}d." );
			else
				WP_CLI::success( "Plugin '{$name}' {$action}d." );
		} else {
			WP_CLI::warning( "Could not {$action} the '{$name}' plugin." );
		}
	}

	protected function get_status( $file ) {
		if ( is_plugin_active_for_network( $file ) )
			return 'active-network';

		if ( is_plugin_active( $file ) )
			return 'active';

		return 'inactive';
	}

	/**
	 * Get the details of a plugin.
	 *
	 * @param object
	 * @return array
	 */
	private function get_details( $file ) {
		$plugin_folder = get_plugins(  '/' . plugin_basename( dirname( $file ) ) );
		$plugin_file = basename( $file );

		return $plugin_folder[$plugin_file];
	}

	private function _delete( $plugin ) {
		$plugin_dir = dirname( $plugin->file );
		if ( '.' == $plugin_dir )
			$plugin_dir = $plugin->file;

		$path = path_join( WP_PLUGIN_DIR, $plugin_dir );

		if ( \WP_CLI\Utils\is_windows() ) {
			$command = 'rd /s /q ';
			$path = str_replace( "/", "\\", $path );
		} else {
			$command = 'rm -rf ';
		}

		return ! WP_CLI::launch( $command . $path );
	}
}

WP_CLI::add_command( 'plugin', 'Plugin_Command' );

