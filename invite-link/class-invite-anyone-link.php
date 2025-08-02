<?php

/**
 * Main class for Invite Anyone Link module.
 *
 * Handles the creation and management of invitation links.
 *
 * @package Invite Anyone
 * @since 1.5.0
 */
class Invite_Anyone_Link {
	/**
	 * Constructor.
	 *
	 * Registers hooks and initializes the module.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		// Hook to group creation to generate invite token.
		add_action( 'groups_create_group', array( $this, 'add_invite_token_to_group' ), 10, 3 );

		// Initialize rewrite rules and query handling.
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_invite_link' ) );

		// Handle login and registration redirects.
		add_action( 'wp_login', array( $this, 'redirect_after_successful_login' ), 10, 2 ); // Only triggers on successful login.
		add_action( 'bp_core_signup_user', array( $this, 'store_invite_token_for_new_user' ), 10, 5 );
		add_action( 'bp_init', array( $this, 'redirect_new_user_after_activation' ) );

		// Handle accept/reject actions.
		add_action( 'admin_post_accept_group_invite', array( $this, 'handle_accept_invite' ) );
		add_action( 'admin_post_reject_group_invite', array( $this, 'handle_reject_invite' ) );
		// Add registration page message for group invitations.
		add_action( 'bp_before_register_page', array( $this, 'group_invite_register_screen_message' ) );

		add_action( 'admin_init', array( $this, 'register_group_invite_link_settings' ) );

		// AJAX handler for creating invite page.
		add_action( 'wp_ajax_create_group_invite_page', array( $this, 'ajax_create_group_invite_page' ) );
	}

	/**
	 * Adds an invite token to group meta on group creation.
	 *
	 * @since 1.5.0
	 *
	 * @param int              $group_id The group ID.
	 * @param BP_Groups_Member $member   The group creator member object.
	 * @param BP_Groups_Group  $group    The group object.
	 */
	public function add_invite_token_to_group( $group_id, $member, $group ) {
		$token = wp_generate_uuid4(); // Generate a UUIDv4 token.
		// Store the token in group meta.
		groups_update_groupmeta( $group_id, 'invite_link_token', $token );
	}

	/**
	 * Adds rewrite rules for invitation links.
	 *
	 * Creates a custom URL structure: yoursite.com/invite/{uuid}
	 * Also creates rules for custom pages: yoursite.com/page-name/{uuid}
	 *
	 * @since 1.5.0
	 */
	public function add_rewrite_rules() {
		// Original invite URL structure.
		add_rewrite_rule( '^invite/([^/]+)/?$', 'index.php?group_invite_token=$matches[1]', 'top' );

		// Get the selected invite page to create specific rewrite rule.
		$invite_page_id = get_option( 'group_invite_page', '' );

		if ( ! empty( $invite_page_id ) && get_post_status( $invite_page_id ) === 'publish' ) {
			$page = get_post( $invite_page_id );
			if ( $page ) {
				$page_slug = $page->post_name;
				// Add rewrite rule for custom page with token: page-slug/{token}/
				add_rewrite_rule( '^' . $page_slug . '/([^/]+)/?$', 'index.php?pagename=' . $page_slug . '&group_invite_token=$matches[1]', 'top' );
			}
		}
	}

