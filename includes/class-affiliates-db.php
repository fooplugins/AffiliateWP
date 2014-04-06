<?php

class Affiliate_WP_DB_Affiliates extends Affiliate_WP_DB {

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function __construct() {
		global $wpdb;

		$this->table_name  = $wpdb->prefix . 'affiliate_wp_affiliates';
		$this->primary_key = 'affiliate_id';
		$this->version     = '1.0';
	}

	/**
	 * Get table columns and date types
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function get_columns() {
		return array(
			'affiliate_id'    => '%d',
			'user_id'         => '%d',
			'rate'            => '%s',
			'payment_email'   => '%s',
			'status'          => '%s',
			'earnings'        => '%s',
			'referrals'       => '%d',
			'visits'          => '%d',
			'date_registered' => '%s',
		);
	}

	/**
	 * Get default column values
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function get_column_defaults() {
		return array(
			'user_id'  => get_current_user_id()
		);
	}

	/**
	 * Retrieve affiliates from the database
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function get_affiliates( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'number'  => 20,
			'offset'  => 0,
			'user_id' => 0,
			'status'  => '',
			'order'   => 'DESC',
			'orderby' => 'affiliate_id'
		);

		$args  = wp_parse_args( $args, $defaults );

		$where = '';

		// affiliates for specific users
		if ( ! empty( $args['user_id'] ) ) {

			if( is_array( $args['user_id'] ) ) {
				$user_ids = implode( ',', $args['user_id'] );
			} else {
				$user_ids = intval( $args['user_id'] );
			}

			$where .= "WHERE `user_id` IN( {$user_ids} ) ";

		}

		if ( ! empty( $args['status'] ) ) {

			if( ! empty( $where ) ) {
				$where .= "AND `status` = '" . $args['status'] . "' ";
			} else {
				$where .= "WHERE `status` = '" . $args['status'] . "' ";
			}
		}

		if ( ! empty( $args['search'] ) ) {

			if( is_numeric( $args['search'] ) ) {

				$affiliate_ids = esc_sql( $args['search'] );
				$search = "`affiliate_id` IN( {$affiliate_ids} )";

			} elseif( is_string( $args['search'] ) ) {

				// Searching by an affiliate's name or email
				if( is_email( $args['search'] ) ) {

					$user    = get_user_by( 'email', $args['search'] );
					$user_id = $user ? $user->ID : 0;
					$search  = "`user_id` = '" . $user_id . "' ";

				} else {

					$args['search'] = esc_sql( $args['search'] );
					$users = $wpdb->get_col( "SELECT ID FROM $wpdb->users WHERE display_name LIKE '%{$args['search']}%'" );
					$users = ! empty( $users ) ? implode( ',', $users ) : 0;
					$search = "`user_id` IN( {$users} )";

				}

			}

			if( ! empty( $search ) ) {

				if( ! empty( $where ) ) {
					$search = "AND " . $search;
				} else {
					$search = "WHERE " . $search;
				}

				$where .= $search;
			}

		}

		if( 'earnings' == $args['orderby'] ) {
			$args['orderby'] = 'earnings+0';
		}

		$cache_key = md5( 'affwp_affiliates_' . serialize( $args ) );

		$affiliates = wp_cache_get( $cache_key, 'affiliates' );

		if( $affiliates === false ) {
			$affiliates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM  $this->table_name $where ORDER BY {$args['orderby']} {$args['order']} LIMIT %d,%d;", absint( $args['offset'] ), absint( $args['number'] ) ) );
			wp_cache_set( $cache_key, $affiliates, 'affiliates', 3600 );
		}

		return $affiliates;

	}

	/**
	 * Retrieve the name of the affiliate
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function get_affiliate_name( $affiliate_id = 0 ) {
		global $wpdb;

		$cache_key = 'affwp_affiliate_name_' . $affiliate_id;

		$name = wp_cache_get( $cache_key, 'affiliates' );

		if( $name === false ) {
			$name = $wpdb->get_var( $wpdb->prepare( "SELECT u.display_name FROM $wpdb->users u INNER JOIN $this->table_name a ON u.ID = a.user_id WHERE a.affiliate_id = %d;", $affiliate_id ) );
			wp_cache_set( $cache_key, $name, 'affiliates', 3600 );
		}

		return $name;
	}

	/**
	 * Checks if an affiliate exists
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function affiliate_exists( $affiliate_id = 0 ) {

		global $wpdb;

		if( empty( $affiliate_id ) ) {
			return false;
		}

		$affiliate = $wpdb->query( $wpdb->prepare( "SELECT 1 FROM $this->table_name WHERE $this->primary_key = %d;", $affiliate_id ) );

		return ! empty( $affiliate );
	}

	/**
	 * Add a new affiliate
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function add( $data = array() ) {

		$defaults = array(
			'status'          => 'active',
			'date_registered' => current_time( 'mysql' ),
			'earnings'		  => 0,
			'referrals'		  => 0,
			'visits'		  => 0
		);

		$args = wp_parse_args( $data, $defaults );

		if(  ! empty( $args['user_id'] ) && affiliate_wp()->affiliates->get_by( 'user_id', $args['user_id'] ) ) {
			return false;
		}

		$add  = $this->insert( $args, 'affiliate' );

		if( $add ) {

			wp_cache_flush();

			do_action( 'affwp_add_affiliate', $add );
			return $add;
		}

		return false;

	}

	/**
	 * Count the total number of affiliates in the database
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function count( $args = array() ) {
		global $wpdb;

		$where = '';

		if ( ! empty( $args['status'] ) ) {

			if( is_array( $args['status'] ) ) {
				$where .= " WHERE `status` IN('" . implode( "','", $args['status'] ) . "') ";
			} else {
				$where .= " WHERE `status` = '" . $args['status'] . "' ";
			}

		}

		if ( ! empty( $args['search'] ) ) {

			if ( is_numeric( $args['search'] ) ) {

				$affiliate_ids = esc_sql( $args['search'] );
				$search = "`affiliate_id` IN( {$affiliate_ids} )";

			} elseif( is_string( $args['search'] ) ) {

				// Searching by an affiliate's name or email
				if( is_email( $args['search'] ) ) {

					$user    = get_user_by( 'email', $args['search'] );
					$user_id = $user ? $user->ID : 0;
					$search  = "`user_id` = '" . $user_id . "' ";

				} else {

					$args['search'] = esc_sql( $args['search'] );
					$users = $wpdb->get_col( "SELECT ID FROM $wpdb->users WHERE display_name LIKE '%{$args['search']}%'" );
					$users = ! empty( $users ) ? implode( ',', $users ) : 0;
					$search = "`user_id` IN( {$users} )";

				}

			}

			if ( ! empty( $search ) ) {

				if( ! empty( $where ) ) {
					$search = "AND " . $search;
				} else {
					$search = "WHERE " . $search;
				}

				$where .= $search;
			}

		}

		$cache_key = md5( 'affwp_affiliates_count' . serialize( $args ) );

		$count = wp_cache_get( $cache_key, 'affiliates' );

		if( $count === false ) {
			$count = $wpdb->get_var( "SELECT COUNT($this->primary_key) FROM " . $this->table_name . "{$where};" );
			wp_cache_set( $cache_key, $count, 'affiliates', 3600 );
		}

		return $count;

	}

	/**
	 * Create the table
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function create_table() {
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$sql = "CREATE TABLE {$this->table_name} (
			`affiliate_id` bigint(20) NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) NOT NULL,
			`rate` tinytext NOT NULL,
			`payment_email` mediumtext NOT NULL,
			`status` tinytext NOT NULL,
			`earnings` mediumtext NOT NULL,
			`referrals` bigint(20) NOT NULL,
			`visits` bigint(20) NOT NULL,
			`date_registered` datetime NOT NULL,
			PRIMARY KEY  (affiliate_id),
			KEY user_id (user_id)
			) CHARACTER SET utf8 COLLATE utf8_general_ci;";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}

}