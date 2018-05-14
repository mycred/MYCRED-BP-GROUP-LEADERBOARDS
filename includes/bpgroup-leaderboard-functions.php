<?php
if ( ! defined( 'MYCRED_BP_GROUP_LEAD_VER' ) ) exit;

/**
 * Get Plugin Settings
 * @since 1.0
 * @version 1.1
 */
if ( ! function_exists( 'bpmycredleaderboarderboard_settings' ) ) :
	function bpmycredleaderboarderboard_settings() {

		$default = array(
			'types'      => array( MYCRED_DEFAULT_TYPE_KEY ),
			'size'       => 10,
			'current'    => 1,
			'type'       => 'current',
			'from'       => '',
			'until'      => '',
			'override'   => 1,
			'title'      => 'Leaderboard',
			'slug'       => 'leaderboard',
			'position'   => 100
		);

		$saved   = get_option( 'mycred_bp_leaderboards', $default );

		return shortcode_atts( $default, $saved );

	}
endif;

/**
 * Get Leaderboard Types
 * @since 1.1
 * @version 1.0
 */
if ( ! function_exists( 'mycred_get_bp_leaderboard_types' ) ) :
	function mycred_get_bp_leaderboard_types() {

		return apply_filters( 'mycred_bp_leaderboard_types', array(
			'current' => __( 'Leaderboard Based on Current Balance', 'bpmycredleaderboard' ),
			'total'   => __( 'Leaderboard Based on Total Balance', 'bpmycredleaderboard' ),
			'today'   => __( "Leaderboard Based on Today's Gains", 'bpmycredleaderboard' ),
			'week'    => __( "Leaderboard Based on This Week's Gains", 'bpmycredleaderboard' ),
			'month'   => __( "Leaderboard Based on This Months' Gains", 'bpmycredleaderboard' ),
			'custom'  => __( 'Leaderboard Based on Date Range', 'bpmycredleaderboard' )
		) );

	}
endif;

/**
 * Get BuddyPress Group Setup
 * @since 1.1
 * @version 1.0
 */
if ( ! function_exists( 'mycred_get_bp_group_setup' ) ) :
	function mycred_get_bp_group_setup( $group_id = NULL ) {

		if ( $group_id === NULL ) return false;

		$settings         = bpmycredleaderboarderboard_settings();

		$setup            = array();
		$setup['forced']  = $settings['override'];
		$setup['types']   = $settings['types'];
		$setup['size']    = absint( $settings['size'] );
		$setup['current'] = (bool) $settings['current'];
		$setup['type']    = sanitize_key( $settings['type'] );

		if ( ! $settings['override'] ) {

			$number         = groups_get_groupmeta( $group_id, 'bp_group_leaderboard_size' );
			if ( $number != '' && absint( $number ) <= MYCRED_BP_GROUP_LEAD_MAX_SIZE )
				$setup['size'] = absint( $number );

			$attach_current = groups_get_groupmeta( $group_id, 'bp_group_show_current_user' );
			if ( $attach_current != '' )
				$setup['current'] = (bool) $attach_current;

			$type           = groups_get_groupmeta( $group_id, 'bp_group_leaderboard_type' );
			if ( $type != '' )
				$setup['type'] = sanitize_key( $type );

		}

		if ( $setup['type'] == 'custom' ) {

			$setup['from']  = $settings['from'];
			$setup['until'] = $settings['until'];

			if ( ! $settings['override'] ) {

				$from  = groups_get_groupmeta( $group_id, 'leaderboard_from' );
				if ( $from != '' )
					$setup['from']  = sanitize_text_field( $from );

				$until = groups_get_groupmeta( $group_id, 'leaderboard_until' );
				if ( $until != '' )
					$setup['until'] = sanitize_text_field( $until );

			}

		}

		return apply_filters( 'mycred_bp_leaderboard_setup', $setup, $group_id, $settings );

	}
endif;

/**
 * Get BuddyPress Groups Leaderboard
 * @since 1.1
 * @version 1.0
 */