	/**
	 * Adds custom query variables.
	 *
	 * @since 1.5.0
	 *
	 * @param array $vars Existing query variables.
	 * @return array Modified query variables.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'group_invite_token';
		return $vars;
	}

	/**
	 * Handles invitation link requests.
	 *
	 * Processes incoming invitation links and manages user flow.
	 *
	 * @since 1.5.0
	 */
	public function handle_invite_link() {
		$invite_token = get_query_var( 'group_invite_token' );

		// Also check for token in URL query parameters (for custom pages).
		if ( ! $invite_token && isset( $_GET['group_invite_token'] ) ) {
			$invite_token = sanitize_text_field( $_GET['group_invite_token'] );
		}

		if ( ! $invite_token ) {
			return;
		}

		// Find the group by the token.
		$group_id = $this->get_group_id_by_token( $invite_token );

		if ( ! $group_id ) {
			// Handle invalid token, redirect to homepage.
			wp_redirect( home_url() );
			exit;
		}

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();

			// Check if user is already a member.
			if ( groups_is_user_member( $user_id, $group_id ) ) {
				// Redirect to the group page with a notice.
				bp_core_add_message( __( 'You are already a member of this group.', 'invite-anyone' ), 'info' );
				wp_redirect( bp_get_group_permalink( groups_get_group( $group_id ) ) );
				exit;
			}

			// Display the invitation template.
			$this->display_invite_template( $group_id );
			exit;

		} else {
			// For non-logged-in users.
			// Store the invite token in a cookie that expires in 1 hour.
			setcookie( 'bb_group_invite_token', $invite_token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN );

			// Redirect to the registration page.
			wp_redirect( bp_get_signup_page() );
			exit;
		}
	}

	/**
	 * Gets group ID by invitation token.
	 *
	 * @since 1.5.0
	 *
	 * @param string $token The invitation token.
	 * @return int|null Group ID or null if not found.
	 */
	public function get_group_id_by_token( $token ) {
		global $wpdb;
		$bp = buddypress();

		$table_name = $bp->groups->table_name_groupmeta;
		$sql        = $wpdb->prepare( "SELECT group_id FROM {$table_name} WHERE meta_key = 'invite_link_token' AND meta_value = %s", $token );
		$group_id   = $wpdb->get_var( $sql );

		return $group_id;
	}

	/**
	 * Redirects user after successful login if they have an invitation token.
	 *
	 * @since 1.5.0
	 *
	 * @param string $user_login The user's login username.
	 * @param WP_User $user The user object.
	 * @return void
	 */
	public function redirect_after_successful_login( $user_login, $user ) {
		if ( isset( $_COOKIE['bb_group_invite_token'] ) ) {
			$token = $_COOKIE['bb_group_invite_token'];
			// Unset the cookie.
			setcookie( 'bb_group_invite_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );

			// Get the invite URL using the configured method.
			$group_id = $this->get_group_id_by_token( $token );
			if ( $group_id ) {
				$invite_url = $this->get_group_invite_url( $group_id );
				if ( $invite_url ) {
					wp_redirect( $invite_url );
					exit;
				}
			}

			// Fallback to old structure.
			wp_redirect( home_url( '/invite/' . $token . '/' ) );
			exit;
		}
	}

	/**
	 * Stores invitation token for new users during registration.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $user_id       The user ID.
	 * @param string $user_login    The user login.
	 * @param string $user_password The user password.
	 * @param string $user_email    The user email.
	 * @param array  $usermeta      User metadata.
	 */
	public function store_invite_token_for_new_user( $user_id, $user_login, $user_password, $user_email, $usermeta ) {
		if ( isset( $_COOKIE['bb_group_invite_token'] ) ) {
			$token = $_COOKIE['bb_group_invite_token'];
			update_user_meta( $user_id, 'pending_group_invite_token', $token );
		}
	}

	/**
	 * Redirects new users after activation if they have a pending invitation.
	 *
	 * @since 1.5.0
	 */
	public function redirect_new_user_after_activation() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_is_activation_page' ) || ! bp_is_activation_page() ) {
			return;
		}

		$user_id = get_current_user_id();
		$token   = get_user_meta( $user_id, 'pending_group_invite_token', true );

		if ( $token ) {
			delete_user_meta( $user_id, 'pending_group_invite_token' );

			// Get the invite URL using the configured method.
			$group_id = $this->get_group_id_by_token( $token );
			if ( $group_id ) {
				$invite_url = $this->get_group_invite_url( $group_id );
				if ( $invite_url ) {
					wp_redirect( $invite_url );
					exit;
				}
			}

			// Fallback to old structure.
			wp_redirect( home_url( '/invite/' . $token . '/' ) );
			exit;
		}
	}

	/**
	 * Displays the invitation template.
	 *
	 * @since 1.5.0
	 *
	 * @param int $group_id The group ID.
	 */
	public function display_invite_template( $group_id ) {
		// Look for template in theme first, then plugin fallback.
		$template_paths = array(
			get_stylesheet_directory() . '/single-group-invite.php',
			get_template_directory() . '/single-group-invite.php',
			plugin_dir_path( __FILE__ ) . 'templates/single-group-invite.php',
		);

		$template_found = false;
		foreach ( $template_paths as $template ) {
			if ( file_exists( $template ) ) {
				// Make the group ID available to the template.
				global $bb_invite_group_id;
				$bb_invite_group_id = $group_id;
				load_template( $template );
				$template_found = true;
				break;
			}
		}

		if ( ! $template_found ) {
			// Fallback inline template.
			$this->display_fallback_template( $group_id );
		}
	}

	/**
	 * Displays a fallback invitation template.
	 *
	 * @since 1.5.0
	 *
	 * @param int $group_id The group ID.
	 */
	private function display_fallback_template( $group_id ) {
		$group = groups_get_group( $group_id );

		get_header();
		?>
		<div style="min-height: 600px; display: flex; align-items: center; justify-content: center;">
			<main id="main" class="site-main">
				<div>
					<div>
						<h1><?php printf( __( 'You have been invited to join: %s', 'invite-anyone' ), esc_html( $group->name ) ); ?></h1>
						<?php if ( ! empty( $group->description ) ) : ?>
							<div>
								<p><?php echo esc_html( $group->description ); ?></p>
							</div>
						<?php endif; ?>
					</div>

					<div style="margin-top: 30px;">
						<a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=accept_group_invite&group_id=' . $group_id ), 'accept_group_invite_' . $group_id ); ?>"
							class="button button-primary"
							style="margin-right: 10px;">
							<?php _e( 'Accept Invitation', 'invite-anyone' ); ?>
						</a>
						<a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=reject_group_invite' ), 'reject_group_invite' ); ?>"
							class="error">
							<?php _e( 'Decline Invitation', 'invite-anyone' ); ?>
						</a>
					</div>

					<div style="margin-top: 30px;">
						<p><?php printf( __( 'By accepting this invitation, you will become a member of %s.', 'invite-anyone' ), esc_html( $group->name ) ); ?></p>
					</div>
				</div>
			</main>
		</div>
		<?php
		get_footer();
	}

	/**
	 * Handles accepting group invitations.
	 *
	 * @since 1.5.0
	 */
	public function handle_accept_invite() {
		if ( ! isset( $_GET['group_id'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'accept_group_invite_' . $_GET['group_id'] ) ) {
			wp_die( 'Invalid request' );
		}

		$group_id = intval( $_GET['group_id'] );
		$user_id  = get_current_user_id();

		if ( groups_join_group( $group_id, $user_id ) ) {
			bp_core_add_message( __( 'You have successfully joined the group.', 'invite-anyone' ), 'success' );
			wp_redirect( bp_get_group_permalink( groups_get_group( $group_id ) ) );
		} else {
			bp_core_add_message( __( 'There was an error joining the group.', 'invite-anyone' ), 'error' );
			wp_redirect( home_url() );
		}
		exit;
	}

	/**
	 * Handles rejecting group invitations.
	 *
	 * @since 1.5.0
	 */
	public function handle_reject_invite() {
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'reject_group_invite' ) ) {
			wp_die( 'Invalid request' );
		}

		bp_core_add_message( __( 'You have declined the invitation.', 'invite-anyone' ), 'info' );

		// Redirect to user's profile or home if function doesn't exist.
		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			wp_redirect( bp_core_get_user_domain( get_current_user_id() ) );
		} else {
			wp_redirect( home_url() );
		}
		exit;
	}

	/**
	 * Gets the invitation URL for a group.
	 *
	 * @since 1.5.0
	 *
	 * @param int $group_id The group ID.
	 * @return string|false The invitation URL or false if no token exists.
	 */
	public function get_group_invite_url( $group_id ) {
		$token = groups_get_groupmeta( $group_id, 'invite_link_token' );

		if ( empty( $token ) ) {
			return false;
		}

		// Get the selected invite page.
		$invite_page_id = get_option( 'group_invite_page', '' );

		if ( ! empty( $invite_page_id ) && get_post_status( $invite_page_id ) === 'publish' ) {
			// Use the selected page with token as slug.
			$page_permalink = get_permalink( $invite_page_id );
			return rtrim( $page_permalink, '/' ) . '/' . $token . '/';
		} else {
			// Fallback to the old URL structure.
			return home_url( '/invite/' . $token . '/' );
		}
	}

	/**
	 * Regenerates the invitation token for a group.
	 *
	 * @since 1.5.0
	 *
	 * @param int $group_id The group ID.
	 * @return string The new token.
	 */
	public function regenerate_group_invite_token( $group_id ) {
		$token = wp_generate_uuid4(); // Generate a new UUIDv4 token.
		groups_update_groupmeta( $group_id, 'invite_link_token', $token );
		return $token;
	}

	/**
	 * Checks if a user can generate invitation links for a group.
	 *
	 * @since 1.5.0
	 *
	 * @param int $user_id  The user ID.
	 * @param int $group_id The group ID.
	 * @return bool True if user can generate links, false otherwise.
	 */
	public function user_can_generate_invite_link( $user_id, $group_id ) {
		// Check if user is group admin or moderator.
		if ( groups_is_user_admin( $user_id, $group_id ) || groups_is_user_mod( $user_id, $group_id ) ) {
			return true;
		}

		// Allow site admins to generate links for any group.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Validates an invitation token.
	 *
	 * @since 1.5.0
	 *
	 * @param string $token The token to validate.
	 * @return array|false Array with group_id if valid, false otherwise.
	 */
	public function validate_invite_token( $token ) {
		$group_id = $this->get_group_id_by_token( $token );

		if ( ! $group_id ) {
			return false;
		}

		// Check if group still exists.
		$group = groups_get_group( $group_id );
		if ( ! $group || ! $group->id ) {
			return false;
		}

		return array(
			'group_id' => $group_id,
			'group'    => $group,
		);
	}

	/**
	 * Displays a welcome message on the registration screen for group invited users.
	 *
	 * This function shows a personalized welcome message to users who are
	 * accepting group invitations via invitation links. It displays the name
	 * of the group they're being invited to join.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function group_invite_register_screen_message() {
		// Check if user has a group invitation token cookie.
		if ( ! isset( $_COOKIE['bb_group_invite_token'] ) ) {
			return;
		}

		$token = $_COOKIE['bb_group_invite_token'];

		// Validate the token and get group information.
		$validation_result = $this->validate_invite_token( $token );

		if ( ! $validation_result ) {
			return;
		}

		$group = $validation_result['group'];

		?>
		<aside class="bp-feedback bp-messages bp-template-notice info">
			<span class="bp-icon" aria-hidden="true"></span>
			<p>
			<?php
				printf(
					__( 'Welcome! You have been invited to join "%s". Create an account or login to join', 'invite-anyone' ),
					esc_html( $group->name )
				);
			?>
				</p>
		</aside>
		<?php
	}

	/**
	 * Registers a settings section for group invite link settings in the admin area.
	 *
	 * @since 1.5.0
	 */
	public function register_group_invite_link_settings() {
		add_settings_section(
			'invite_anyone_group_invite_links',
			__( 'Group Invite Link Settings', 'invite-anyone' ),
			array( $this, 'group_invite_link_settings_section_description' ),
			'bp-groups'
		);

		add_settings_field(
			'group_invite_page',
			__( 'Group Invite Page', 'invite-anyone' ),
			array( $this, 'group_invite_page_field_callback' ),
			'bp-groups',
			'invite_anyone_group_invite_links'
		);

		add_settings_field(
			'create_invite_page',
			__( 'Create Invite Page', 'invite-anyone' ),
			array( $this, 'create_invite_page_field_callback' ),
			'bp-groups',
			'invite_anyone_group_invite_links'
		);
		register_setting( 'bp-groups', 'group_invite_page' );
	}

	/**
	 * Section description for group invite link settings.
	 *
	 * @since 1.5.0
	 */
	public function group_invite_link_settings_section_description() {
		echo esc_html__( 'Configure settings for group invite links.', 'invite-anyone' ) . '.';
	}


	/**
	 * Field callback for selecting group invite page.
	 *
	 * @since 1.5.0
	 * @param array $args Field arguments.
	 */
	public function group_invite_page_field_callback( $args ) {
		$selected_page = get_option( 'group_invite_page', '' );
		$pages         = get_pages();
		?>
		<select name="group_invite_page" id="group_invite_page">
			<option value=""><?php esc_html_e( 'Select a page...', 'invite-anyone' ); ?></option>
			<?php foreach ( $pages as $page ) : ?>
				<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $selected_page, $page->ID ); ?>>
					<?php echo esc_html( $page->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the page that will handle group invitations. The invite token will be passed as a URL slug: yourpage.com/page-name/{token}/', 'invite-anyone' ); ?>
		</p>
		<?php
	}

	/**
	 * Field callback for creating a new invite page.
	 *
	 * @since 1.5.0
	 * @param array $args Field arguments.
	 */
	public function create_invite_page_field_callback( $args ) {
		?>
		<button type="button" id="create_invite_page_btn" class="button button-secondary">
			<?php esc_html_e( 'Create New Invite Page', 'invite-anyone' ); ?>
		</button>
		<p class="description">
			<?php esc_html_e( 'Click to automatically create a new page for group invitations with the required shortcode.', 'invite-anyone' ); ?>
		</p>
		
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#create_invite_page_btn').on('click', function() {
				var button = $(this);
				button.prop('disabled', true).text('<?php esc_html_e( 'Creating...', 'invite-anyone' ); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'create_group_invite_page',
						nonce: '<?php echo wp_create_nonce( 'create_invite_page' ); ?>'
					},
					success: function(response) {
						if (response.success) {
							// Add the new page to the dropdown and select it.
							var option = '<option value="' + response.data.page_id + '" selected>' + response.data.page_title + '</option>';
							$('#group_invite_page').append(option);
							alert('<?php esc_html_e( 'Invite page created successfully!', 'invite-anyone' ); ?>');
						} else {
							alert('<?php esc_html_e( 'Error creating page: ', 'invite-anyone' ); ?>' + response.data);
						}
					},
					error: function() {
						alert('<?php esc_html_e( 'An error occurred while creating the page.', 'invite-anyone' ); ?>');
					},
					complete: function() {
						button.prop('disabled', false).text('<?php esc_html_e( 'Create New Invite Page', 'invite-anyone' ); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler for creating a new group invite page.
	 *
	 * @since 1.5.0
	 */
	public function ajax_create_group_invite_page() {
		// Verify nonce.
		if ( ! wp_verify_nonce( $_POST['nonce'], 'create_invite_page' ) ) {
			wp_die( 'Security check failed.' );
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		// Create the page.
		$page_data = array(
			'post_title'   => __( 'Group Invitation', 'invite-anyone' ),
			'post_content' => '[group_invite_handler]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id(),
		);

		$page_id = wp_insert_post( $page_data );

		if ( is_wp_error( $page_id ) ) {
			wp_send_json_error( $page_id->get_error_message() );
		}

		// Update the option to use this page.
		update_option( 'group_invite_page', $page_id );

		wp_send_json_success(
			array(
				'page_id'    => $page_id,
				'page_title' => get_the_title( $page_id ),
			)
		);
	}
}
