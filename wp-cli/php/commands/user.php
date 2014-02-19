<?php

/**
 * Manage users.
 *
 * @package wp-cli
 */
class User_Command extends \WP_CLI\CommandWithDBObject {

	protected $obj_type = 'user';
	protected $obj_fields = array(
		'ID',
		'user_login',
		'display_name',
		'user_email',
		'user_registered',
		'roles'
	);

	public function __construct() {
		$this->fetcher = new \WP_CLI\Fetchers\User;
	}

	/**
	 * List users.
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : Only display users with a certain role.
	 *
	 * [--<field>=<value>]
	 * : Filter by one or more fields. For accepted fields, see get_users().
	 *
	 * [--field=<field>]
	 * : Prints the value of a single field for each user.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields. Defaults to ID,user_login,display_name,user_email,user_registered,roles
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp user list --field=ID
	 *
	 *     wp user list --role=administrator --format=csv
	 *
	 *     wp user list --fields=display_name,user_email --format=json
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$formatter = $this->get_formatter( $assoc_args );

		if ( 'ids' == $formatter->format ) {
			$assoc_args['fields'] = 'ids';
		} else {
			$assoc_args['fields'] = 'all_with_meta';
		}

		$users = get_users( $assoc_args );

		$it = WP_CLI\Utils\iterator_map( $users, function ( $user ) {
			if ( !is_object( $user ) )
				return $user;

			$user->roles = implode( ',', $user->roles );

			return $user;
		} );

		$formatter->display_items( $it );
	}

	/**
	 * Get a single user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or user login.
	 *
	 * [--field=<field>]
	 * : Instead of returning the whole user, returns the value of a single field.
	 *
	 * [--format=<format>]
	 * : Accepted values: table, json. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp user get 12 --field=login
	 *
	 *     wp user get bob --format=json > bob.json
	 */
	public function get( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );

		if ( method_exists( $user, 'to_array' ) ) {
			$user_data = $user->to_array();
		} else {
			// WP 3.4 compat
			$user_data = (array) $user->data;
		}
		$user_data['roles'] = implode( ', ', $user->roles );