if ( ! function_exists( 'mycred_get_bp_groups_leaderboard' ) ) :
	function mycred_get_bp_groups_leaderboard( $group_id = NULL, $point_type = MYCRED_DEFAULT_TYPE_KEY, $append_user = false, $fresh = false ) {

		if ( $group_id === NULL ) return array();

		global $wpdb, $mycred;

		$leaderboard = array();
		$group_table = $wpdb->prefix . 'bp_groups_members';
		$group_id    = absint( $group_id );

		if ( ! $fresh ) {

			$leaderboard  = groups_get_groupmeta( $group_id, 'cached_leaderboard' . $point_type );
			$leaderboard  = maybe_unserialize( $leaderboard );

		}

		$setup       = mycred_get_bp_group_setup( $group_id );

		if ( empty( $leaderboard ) || $fresh ) {

			// Check if this is a built-in type
			if ( in_array( $setup['type'], array( 'current', 'total', 'today', 'week', 'month', 'custom' ) ) ) {

				// Based on current balance
				if ( $setup['type'] == 'current' ) {

					$leaderboard = $wpdb->get_results( $wpdb->prepare( "
						SELECT meta.user_id, meta.meta_value as balance 
						FROM {$wpdb->usermeta} meta 
							LEFT JOIN {$group_table} groups ON ( groups.user_id = meta.user_id ) 
						WHERE groups.group_id = %d 
							AND groups.is_confirmed = 1 
							AND groups.is_banned = 0 
							AND meta.meta_key = %s
						ORDER BY meta.meta_value+0 DESC 
						LIMIT 0,%d", $group_id, mycred_get_meta_key( $point_type ), $setup['size'] ) );

				}

				// Based on total balance
				elseif ( $setup['type'] == 'total' ) {

					$leaderboard = $wpdb->get_results( $wpdb->prepare( "
						SELECT log.user_id, SUM( log.creds ) as balance 
						FROM {$mycred->log_table} log 
							LEFT JOIN {$group_table} groups ON ( groups.user_id = log.user_id ) 
						WHERE groups.group_id = %d 
							AND groups.is_confirmed = 1 
							AND groups.is_banned = 0 
							AND log.ctype = %s 
							AND ( ( log.creds > 0 ) OR ( log.creds < 0 AND log.ref = 'manual' ) )
						GROUP BY log.user_id 
						ORDER BY balance DESC, log.user_id ASC 
						LIMIT 0,%d", $group_id, $point_type, $setup['size'] ) );

				}

				// Based on time
				else {

					$from  = 0;
					$until = current_time( 'timestamp' );

					if ( $setup['type'] == 'today' )
						$from  = mktime( 0, 0, 0, date( 'n', $until ), date( 'j', $until ), date( 'Y', $until ) );

					elseif ( $setup['type'] == 'week' )
						$from  = mktime( 0, 0, 0, date( "n", $until ), date( "j", $until ) - date( "N", $until ) + 1 );

					elseif ( $setup['type'] == 'month' )
						$from  = mktime( 0, 0, 0, date( "n", $until ), 1, date( 'Y', $until ) );

					elseif ( $setup['type'] == 'custom' ) {
						$from  = strtotime( $setup['from'] );
						$until = strtotime( $setup['until'] );
					}

					$leaderboard = $wpdb->get_results( $wpdb->prepare( "
						SELECT log.user_id, SUM( log.creds ) as balance 
						FROM {$mycred->log_table} log 
							LEFT JOIN {$group_table} groups ON ( groups.user_id = log.user_id ) 
						WHERE groups.group_id = %d 
							AND groups.is_confirmed = 1 
							AND groups.is_banned = 0 
							AND log.ctype = %s 
							AND log.time BETWEEN %d AND %d
						GROUP BY log.user_id 
						ORDER BY balance DESC, log.user_id ASC 
						LIMIT 0,%d", $group_id, $point_type, $from, $until, $setup['size'] ) );

				}

				if ( ! empty( $leaderboard ) )
					groups_update_groupmeta( $group_id, 'cached_leaderboard' . $point_type, serialize( $leaderboard ) );

			}

		}

		// If we have a leaderboard and we are logged in
		if ( ! empty( $leaderboard ) && $append_user !== false ) {

			// Check if the current user is in the leaderboard
			$in_list = false;
			foreach ( $leaderboard as $position => $entry ) {

				$entry->position = $position+1;
				$entry->current  = false;

				if ( $entry->user_id == $append_user ) {
					$entry->current = true;
					$in_list        = true;
				}

			}

			// If we want to attach the current user because they are not in the leaderboard
			if ( $setup['current'] && ! $in_list ) {

				$position = mycred_get_users_bp_group_position( $append_user, $group_id, $point_type );
				if ( $position !== false ) {

					// Append the current user
					$row           = new StdClass();
					$row->position = $position;
					$row->user_id  = $append_user;
					$row->balance  = mycred_get_users_cred( $append_user, $point_type );
					$row->current  = true;

					$leaderboard[] = $row;

				}

			}

		}

		return apply_filters( 'mycred_bp_leaderboard_get', $leaderboard, $group_id, $point_type, $append_user, $fresh );

	}
endif;

/**
 * Get Users Group Positions
 * @since 1.1
 * @version 1.0
 */
if ( ! function_exists( 'mycred_get_users_bp_group_positions' ) ) :
	function mycred_get_users_bp_group_positions( $user_id = NULL, $group_id = NULL ) {

		$setup    = mycred_get_bp_group_setup( $group_id );
		$members  = groups_get_total_member_count( $group_id );
		$defaults = array();

		// Construct a default array
		if ( ! empty( $setup['types'] ) ) {
			foreach ( $setup['types'] as $type_id )
				$default[ $type_id ] = false;
		}

		$positions = (array) get_user_meta( $user_id, '_bp_leaderboard_position' . $group_id );
		if ( empty( $positions ) ) $positions = $defaults;

		// Make sure all point types are present in the array
		if ( ! empty( $setup['types'] ) ) {
			foreach ( $setup['types'] as $type_id ) {

				if ( ! array_key_exists( $type_id, $positions ) )
					$positions[ $type_id ] = false;

			}
		}

		// Make sure we remove point types that no longer being used
		if ( ! empty( $positions ) ) {
			foreach ( $positions as $point_type => $position ) {

				if ( ! in_array( $point_type, $setup['types'] ) )
					unset( $positions[ $point_type ] );

			}
		}

		return $positions;

	}
endif;

/**
 * Get Users Group Position
 * @since 1.1
 * @version 1.0
 */
if ( ! function_exists( 'mycred_get_users_bp_group_position' ) ) :
	function mycred_get_users_bp_group_position( $user_id = NULL, $group_id = NULL, $point_type = MYCRED_DEFAULT_TYPE_KEY, $find = true ) {

		$positions = mycred_get_users_bp_group_positions( $user_id, $group_id );

		$result    = false;
		if ( array_key_exists( $point_type, $positions ) && $positions[ $point_type ] !== false )
			$result = $positions[ $point_type ];

		if ( $result === false && $find )
			$result = mycred_find_users_bp_group_position( $user_id, $group_id, $point_type );

		return $result;

	}
endif;

/**
 * Find Users BuddyPress Group Position
 * @since 1.1
 * @version 1.0
 */
if ( ! function_exists( 'mycred_find_users_bp_group_position' ) ) :
	function mycred_find_users_bp_group_position( $user_id = NULL, $group_id = NULL, $point_type = MYCRED_DEFAULT_TYPE_KEY ) {

		if ( $user_id === NULL || $group_id === NULL ) return false;

		global $wpdb, $mycred;

		$group_table = $wpdb->prefix . 'bp_groups_members';

		$position    = false;
		$members     = groups_get_total_member_count( $group_id );
		$setup       = mycred_get_bp_group_setup( $group_id );

		// Check if this is a built-in type
		if ( in_array( $setup['type'], array( 'current', 'total', 'today', 'week', 'month', 'custom' ) ) ) {

			// Based on current balance
			if ( $setup['type'] == 'current' ) {

				$position = $wpdb->get_var( $wpdb->prepare( "
					SELECT rank FROM (
						SELECT s.*, @rank := @rank + 1 rank FROM (
							SELECT t.user_id, t.meta_value AS Balance 
							FROM {$wpdb->usermeta} t 
								LEFT JOIN {$group_table} g ON ( g.user_id = t.user_id ) 
							WHERE t.meta_key = %s 
								AND g.group_id = %d 
								AND g.is_confirmed = 1 
								AND g.is_banned = 0 
						) s, (SELECT @rank := 0) init
						ORDER BY Balance+0 DESC, s.user_id ASC 
					) r 
					WHERE user_id = %d", mycred_get_meta_key( $point_type ), $group_id, $user_id ) );

			}

			// Based on total balance
			elseif ( $setup['type'] == 'total' ) {

				$position = $wpdb->get_var( $wpdb->prepare( "
					SELECT rank FROM (
						SELECT s.*, @rank := @rank + 1 rank FROM (
							SELECT l.user_id, SUM( t.creds ) AS Balance 
							FROM {$mycred->log_table} l 
								LEFT JOIN {$group_table} g ON ( g.user_id = l.user_id ) 
							WHERE g.group_id = %d 
								AND g.is_confirmed = 1 
								AND g.is_banned = 0 
								AND l.ctype = %s 
								AND ( ( l.creds > 0 ) OR ( l.creds < 0 AND l.ref = 'manual' ) ) 
							GROUP BY l.user_id 
						) s, (SELECT @rank := 0) init
						ORDER BY Balance DESC, s.user_id ASC 
					) r 
					WHERE user_id = %d", $group_id, $point_type, $user_id ) );

			}

			// Based on time
			else {

				$from  = 0;
				$until = current_time( 'timestamp' );

				if ( $setup['type'] == 'today' )
					$from  = mktime( 0, 0, 0, date( 'n', $until ), date( 'j', $until ), date( 'Y', $until ) );

				elseif ( $setup['type'] == 'week' )
					$from  = mktime( 0, 0, 0, date( "n", $until ), date( "j", $until ) - date( "N", $until ) + 1 );

				elseif ( $setup['type'] == 'month' )
					$from  = mktime( 0, 0, 0, date( "n", $until ), 1, date( 'Y', $until ) );

				elseif ( $setup['type'] == 'custom' ) {
					$from  = strtotime( $setup['from'] );
					$until = strtotime( $setup['until'] );
				}

				$position = $wpdb->get_var( $wpdb->prepare( "
					SELECT rank FROM (
						SELECT s.*, @rank := @rank + 1 rank FROM (
							SELECT l.user_id, SUM( t.creds ) AS Balance 
							FROM {$mycred->log_table} l 
								LEFT JOIN {$group_table} g ON ( g.user_id = l.user_id ) 
							WHERE g.group_id = %d 
								AND g.is_confirmed = 1 
								AND g.is_banned = 0 
								AND l.ctype = %s 
								AND l.time BETWEEN %d AND %d
							GROUP BY l.user_id 
						) s, (SELECT @rank := 0) init
						ORDER BY Balance DESC, s.user_id ASC 
					) r 
					WHERE user_id = %d", $group_id, $point_type, $from, $until, $user_id ) );

			}

		}

		if ( $position === NULL )
			$position = $members;

		// A position was found, save it now
		else {

			$saved                = mycred_get_users_bp_group_positions( $user_id, $group_id );
			$saved[ $point_type ] = $position;

			update_user_meta( $user_id, '_bp_leaderboard_position' . $group_id, $saved );

		}

		return apply_filters( 'mycred_bp_leaderboard_find_position', $position, $group_id, $user_id, $point_type );

	}
endif;
