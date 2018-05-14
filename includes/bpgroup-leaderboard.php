<?php
if ( ! defined( 'MYCRED_BP_GROUP_LEAD_VER' ) ) exit;

/**
 * Group Extention
 * @see https://codex.buddypress.org/developer/group-extension-api/
 * @since 1.0
 * @version 1.1
 */
if ( ! class_exists( 'myCRED_Group_Leaderboards' ) ) :
	class myCRED_Group_Leaderboards extends BP_Group_Extension {

		public $settings          = array();
		public $leaderboard_types = array();

		/**
		 * Construct
		 * @since 1.0
		 * @version 1.1
		 */
		public function __construct() {

			$this->settings          = bpmycredleaderboarderboard_settings();
			$this->leaderboard_types = mycred_get_bp_leaderboard_types();

			$args                    = array(
				'slug'              => $this->settings['slug'],
				'name'              => $this->settings['title'],
				'nav_item_position' => $this->settings['position'],
				'screens'           => array(
					'edit' => array(
						'enabled'     => true,
						'name'        => $this->settings['title'],
						'slug'        => $this->settings['slug'],
						'submit_text' => __( 'Save', 'bpmycredleaderboard' ),
					)
				)
			);

			if ( $this->settings['override'] === 1 )
				$args['screens']['edit']['enabled'] = false;

			add_action( 'mycred_bp_leaderboard_custom_field',       array( $this, 'custom_leaderboard_fields' ), 10, 2 );
			add_action( 'mycred_bp_leaderboard_custom_prefs_field', array( $this, 'custom_leaderboard_fields' ), 10, 2 );

			parent::init( $args );

		}

		/**
		 * Display Leaderboard Screen
		 * @since 1.0
		 * @version 1.1
		 */
		public function display( $group_id = NULL ) {

			$current_user = get_current_user_id();
			$setup        = mycred_get_bp_group_setup( $group_id );

?>
<h1><?php printf( __( '%s Leaderboard', 'bpmycredleaderboard' ), bp_get_group_name() ); ?></h1>
<?php

			if ( ! empty( $setup['types'] ) ) {

				$point_type  = $setup['types'][0];
				if ( isset( $_GET['type'] ) && in_array( $_GET['type'], $setup['types'] ) )
					$point_type = sanitize_key( $_GET['type'] );

				$mycred      = mycred( $point_type );
				$leaderboard = mycred_get_bp_groups_leaderboard( $group_id, $point_type, ( $mycred->exclude_user( $current_user ) ? false : $current_user ) );

				// Allow adjustments of the leaderboard columns
				$columns     = apply_filters( 'mycred_bp_leaderboard_columns', array(
					'position' => __( 'Position', 'bpmycredleaderboard' ),
					'member'   => __( 'Member', 'bpmycredleaderboard' ),
					'balance'  => $mycred->plural()
				), $group_id, $point_type );

				do_action( 'mycred_bp_leaderboard_before_filter', $group_id, $setup );

				// If there is more than one point type, we need to offer a filter option
				if ( count( $setup['types'] ) > 1 ) {

?>
<form method="get" action="" id="select-leaderboard-type" class="form-inline">
	<div class="form-group">
		<select name="type" id="bp-leaderboard-type" class="form-control">
<?php

					foreach ( $setup['types'] as $type_id ) {

						$mycred = mycred( $type_id );

						echo '<option value="' . $type_id . '"';
						if ( in_array( $selected_type, $setup['types'] ) && $selected_type == $type_id ) echo ' selected="selected"';
						echo '>' . $mycred->plural() . '</option>';

					}

?>
		</select>
	</div>

	<?php do_action( 'mycred_bp_leaderboard_filter', $group_id, $setup ); ?>

	<div class="form-group">
		<input type="submit" class="btn btn-primary button" value="<?php _e( 'Filter', 'bpmycredleaderboard' ); ?>" />
	</div>
</form>
<?php

				}

				do_action( 'mycred_bp_leaderboard_after_filter', $group_id, $setup );

?>
<div class="table-responsive">
	<table id="bp-group-leaderboard" class="table">
		<thead>
			<tr>
<?php

				// Render column headers
				foreach ( $columns as $column_id => $column_label )
					echo '<th class="leaderboard-column column-' . $column_id . ' ' . $column_id . '">' . $column_label . '</option>';

?>
			</tr>
		</thead>
		<tbody>
<?php

				if ( ! empty( $leaderboard ) ) {

					// Render leaderboard rows
					foreach ( $leaderboard as $row ) {

						$classes = array( 'leaderboard-row' );
						if ( $row->current )
							$classes[] = 'my-row';

						echo '<tr class="' . implode( ' ', $classes ) . '">';

						foreach ( $columns as $column_id => $column_label ) {

							// Position column
							if ( $column_id == 'position' ) {
								$content = apply_filters( "mycred_bp_leaderboard_position_column", $row->position, $row, $group_id, $point_type );
							}

							// Member column
							elseif ( $column_id == 'member' ) {
								$user        = get_userdata( $row->user_id );
								$profile_url = bp_core_get_user_domain( $row->user_id );
								$content     = apply_filters( "mycred_bp_leaderboard_member_column", '<a href="' . $profile_url . '">' . esc_attr( $user->display_name ) . '</a>', $row, $group_id, $point_type );
							}

							// Balance column
							elseif ( $column_id == 'balance' ) {
								$content     = apply_filters( "mycred_bp_leaderboard_balance_column", $mycred->format_creds( $row->balance ), $row, $group_id, $point_type );
							}

							// Custom columns
							else {
								$content     = apply_filters( "mycred_bp_leaderboard_{$column_id}_column", '', $row, $group_id, $point_type );
							}

							echo '<td class="leaderboard-column column-' . $column_id . ' ' . $column_id . '">' . $content . '</td>';

						}

						echo '</tr>';

					}

				}

				else {

					echo '<tr><td colspan="' . count( $columns ) . '">' . __( 'The leaderboard is empty.', 'bpmycredleaderboard' ) . '</td></tr>';

				}

?>
		</tbody>
	</table>
</div>
<?php

				do_action( 'mycred_bp_leaderboard_after', $group_id, $this );

			}
			else {

				echo apply_filters( 'mycred_bp_leaderboard_excluded', '<p>' . __( 'There are no point types to show a leaderboard for.', 'bpmycredleaderboard' ) . '</p>', $group_id, $this );

			}

			do_action( 'mycred_bp_leaderboard_excluded', $group_id, $this );

		}

		/**
		 * Settings Screen
		 * @since 1.0
		 * @version 1.1
		 */
		public function settings_screen( $group_id = NULL ) {

			$setup = mycred_get_bp_group_setup( $group_id );

?>
<style type="text/css">#bp-group-leaderboard-size { max-width: 100px; } ul#bp-group-leaderboard-types { list-style-type: none; margin: 0 0 0 0; padding: 0 0 0 0; } ul#bp-group-leaderboard-types li { list-style-type: none; margin: 0 0 0 0; padding: 0 0 0 0; } ul#bp-group-leaderboard-types li label { display: block; line-height: inherit; } ul#bp-group-leaderboard-types li label input { vertical-align: middle; margin-right: 12px; }</style>
<label for="bp-group-leaderboard-size"><?php _e( 'Size', 'bpmycredleaderboard' ); ?></label>
<input type="text" name="bp_group_leaderboard_size" id="bp-group-leaderboard-size" size="10" placeholder="Required" value="<?php echo esc_attr( $setup['size'] ); ?>" />
<p><?php printf( __( 'The number of users to show in the leaderboard. Maximum %d.', 'bpmycredleaderboard' ), MYCRED_BP_GROUP_LEAD_MAX_SIZE ); ?></p>
<p><label for="bp-group-show-current-user"><input type="checkbox" name="bp_group_show_current_user" id="bp-group-show-current-user"<?php checked( (int) $setup['current'], 1 ); ?> value="1" /> <?php _e( 'Append the position of the member viewing the leaderboard if they are not in the set size.', 'bpmycredleaderboard' ); ?></label></p>
<?php

			if ( ! empty( $this->leaderboard_types ) ) {

?>
<label for="bp-leaderboard-type-current"><?php _e( 'Leaderboard Type', 'bpmycredleaderboard' ); ?></label>
<ul id="bp-group-leaderboard-types">
<?php

				foreach ( $this->leaderboard_types as $type_id => $type_label ) {

?>
	<li>
		<label for="bp-leaderboard-type-<?php echo esc_attr( $type_id ); ?>" style="font-weight: normal;"><input type="radio" name="bp_group_leaderboard_type" class="bp-leaderboard-type" id="bp-leaderboard-type-<?php echo esc_attr( $type_id ); ?>"<?php checked( $setup['type'], $type_id ); ?> value="<?php echo esc_attr( $type_id ); ?>" /> <?php echo esc_attr( $type_label ); ?></label>
		<?php do_action( "mycred_bp_leaderboard_{$type_id}_field", $setup['type'], $group_id ); ?>
	</li>
<?php

				}

?>
</ul>
<?php

			}

			do_action( 'mycred_bp_leaderboard_settings', $group_id, $this );

		}

		/**
		 * Custom Leaderboard
		 * @since 1.0
		 * @version 1.1
		 */
		public function custom_leaderboard_fields( $leaderboard_type, $group_id = false ) {

			if ( ! is_array( $group_id ) )
				$setup = mycred_get_bp_group_setup( $group_id );
			else
				$setup = $group_id;

?>
<div id="leaderboard-timeframe" style="display: <?php if ( $leaderboard_type == 'custom' ) echo 'block'; else echo 'none'; ?>;">
	<p><label for="bp-group-leaderboard-from"><?php _e( 'From Date:', 'bpmycredleaderboard' ); ?></label><input type="date" placeholder="YYYY-MM-DD" name="bp_group_leaderboard_from" id="bp-group-leaderboard-from" value="<?php echo esc_attr( $setup['from'] ); ?>" /></p>
	<p><label for="bp-group-leaderboard-until"><?php _e( 'Until Date:', 'bpmycredleaderboard' ); ?></label><input type="date" placeholder="YYYY-MM-DD" name="bp_group_leaderboard_until" id="bp-group-leaderboard-until" value="<?php echo esc_attr( $setup['until'] ); ?>" /></p>
</div>
<script type="text/javascript">
jQuery(function($){

	$( 'input.bp-leaderboard-type' ).change(function(){

		if ( $(this).val() == 'custom' ) {
			$( '#leaderboard-timeframe' ).show();
		}
		else {
			$( '#leaderboard-timeframe' ).hide();
		}

	});

});
</script>
<?php

		}

		/**
		 * Save Settings
		 * @since 1.0
		 * @version 1.1
		 */
		public function settings_screen_save( $group_id = NULL ) {

			$setup            = mycred_get_bp_group_setup( $group_id );

			$number           = isset( $_POST['bp_group_leaderboard_size'] ) ? absint( $_POST['bp_group_leaderboard_size'] ) : $this->settings['size'];
			if ( $number > MYCRED_BP_GROUP_LEAD_MAX_SIZE ) $number = MYCRED_BP_GROUP_LEAD_MAX_SIZE;

			// Save if value differs
			if ( $number != $setup['size'] )
				groups_update_groupmeta( $group_id, 'bp_group_leaderboard_size', $number );

			$show_current     = isset( $_POST['bp_group_show_current_user'] ) ? absint( $_POST['bp_group_show_current_user'] ) : 0;

			// Save if value differs
			if ( (int) $setup['current'] != $show_current )
				groups_update_groupmeta( $group_id, 'bp_group_show_current_user', $show_current );

			$leaderboard_type = isset( $_POST['bp_group_leaderboard_type'] ) ? sanitize_key( $_POST['bp_group_leaderboard_type'] ) : 'current';
			if ( ! empty( $this->leaderboard_types ) && ! array_key_exists( $leaderboard_type, $this->leaderboard_types ) ) $leaderboard_type = 'current';

			if ( ! empty( $this->leaderboard_types ) ) {

				// Save if value differs
				if ( $setup['type'] != $leaderboard_type )
					groups_update_groupmeta( $group_id, 'bp_group_leaderboard_type', $leaderboard_type );

				do_action( "mycred_bp_leaderboard_{$leaderboard_type}_save", $group_id, $this );

			}

			// When dealing with custom leaderboards, we need to check the start and end dates
			if ( $leaderboard_type == 'custom' ) {

				$from       = sanitize_text_field( $_POST['bp_group_leaderboard_from'] );
				$unix_from  = strtotime( $from );
				$until      = sanitize_text_field( $_POST['bp_group_leaderboard_until'] );
				$unix_until = strtotime( $until );

				if ( $unix_from !== false && $unix_from > 0 && $unix_until !== false && $unix_until > 0 ) {

					// Save if value differs
					if ( $setup['from'] != $from )
						groups_update_groupmeta( $group_id, 'leaderboard_from', $from );

					// Save if value differs
					if ( $setup['until'] != $until )
						groups_update_groupmeta( $group_id, 'leaderboard_until', $until );

				}

			}

			// Delete cached leaderboards
			foreach ( $setup['types'] as $point_type )
				groups_delete_groupmeta( $group_id, 'cached_leaderboard' . $point_type );

			do_action( 'mycred_bp_leaderboard_save', $group_id, $this );

			global $wpdb;

			// Delete positions for all group members
			$wpdb->delete(
				$wpdb->usermeta,
				array( 'meta_key' => '_bp_leaderboard_position' . $group_id ),
				array( '%s' )
			);

		}

	}
endif;