		$formatter = $this->get_formatter( $assoc_args );
		$formatter->display_item( $user_data );
	}

	/**
	 * Delete one or more users.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : The user login, user email, or user ID of the user(s) to update.
	 *
	 * [--reassign=<user-id>]
	 * : User ID to reassign the posts to.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user delete 123 --reassign=567
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args = wp_parse_args( $assoc_args, array(
			'reassign' => null
		) );

		$users = $this->fetcher->get_many( $args );

		parent::_delete( $users, $assoc_args, function ( $user, $assoc_args ) {
			$user_id = $user->ID;

			if ( is_multisite() ) {
				$r = wpmu_delete_user( $user_id );
			} else {
				$r = wp_delete_user( $user_id, $assoc_args['reassign'] );
			}

			if ( $r ) {
				return array( 'success', "Deleted user $user_id." );
			} else {
				return array( 'error', "Failed deleting user $user_id." );
			}
		} );
	}

	/**
	 * Create a user.
	 *
	 * ## OPTIONS
	 *
	 * <user-login>
	 * : The login of the user to create.
	 *
	 * <user-email>
	 * : The email address of the user to create.
	 *
	 * [--role=<role>]
	 * : The role of the user to create. Default: default role
	 *
	 * [--user_pass=<password>]
	 * : The user password. Default: randomly generated
	 *
	 * [--user_registered=<yyyy-mm-dd>]
	 * : The date the user registered. Default: current date
	 *
	 * [--display_name=<name>]
	 * : The display name.
	 *
	 * [--porcelain]
	 * : Output just the new user id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user create bob bob@example.com --role=author
	 */
	public function create( $args, $assoc_args ) {
		list( $user_login, $user_email ) = $args;

		$defaults = array(
			'role' => get_option('default_role'),
			'user_pass' => false,
			'user_registered' => strftime( "%F %T", time() ),
			'display_name' => false,
		);
		extract( array_merge( $defaults, $assoc_args ), EXTR_SKIP );

		if ( !self::validate_role( $role ) ) {
			WP_CLI::error( "Invalid role: $role" );
		}

		// @codingStandardsIgnoreStart
		if ( !$user_pass ) {
			$user_pass = wp_generate_password();
			$generated_pass = true;
		}

		$user_id = wp_insert_user( array(
			'user_email' => $user_email,
			'user_login' => $user_login,
			'user_pass' => $user_pass,
			'user_registered' => $user_registered,
			'display_name' => $display_name,
			'role' => $role,
		) );

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( $user_id );
		} else {
			if ( false === $role ) {
				delete_user_option( $user_id, 'capabilities' );
				delete_user_option( $user_id, 'user_level' );
			}
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $user_id );
		} else {
			WP_CLI::success( "Created user $user_id." );
			if ( isset( $generated_pass ) )
				WP_CLI::line( "Password: $user_pass" );
		}
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Update a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : The user login, user email or user ID of the user(s) to update.
	 *
	 * --<field>=<value>
	 * : One or more fields to update. For accepted fields, see wp_update_user().
	 *
	 * ## EXAMPLES
	 *
	 *     wp user update 123 --display_name=Mary --user_pass=marypass
	 */
	public function update( $args, $assoc_args ) {
		if ( isset( $assoc_args['user_login'] ) ) {
			WP_CLI::warning( "User logins can't be changed." );
			unset( $assoc_args['user_login'] );
		}

		$user_ids = array();
		foreach ( $this->fetcher->get_many( $args ) as $user ) {
			$user_ids[] = $user->ID;
		}

		parent::_update( $user_ids, $assoc_args, 'wp_update_user' );
	}

	/**
	 * Generate users.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : How many users to generate. Default: 100
	 *
	 * [--role=<role>]
	 * : The role of the generated users. Default: default role from WP
	 */
	public function generate( $args, $assoc_args ) {
		global $blog_id;

		$defaults = array(
			'count' => 100,
			'role' => get_option('default_role'),
		);
		$assoc_args = array_merge( $defaults, $assoc_args );

		$role = $assoc_args['role'];

		if ( !self::validate_role( $role ) ) {
			WP_CLI::error( "Invalid role: $role" );
		}

		$user_count = count_users();
		$total = $user_count['total_users'];
		$limit = $assoc_args['count'] + $total;

		$notify = \WP_CLI\Utils\make_progress_bar( 'Generating users', $assoc_args['count'] );

		for ( $i = $total; $i < $limit; $i++ ) {
			$login = sprintf( 'user_%d_%d', $blog_id, $i );
			$name = "User $i";

			$user_id = wp_insert_user( array(
				'user_login' => $login,
				'user_pass' => $login,
				'nickname' => $name,
				'display_name' => $name,
				'role' => $role
			) );

			if ( false === $role ) {
				delete_user_option( $user_id, 'capabilities' );
				delete_user_option( $user_id, 'user_level' );
			}

			$notify->tick();
		}

		$notify->finish();
	}

	/**
	 * Set the user role (for a particular blog).
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or user login.
	 *
	 * [<role>]
	 * : Make the user have the specified role. If not passed, the default role is
	 * used.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user set-role bob author
	 *     wp user set-role 12 author
	 *
	 * @subcommand set-role
	 */
	public function set_role( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );

		$role = isset( $args[1] ) ? $args[1] : get_option( 'default_role' );

		// Multisite
		if ( function_exists( 'add_user_to_blog' ) )
			add_user_to_blog( get_current_blog_id(), $user->ID, $role );
		else
			$user->set_role( $role );

		WP_CLI::success( "Added {$user->user_login} ({$user->ID}) to " . site_url() . " as {$role}" );
	}

	/**
	 * Add a role for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or user login.
	 *
	 * <role>
	 * : Add the specified role to the user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user add-role bob author
	 *     wp user add-role 12 author
	 *
	 * @subcommand add-role
	 */
	public function add_role( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );

		$role = $args[1];

		$user->add_role( $role );

		WP_CLI::success( sprintf( "Added '%s' role for %s (%d).", $role, $user->user_login, $user->ID ) );
	}

	/**
	 * Remove a user's role.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or user login.
	 *
	 * [<role>]
	 * : A specific role to remove.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user remove-role bob
	 *     wp user remove-role 12 editor
	 *
	 * @subcommand remove-role
	 */
	public function remove_role( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );

		if ( isset( $args[1] ) ) {
			$role = $args[1];

			$user->remove_role( $role );

			WP_CLI::success( sprintf( "Removed '%s' role for %s (%d).", $role, $user->user_login, $user->ID ) );
		} else {
			// Multisite
			if ( function_exists( 'remove_user_from_blog' ) )
				remove_user_from_blog( $user->ID, get_current_blog_id() );
			else
				$user->remove_all_caps();

			WP_CLI::success( "Removed {$user->user_login} ({$user->ID}) from " . site_url() );
		}
	}

	/**
	 * Add a capability for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or user login.
	 *
	 * <cap>
	 * : The capability to add.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user add-cap john create_premium_item
	 *     wp user add-cap 15 edit_product
	 *
	 * @subcommand add-cap
	 */
	public function add_cap( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );
		if ( $user ) {
			$cap  = $args[1];
			$user->add_cap( $cap );

			WP_CLI::success( sprintf( "Added '%s' capability for %s (%d).", $cap, $user->user_login, $user->ID ) );
		}
	}

	/**
	 * Remove a user's capability.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or user login.
	 *
	 * <cap>
	 * : The capability to be removed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user remove-cap bob edit_themes
	 *     wp user remove-cap 11 publish_newsletters
	 *
	 * @subcommand remove-cap
	 */
	public function remove_cap( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );
		if ( $user ) {
			$cap = $args[1];
			$user->remove_cap( $cap );

			WP_CLI::success( sprintf( "Removed '%s' cap for %s (%d).", $cap, $user->user_login, $user->ID ) );
		}
	}

	/**
	 * List all user's capabilities.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, user email, or login.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user list-caps admin
	 *     wp user list-caps 21
	 *
	 * @subcommand list-caps
	 */
	public function list_caps( $args, $assoc_args ) {
		$user = $this->fetcher->get_check( $args[0] );

		if ( $user ) {
			$user->get_role_caps();

			$user_caps_list = $user->allcaps;
			$cap_table_titles = array( 'capability', 'status' );

			foreach ( $user_caps_list as $cap => $active ) {
				if ( $active ) {
					\cli\line( $cap );
				}
			}
		}
	}

	/**
	 * Import users from a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV file of users to import.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user import-csv /path/to/users.csv
	 *
	 *     Sample users.csv file:
	 *
	 *     user_login,user_email,display_name,role
	 *     bobjones,bobjones@domain.com,Bob Jones,contributor
	 *     newuser1,newuser1@domain.com,New User,author
	 *     existinguser,existinguser@domain.com,Existing User,administrator
	 *
	 * @subcommand import-csv
	 */
	public function import_csv( $args, $assoc_args ) {

		$blog_users = get_users();

		$filename = $args[0];

		if ( ! file_exists( $filename ) ) {
			WP_CLI::warning( sprintf( "Missing file: %s", $filename ) );
		}

		foreach ( new \WP_CLI\Iterators\CSV( $filename ) as $i => $new_user ) {
			$defaults = array(
				'role' => get_option('default_role'),
				'user_pass' => wp_generate_password(),
				'user_registered' => strftime( "%F %T", time() ),
				'display_name' => false,
			);
			$new_user = array_merge( $defaults, $new_user );

			if ( 'none' === $new_user['role'] ) {
				$new_user['role'] = false;
			} elseif ( is_null( get_role( $new_user['role'] ) ) ) {
				WP_CLI::warning( "{$new_user['user_login']} has an invalid role" );
				continue;
			}

			// User already exists and we just need to add them to the site if they aren't already there
			$existing_user = get_user_by( 'email', $new_user['user_email'] );

			if ( !$existing_user ) {
				$existing_user = get_user_by( 'login', $new_user['user_login'] );
			}

			if ( $existing_user ) {
				$new_user['ID'] = $existing_user->ID;
				$user_id = wp_update_user( $new_user );

				if ( !in_array( $existing_user->user_login, wp_list_pluck( $blog_users, 'user_login' ) ) &&  $new_user['role'] ) {
					add_user_to_blog( get_current_blog_id(), $existing_user->ID, $new_user['role'] );
					WP_CLI::log( "{$existing_user->user_login} added as {$new_user['role']}." );
				}

			// Create the user
			} else {
				unset( $new_user['ID'] ); // Unset else it will just return the ID 
				$user_id = wp_insert_user( $new_user );
			}

			if ( is_wp_error( $user_id ) ) {
				WP_CLI::warning( $user_id );
				continue;

			} else if ( $new_user['role'] === false ) {
				delete_user_option( $user_id, 'capabilities' );
				delete_user_option( $user_id, 'user_level' );
			}

			if ( !empty( $existing_user ) ) {
				WP_CLI::success( $new_user['user_login'] . " updated" );
			} else {
				WP_CLI::success( $new_user['user_login'] . " created" );
			}
		}
	}

	private static function validate_role( $role ) {
		if ( empty( $role ) || ! is_null( get_role( $role ) ) ) {
			return true;
		}
		return false;
	}
}

WP_CLI::add_command( 'user', 'User_Command' );

