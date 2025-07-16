<?php
/**
 * Invite Anyone database functions and schema management.
 *
 * This file contains the core database functionality for the Invite Anyone plugin,
 * including the schema definitions, invitation object methods, and migration routines.
 * It handles the creation and management of custom post types and taxonomies used
 * to store invitation data, as well as various utility functions for working with
 * invitation records.
 *
 * @package Invite Anyone
 * @since 0.8
 */

/**
 * Defines the data schema for IA Invitations.
 *
 * This class handles the database schema initialization and management for the
 * Invite Anyone plugin. It sets up custom post types and taxonomies for storing
 * invitation data, manages database version updates, and provides upgrade routines
 * for migrating data between different plugin versions.
 *
 * @since 0.8
 * @package Invite Anyone
 */
class Invite_Anyone_Schema {
	/**
	 * The name of the custom post type used for storing invitations.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $post_type_name;

	/**
	 * The name of the taxonomy used for storing invitee email addresses.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $invitee_tax_name;

	/**
	 * The name of the taxonomy used for storing invited groups.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $invited_groups_tax_name;

	/**
	 * The current database version for the plugin.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $db_version;

	/**
	 * Initialize the Invite Anyone Schema class and set up database structure.
	 *
	 * This constructor handles the initialization of the database schema for the Invite Anyone plugin.
	 * It sets up the custom post type and taxonomy names, checks for database version updates,
	 * and registers the necessary WordPress hooks for the invitation system.
	 *
	 * The constructor will return early if running on a multisite installation and the current
	 * blog is not the root blog, as the custom post type should only be loaded on the root blog.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @global object $current_blog The current blog object in multisite installations.
	 *
	 * @return void
	 */
	public function __construct() {
		global $current_blog;

		// There's no reason for the CPT to be loaded on non-root-blogs
		if ( is_multisite() && ! bp_is_root_blog( $current_blog->blog_id ) ) {
			return;
		}

		// Check the current db version and update if necessary
		$this->db_version = get_option( 'invite_anyone_db_version' );

		// Check for necessary updates to data schema
		$this->update();

		if ( BP_INVITE_ANYONE_DB_VER !== $this->db_version ) {
			update_option( 'invite_anyone_db_version', BP_INVITE_ANYONE_DB_VER );
			$this->db_version = BP_INVITE_ANYONE_DB_VER;
		}

		// Define the post type name used throughout
		$this->post_type_name = apply_filters( 'invite_anyone_post_type_name', 'ia_invites' );

		// Define the invitee tax name used throughout
		$this->invitee_tax_name = apply_filters( 'invite_anyone_invitee_tax_name', 'ia_invitees' );

		// Define the invited group tax name used throughout
		$this->invited_groups_tax_name = apply_filters( 'invite_anyone_invited_group_tax_name', 'ia_invited_groups' );

		// Hooks into the 'init' action to register our WP custom post type and tax
		add_action( 'init', array( $this, 'register_post_type' ), 1 );
	}

	/**
	 * Registers Invite Anyone's post type and taxonomies
	 *
	 * Data schema:
	 * - The ia_invites post type represents individual invitations, with post data divvied up
	 *   as follows:
	 *      - post_title is the subject of the email sent
	 *      - post_content is the content of the email
	 *      - post_author is the person sending the invitation
	 *      - post_date is the date/time when the invitation is sent
	 *      - post_status represents 'is_hidden' on the old custom table schema:
	 *          - Default is 'publish' - i.e. the user sees the invitation on Sent Invites
	 *          - When the invitation is hidden, it is switched to 'draft'
	 * - The ia_invitees taxonomy represents invited email addresses
	 * - The ia_invited_groups taxonomy represents the groups that a user has been invited to
	 *   when the group invitation is sent
	 * - The following data is stored in postmeta:
	 *  - opt_out (corresponds to old is_opt_out) is stored at opt_out time
	 *  - The invitation accepted date is stored in a post_meta called bp_ia_accepted
	 *
	 * @package BuddyPress Docs
	 * @since 1.0
	 */
	public function register_post_type() {
		global $bp;

		// Define the labels to be used by the post type
		$post_type_labels = apply_filters(
			'invite_anyone_post_type_labels',
			array(
				'name'               => _x( 'BuddyPress Invitations', 'post type general name', 'invite-anyone' ),
				'singular_name'      => _x( 'Invitation', 'post type singular name', 'invite-anyone' ),
				'add_new'            => _x( 'Add New', 'add new', 'invite-anyone' ),
				'add_new_item'       => __( 'Add New Invitation', 'invite-anyone' ),
				'edit_item'          => __( 'Edit Invitation', 'invite-anyone' ),
				'new_item'           => __( 'New Invitation', 'invite-anyone' ),
				'view_item'          => __( 'View Invitation', 'invite-anyone' ),
				'search_items'       => __( 'Search Invitation', 'invite-anyone' ),
				'not_found'          => __( 'No Invitations found', 'invite-anyone' ),
				'not_found_in_trash' => __( 'No Invitations found in Trash', 'invite-anyone' ),
				'parent_item_colon'  => '',
			),
			$this
		);

		// Register the invitation post type
		register_post_type(
			$this->post_type_name,
			apply_filters(
				'invite_anyone_post_type_args',
				array(
					'label'           => __( 'BuddyPress Invitations', 'invite-anyone' ),
					'labels'          => $post_type_labels,
					'public'          => false,
					'_builtin'        => false,
					'show_ui'         => $this->show_dashboard_ui(),
					'hierarchical'    => false,
					'menu_icon'       => plugins_url() . '/invite-anyone/images/smallest_buddypress_icon_ev.png',
					'supports'        => array( 'title', 'editor', 'custom-fields', 'author' ),
					'show_in_rest'    => true,
					'capability_type' => array(
						0 => 'ia_invitation',
						1 => 'ia_invitations',
					),
					'map_meta_cap'    => true,
				),
				$this
			)
		);

		// Define the labels to be used by the invitee taxonomy
		$invitee_labels = apply_filters(
			'invite_anyone_invitee_labels',
			array(
				'name'          => __( 'Invitees', 'invite-anyone' ),
				'singular_name' => __( 'Invitee', 'invite-anyone' ),
				'search_items'  => __( 'Search Invitees', 'invite-anyone' ),
				'all_items'     => __( 'All Invitees', 'invite-anyone' ),
				'edit_item'     => __( 'Edit Invitee', 'invite-anyone' ),
				'update_item'   => __( 'Update Invitee', 'invite-anyone' ),
				'add_new_item'  => __( 'Add New Invitee', 'invite-anyone' ),
				'new_item_name' => __( 'New Invitee Name', 'invite-anyone' ),
				'menu_name'     => __( 'Invitee', 'invite-anyone' ),
			),
			$this
		);

		// Register the invitee taxonomy
		register_taxonomy(
			$this->invitee_tax_name,
			$this->post_type_name,
			apply_filters(
				'invite_anyone_invitee_tax_args',
				array(
					'label'        => __( 'Invitees', 'invite-anyone' ),
					'labels'       => $invitee_labels,
					'hierarchical' => false,
					'public'       => false,
					'show_ui'      => true,
					'show_in_rest' => true,
				),
				$this
			)
		);

		// Define the labels to be used by the invited groups taxonomy
		$invited_groups_labels = apply_filters(
			'invite_anyone_invited_groups_labels',
			array(
				'name'          => __( 'Invited Groups', 'invite-anyone' ),
				'singular_name' => __( 'Invited Group', 'invite-anyone' ),
				'search_items'  => __( 'Search Invited Groups', 'invite-anyone' ),
				'all_items'     => __( 'All Invited Groups', 'invite-anyone' ),
				'edit_item'     => __( 'Edit Invited Group', 'invite-anyone' ),
				'update_item'   => __( 'Update Invited Group', 'invite-anyone' ),
				'add_new_item'  => __( 'Add New Invited Group', 'invite-anyone' ),
				'new_item_name' => __( 'New Invited Group Name', 'invite-anyone' ),
				'menu_name'     => __( 'Invited Group', 'invite-anyone' ),
			),
			$this
		);

		// Register the invited groups taxonomy
		register_taxonomy(
			$this->invited_groups_tax_name,
			$this->post_type_name,
			apply_filters(
				'invite_anyone_invited_group_tax_args',
				array(
					'label'        => __( 'Invited Groups', 'invite-anyone' ),
					'labels'       => $invited_groups_labels,
					'hierarchical' => false,
					'public'       => false,
					'show_ui'      => true,
					'show_in_rest' => true,
				),
				$this
			)
		);

		// Stash in $bp because of template tags that need it
		if ( ! isset( $bp->invite_anyone ) ) {
			$bp->invite_anyone = new stdClass();
		}

		$bp->invite_anyone->invitee_tax_name        = $this->invitee_tax_name;
		$bp->invite_anyone->invited_groups_tax_name = $this->invited_groups_tax_name;
	}

	/**
	 * Determine whether the dashboard UI should be shown for the custom post type.
	 *
	 * This function provides a filtered check for is_super_admin() to determine if the
	 * current user should see the Dashboard UI for the invitation custom post type.
	 * By default, only super admins can see the dashboard interface, but this can be
	 * modified by plugins using the 'show_dashboard_ui' filter.
	 *
	 * @since 0.9
	 * @package Invite Anyone
	 *
	 * @return bool True if the dashboard UI should be shown, false otherwise.
	 */
	public function show_dashboard_ui() {
		return apply_filters( 'show_dashboard_ui', is_super_admin() );
	}

	/**
	 * Check for necessary database schema updates and schedule them.
	 *
	 * This function compares the current database version with the required version
	 * and schedules appropriate upgrade routines to be run. It handles version checks
	 * for multiple plugin versions and ensures that database migrations are performed
	 * in the correct order.
	 *
	 * The function schedules the following upgrades:
	 * - 0.9: Migrates accepted invitation data from date_modified to post meta
	 * - 1.4.0: Installs default email templates
	 * - 1.4.11: Migrates to meta-based email storage for better performance
	 *
	 * @since 0.9
	 * @package Invite Anyone
	 *
	 * @return void
	 */
	public function update() {
		if ( version_compare( $this->db_version, '0.9', '<' ) ) {
			add_action( 'admin_init', array( $this, 'upgrade_0_9' ) );
		}

		if ( version_compare( $this->db_version, '1.4.0', '<' ) ) {
			add_action( 'wp_loaded', array( $this, 'upgrade_1_4_0' ) );
		}

		if ( version_compare( $this->db_version, '1.4.11', '<' ) ) {
			add_action( 'wp_loaded', array( $this, 'upgrade_1_4_11' ) );
		}
	}

	/**
	 * Perform database upgrade for version 0.9.
	 *
	 * This upgrade function migrates invitation acceptance data from the post_modified
	 * field to a dedicated post meta field called 'bp_ia_accepted'. This change provides
	 * better data integrity and makes it easier to query for accepted invitations.
	 *
	 * The function processes invitations in batches of 30 to avoid memory issues with
	 * large datasets. For each invitation, it compares the post_date with post_modified
	 * to determine if the invitation was accepted (dates differ) and stores the appropriate
	 * value in the new meta field.
	 *
	 * @since 0.9
	 * @package Invite Anyone
	 *
	 * @global WP_Query $wp_query The main WordPress query object.
	 * @global WP_Post $post The current post object.
	 *
	 * @return void
	 */
	public function upgrade_0_9() {
		global $wp_query, $post;

		$args = array(
			'posts_per_page' => '30',
			'paged'          => '1',
		);

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

		// Get the invites
		$invite  = new Invite_Anyone_Invitation();
		$invites = $invite->get( $args );

		// Get the total. We're going to loop through them in an attempt to save memory.
		$total_invites = $invites->found_posts;

		unset( $invites );
		unset( $args );

		// WP bug
		$old_wp_query = $wp_query;

		$paged = 0;
		while ( $paged * 30 <= $total_invites ) {
			++$paged;

			$args = array(
				'posts_per_page' => '30',
				'paged'          => $paged,
			);

			// Get the invites
			$invite  = new Invite_Anyone_Invitation();
			$invites = $invite->get( $args );

			// I don't understand why, but I have to do this to avoid errors. WP bug?
			$wp_query = $invites;

			if ( $invites->have_posts() ) {
				while ( $invites->have_posts() ) {
					$invites->the_post();

					// Migrate the accepted data from date_modified to a meta
					if ( ! get_post_meta( get_the_ID(), 'bp_ia_accepted', true ) ) {
						// When the dates are different, it's been accepted
						if ( $post->post_date !== $post->post_modified ) {
							update_post_meta( get_the_ID(), 'bp_ia_accepted', $post->post_modified );
						} else {
							// We set this to null so it still comes up in the
							// meta query
							update_post_meta( get_the_ID(), 'bp_ia_accepted', '' );
						}
					}
				}
			}

			unset( $invites );
			unset( $args );
		}

		// WP bug
		$wp_query = $old_wp_query;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Perform database upgrade for version 1.4.0.
	 *
	 * This upgrade function installs the default email templates for the invitation system.
	 * It calls the invite_anyone_install_emails function with the force parameter set to true
	 * to ensure that email templates are properly installed or updated during the upgrade process.
	 *
	 * @since 1.4.0
	 * @package Invite Anyone
	 *
	 * @return void
	 */
	public function upgrade_1_4_0() {
		invite_anyone_install_emails( true );
	}

	/**
	 * Perform database upgrade for version 1.4.11 - Migrate to meta-based email storage.
	 *
	 * This upgrade function initiates the migration from taxonomy-based email storage
	 * to meta-based email storage for better performance and easier querying. The migration
	 * is scheduled as a background process to avoid timeout issues on sites with large
	 * numbers of invitations.
	 *
	 * The function schedules a single event to run 10 seconds after the upgrade, which
	 * will begin the migration process. The migration handler will process invitations
	 * in batches and reschedule itself until all invitations are migrated.
	 *
	 * @since 1.4.11
	 * @package Invite Anyone
	 *
	 * @return void
	 */
	public function upgrade_1_4_11() {
		// Run the migration in the background to avoid timeouts.
		if ( ! wp_next_scheduled( 'invite_anyone_migrate_to_meta_emails' ) ) {
			wp_schedule_single_event( time() + 10, 'invite_anyone_migrate_to_meta_emails' );
		}
	}
}

$invite_anyone_data = new Invite_Anyone_Schema();

/**
 * Defines the invitation object and its methods.
 *
 * This class represents individual invitation records and provides methods
 * for creating, retrieving, and managing invitations. Each invitation is
 * stored as a WordPress custom post with associated taxonomy terms and
 * meta data to track invitation status, recipients, and related information.
 *
 * @since 0.8
 * @package Invite Anyone
 */
class Invite_Anyone_Invitation {
	/**
	 * The unique ID of the invitation post.
	 *
	 * @since 0.8
	 * @var int|null
	 */
	public $id;

	/**
	 * The name of the taxonomy used for storing invitee email addresses.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $invitee_tax_name;

	/**
	 * The name of the custom post type used for storing invitations.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $post_type_name;

	/**
	 * The name of the taxonomy used for storing invited groups.
	 *
	 * @since 0.8
	 * @var string
	 */
	public $invited_groups_tax_name;

	/**
	 * The order direction for email sorting (used in deprecated functions).
	 *
	 * @since 0.9
	 * @var string
	 */
	public $email_order;

	/**
	 * Initialize the Invite Anyone Invitation object.
	 *
	 * This constructor initializes an invitation object with optional ID parameter.
	 * If an ID is provided, it will be stored in the object for use in subsequent
	 * operations. The constructor also sets up the necessary post type and taxonomy
	 * names using filtered values to ensure consistency throughout the plugin.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @param int|false $id Optional. The unique ID of the invitation post. Default false.
	 *
	 * @return void
	 */
	public function __construct( $id = false ) {
		if ( $id ) {
			$this->id = $id;
		}

		// Define the post type name used throughout
		$this->post_type_name = apply_filters( 'invite_anyone_post_type_name', 'ia_invites' );

		// Define the invitee tax name used throughout
		$this->invitee_tax_name = apply_filters( 'invite_anyone_invitee_tax_name', 'ia_invitees' );

		// Define the invited group tax name used throughout
		$this->invited_groups_tax_name = apply_filters( 'invite_anyone_invited_group_tax_name', 'ia_invited_groups' );
	}

	/**
	 * Create a new invitation record in the database.
	 *
	 * This function creates a new invitation by inserting a WordPress post of the
	 * invitation custom post type and associating it with the appropriate taxonomy
	 * terms and meta data. The function handles all aspects of invitation creation
	 * including validation, post insertion, meta data storage, and taxonomy assignment.
	 *
	 * The function accepts an array of arguments that define the invitation properties.
	 * Required arguments include inviter_id, invitee_email, message, and subject.
	 * Optional arguments include groups, status, dates, and CloudSponge flag.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @param array $args {
	 *     Optional. Array of arguments for creating the invitation.
	 *
	 *     @type int          $inviter_id     ID of the user sending the invitation. Default: current logged-in user.
	 *     @type string       $invitee_email  Email address of the invitation recipient. Required.
	 *     @type string       $message        Content of the invitation email. Required.
	 *     @type string       $subject        Subject line of the invitation email. Required.
	 *     @type array|false  $groups         Array of group IDs to invite the user to join. Default false.
	 *     @type string       $status         Post status for the invitation. Default 'publish'.
	 *     @type string       $date_created   Date when the invitation was created. Default current time.
	 *     @type string       $date_modified  Date when the invitation was last modified. Default current time.
	 *     @type bool         $is_cloudsponge Whether the email came from CloudSponge. Default false.
	 *     @type string|false $recipient_name Name of the invitation recipient. Default false.
	 * }
	 *
	 * @return int|false The ID of the created invitation post on success, false on failure.
	 */
	public function create( $args = false ) {
		// Set up the default arguments
		$defaults = apply_filters(
			'invite_anyone_create_invite_defaults',
			array(
				'inviter_id'     => bp_loggedin_user_id(),
				'invitee_email'  => false,
				'message'        => false,
				'subject'        => false,
				'groups'         => false,
				'status'         => 'publish', // i.e., visible on Sent Invites
				'date_created'   => bp_core_current_time( false ),
				'date_modified'  => bp_core_current_time( false ),
				'is_cloudsponge' => false,
				'recipient_name' => false,
			)
		);

		$r = wp_parse_args( $args, $defaults );

		$inviter_id     = $r['inviter_id'];
		$invitee_email  = $r['invitee_email'];
		$message        = $r['message'];
		$subject        = $r['subject'];
		$groups         = $r['groups'];
		$status         = $r['status'];
		$date_created   = $r['date_created'];
		$date_modified  = $r['date_modified'];
		$is_cloudsponge = $r['is_cloudsponge'];
		$recipient_name = $r['recipient_name'];

		// Let plugins stop this process if they want
		do_action( 'invite_anyone_before_invitation_create', $r, $args );

		// We can't record an invitation without a few key pieces of data
		if ( empty( $inviter_id ) || empty( $invitee_email ) || empty( $message ) || empty( $subject ) ) {
			return false;
		}

		// Set the arguments and create the post
		$insert_post_args = array(
			'post_author'  => $inviter_id,
			'post_content' => $message,
			'post_title'   => $subject,
			'post_status'  => $status,
			'post_type'    => $this->post_type_name,
			'post_date'    => $date_created,
			'post_name'    => sanitize_title_with_dashes( $subject . ' ' . microtime() ),
		);

		$this->id = wp_insert_post( $insert_post_args );

		if ( ! $this->id ) {
			return false;
		}

		// If a date_modified has been passed, update it manually
		if ( $date_modified ) {
			$post_modified_args = array(
				'ID'            => $this->id,
				'post_modified' => $date_modified,
			);

			wp_update_post( $post_modified_args );
		}

		// Save a blank bp_ia_accepted post_meta
		update_post_meta( $this->id, 'bp_ia_accepted', '' );

		// Save a meta item about whether this is a CloudSponge email
		update_post_meta( $this->id, 'bp_ia_is_cloudsponge', $is_cloudsponge ? __( 'Yes', 'invite-anyone' ) : __( 'No', 'invite-anyone' ) );

		// Store email as meta for easier sorting and querying.
		update_post_meta( $this->id, 'invitee_email', $invitee_email );

		// Store recipient name as meta for easier sorting and querying.
		if ( ! empty( $recipient_name ) ) {
			update_post_meta( $this->id, 'recipient_name', $recipient_name );
		}

		// Now set up the taxonomy terms

		// Invitee (keep for backward compatibility and filtering)
		wp_set_post_terms( $this->id, $invitee_email, $this->invitee_tax_name );

		// Groups included in the invitation
		if ( ! empty( $groups ) ) {
			wp_set_post_terms( $this->id, $groups, $this->invited_groups_tax_name );
		}

		do_action( 'invite_anyone_after_invitation_create', $this->id, $r, $args );

		return $this->id;
	}

	/**
	 * Retrieve existing invitations based on specified criteria.
	 *
	 * This function queries the database for invitations that match the provided
	 * criteria. It supports filtering by inviter, invitee email, message content,
	 * subject, associated groups, status, and creation date. The function also
	 * supports pagination and custom ordering of results.
	 *
	 * The function uses WP_Query internally and returns a WP_Query object, allowing
	 * for standard WordPress post loop operations on the results.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @param array $args {
	 *     Optional. Array of arguments for querying invitations.
	 *
	 *     @type int|false    $inviter_id     ID of the user who sent the invitation. Default false.
	 *     @type string|false $invitee_email  Email address of the invitation recipient. Default false.
	 *     @type string|false $message        Content of the invitation email to match. Default false.
	 *     @type string|false $subject        Subject line of the invitation email to match. Default false.
	 *     @type array|false  $groups         Array of group IDs to filter by. Default false.
	 *     @type string       $status         Post status to filter by. Default 'publish'.
	 *     @type string|false $date_created   Date filter for invitation creation. Default false.
	 *     @type int|false    $posts_per_page Number of invitations per page. Default false.
	 *     @type int|false    $paged          Page number for pagination. Default false.
	 *     @type string       $orderby        Field to order results by. Default 'post_date'.
	 *     @type string       $order          Order direction (ASC or DESC). Default 'DESC'.
	 * }
	 *
	 * @return WP_Query Query object containing the matching invitations.
	 */
	public function get( $args = false ) {
		// Set up the default arguments
		$defaults = apply_filters(
			'invite_anyone_get_invite_defaults',
			array(
				'inviter_id'     => false,
				'invitee_email'  => false,
				'message'        => false,
				'subject'        => false,
				'groups'         => false,
				'status'         => 'publish', // i.e., visible on Sent Invites
				'date_created'   => false,
				'posts_per_page' => false,
				'paged'          => false,
				'orderby'        => 'post_date',
				'order'          => 'DESC',
			)
		);

		$r = wp_parse_args( $args, $defaults );

		$inviter_id     = $r['inviter_id'];
		$invitee_email  = $r['invitee_email'];
		$message        = $r['message'];
		$subject        = $r['subject'];
		$groups         = $r['groups'];
		$status         = $r['status'];
		$date_created   = $r['date_created'];
		$posts_per_page = $r['posts_per_page'];
		$paged          = $r['paged'];
		$orderby        = $r['orderby'];
		$order          = $r['order'];

		// Backward compatibility, and to keep the URL args clean
		if ( 'email' === $orderby ) {
			$orderby = 'invitee_email_meta';
		} elseif ( 'ia_invitees' === $orderby ) {
			// Use meta-based sorting instead of problematic global filters.
			$orderby = 'invitee_email_meta';
		} elseif ( 'recipient_name' === $orderby ) {
			$orderby       = 'meta_value';
			$r['meta_key'] = 'recipient_name';
		} elseif ( 'date_joined' === $orderby || 'accepted' === $orderby ) {
			$orderby       = 'meta_value';
			$r['meta_key'] = 'bp_ia_accepted';
		}

		if ( ! $posts_per_page && ! $paged ) {
			$r['posts_per_page'] = '10';
			$r['paged']          = '1';
		}

		// Let plugins stop this process if they want
		do_action( 'invite_anyone_before_invitation_get', $r, $args );

		// Set the arguments and get the posts
		$query_post_args = array(
			'author'      => $inviter_id,
			'post_status' => $status,
			'post_type'   => $this->post_type_name,
			'orderby'     => $orderby,
			'order'       => $order,
			'tax_query'   => array(),
		);

		// Handle email-based sorting with meta queries.
		if ( 'invitee_email_meta' === $orderby ) {
			$query_post_args['meta_key'] = 'invitee_email';
			$query_post_args['orderby']  = 'meta_value';
			$query_post_args['order']    = $order;
		}

		// Handle email filtering with meta queries (more reliable than taxonomy).
		if ( ! empty( $r['invitee_email'] ) ) {
			$query_post_args['meta_query'] = array(
				array(
					'key'     => 'invitee_email',
					'value'   => $r['invitee_email'],
					'compare' => is_array( $r['invitee_email'] ) ? 'IN' : '=',
				),
			);
		}

		// Keep taxonomy query for backward compatibility if needed.
		if ( ! empty( $r['invitee_email'] ) && empty( $query_post_args['meta_query'] ) ) {
			$query_post_args['tax_query']['invitee'] = array(
				'taxonomy' => $this->invitee_tax_name,
				'terms'    => (array) $r['invitee_email'],
				'field'    => 'name',
			);
		}

		// Add optional arguments, if provided
		$optional_args = array(
			'message'        => 'post_content',
			'subject'        => 'post_title',
			'date_created'   => 'date_created',
			'meta_key'       => 'meta_key',
			'meta_value'     => 'meta_value',
			'posts_per_page' => 'posts_per_page',
			'paged'          => 'paged',
		);

		foreach ( $optional_args as $key => $value ) {
			if ( ! empty( $r[ $key ] ) ) {
				$query_post_args[ $value ] = $r[ $key ];
			}
		}

		$query = new WP_Query( $query_post_args );

		return $query;
	}

	/**
	 * Filter the SQL JOIN clause when sorting by invited email address (DEPRECATED).
	 *
	 * This function was used to modify the SQL JOIN clause in WordPress queries
	 * to enable sorting invitations by the invited email address stored in taxonomy
	 * terms. It has been deprecated in favor of the more efficient meta-based
	 * email storage and sorting system.
	 *
	 * @deprecated 1.4.11 Use meta-based email sorting instead.
	 * @since 0.9
	 * @package Invite Anyone
	 *
	 * @param string $join The SQL JOIN clause to be modified.
	 *
	 * @return string The modified JOIN clause with taxonomy table joins.
	 */
	public function filter_join_emails( $join ) {
		_deprecated_function( __METHOD__, '1.4.11', 'Meta-based email sorting' );

		global $wpdb;

		$join .= "
			INNER JOIN {$wpdb->term_relationships} tria ON ( tria.object_id = {$wpdb->posts}.ID )
			INNER JOIN {$wpdb->term_taxonomy} wp_term_taxonomy_ia ON ( tria.term_taxonomy_id = wp_term_taxonomy_ia.term_taxonomy_id )
			INNER JOIN {$wpdb->terms} wp_terms_ia ON ( wp_terms_ia.term_id = wp_term_taxonomy_ia.term_id )
		";

		return $join;
	}

	/**
	 * Filter the SQL SELECT fields when sorting by invited email address (DEPRECATED).
	 *
	 * This function was used to add additional fields to the SELECT clause of
	 * WordPress queries to enable sorting by email addresses stored in taxonomy
	 * terms. It has been deprecated in favor of the more efficient meta-based
	 * email storage and sorting system.
	 *
	 * @deprecated 1.4.11 Use meta-based email sorting instead.
	 * @since 0.9
	 * @package Invite Anyone
	 *
	 * @param string $fields The SQL SELECT fields to be modified.
	 *
	 * @return string The modified SELECT fields with taxonomy term fields.
	 */
	public function filter_fields_emails( $fields ) {
		_deprecated_function( __METHOD__, '1.4.11', 'Meta-based email sorting' );

		$fields .= ' ,wp_terms_ia.name, wp_term_taxonomy_ia.term_taxonomy_id';

		return $fields;
	}

	/**
	 * Filter the SQL ORDER BY clause when sorting by invited email address (DEPRECATED).
	 *
	 * This function was used to modify the ORDER BY clause in WordPress queries
	 * to enable sorting invitations by the invited email address stored in taxonomy
	 * terms. It has been deprecated in favor of the more efficient meta-based
	 * email storage and sorting system.
	 *
	 * @deprecated 1.4.11 Use meta-based email sorting instead.
	 * @since 0.9
	 * @package Invite Anyone
	 *
	 * @param string $orderby The SQL ORDER BY clause to be modified.
	 *
	 * @return string The modified ORDER BY clause for email sorting.
	 */
	public function filter_orderby_emails( $orderby ) {
		_deprecated_function( __METHOD__, '1.4.11', 'Meta-based email sorting' );

		$orderby = 'wp_terms_ia.name ' . $this->email_order;

		return $orderby;
	}

	/**
	 * Mark an invitation as accepted by the recipient.
	 *
	 * This function updates the invitation's meta data to record that the invitation
	 * has been accepted. It sets the 'bp_ia_accepted' meta field to the current GMT
	 * timestamp, which is used throughout the plugin to determine invitation status
	 * and generate acceptance statistics.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @return bool True on successful update, false on failure.
	 */
	public function mark_accepted() {
		update_post_meta( $this->id, 'bp_ia_accepted', gmdate( 'Y-m-d H:i:s' ) );

		return true;
	}

	/**
	 * Clear (hide) an invitation from the Sent Invites list.
	 *
	 * This function changes the post status of an invitation from 'publish' to 'draft',
	 * effectively removing it from the user's Sent Invites list without permanently
	 * deleting the invitation record. This allows users to clean up their invitation
	 * history while preserving the data for administrative purposes.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @return bool True on successful update, false on failure.
	 */
	public function clear() {
		$args = array(
			'ID'          => $this->id,
			'post_status' => 'draft',
		);
		if ( wp_update_post( $args ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Mark an invitation as opted out by the recipient.
	 *
	 * This function sets the 'opt_out' meta field to 'yes' for the invitation,
	 * indicating that the recipient has chosen to opt out of receiving future
	 * invitations. This is typically used when users click unsubscribe links
	 * in invitation emails or explicitly request to stop receiving invitations.
	 *
	 * @since 0.8
	 * @package Invite Anyone
	 *
	 * @return bool True on successful update, false on failure.
	 */
	public function mark_opt_out() {
		if ( update_post_meta( $this->id, 'opt_out', 'yes' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Migrate existing invitations to use meta-based email storage
	 *
	 * This method updates existing invitations that don't have the 'invitee_email' meta
	 * to include it based on their taxonomy terms.
	 *
	 * @package Invite Anyone
	 * @since 1.4.11
	 *
	 * @param int $batch_size Number of invitations to process at once to avoid timeouts.
	 * @return array Results of the migration process.
	 */
	public static function migrate_to_meta_emails( $batch_size = 50 ) {
		$results = array(
			'processed' => 0,
			'updated'   => 0,
			'errors'    => 0,
		);

		$invitations = get_posts(
			array(
				'post_type'      => apply_filters( 'invite_anyone_post_type_name', 'ia_invites' ),
				'posts_per_page' => $batch_size,
				'meta_query'     => array(
					array(
						'key'     => 'invitee_email',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $invitations as $invitation ) {
			++$results['processed'];

			$terms = get_the_terms( $invitation->ID, apply_filters( 'invite_anyone_invitee_tax_name', 'ia_invitees' ) );

			if ( $terms && ! is_wp_error( $terms ) ) {
				$email = $terms[0]->name;

				if ( update_post_meta( $invitation->ID, 'invitee_email', $email ) ) {
					++$results['updated'];
				} else {
					++$results['errors'];
				}
			} else {
				++$results['errors'];
			}
		}

		return $results;
	}
}

/**
 * Record a new invitation in the database.
 *
 * This function serves as a convenient wrapper for creating new invitations.
 * It handles the special case of Gmail "+" addresses by converting them to
 * a format that can be safely stored and retrieved. The function creates
 * a new Invite_Anyone_Invitation object and calls its create method with
 * the provided parameters.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param int          $inviter_id     The ID of the user sending the invitation.
 * @param string       $email          The email address of the invitation recipient.
 * @param string       $message        The content of the invitation email.
 * @param array        $groups         An array of group IDs that the invitation invites the user to join.
 * @param string|false $subject        Optional. The subject line of the invitation email. Default false.
 * @param bool         $is_cloudsponge Optional. Whether this email address originated from CloudSponge. Default false.
 * @param string|false $recipient_name Optional. The name of the invitation recipient. Default false.
 *
 * @return int|false The ID of the created invitation on success, false on failure.
 */
function invite_anyone_record_invitation( $inviter_id, $email, $message, $groups, $subject = false, $is_cloudsponge = false, $recipient_name = false ) {

	// hack to make sure that gmail + email addresses work
	$email = str_replace( '+', '.PLUSSIGN.', $email );

	$args = array(
		'inviter_id'     => $inviter_id,
		'invitee_email'  => $email,
		'message'        => $message,
		'subject'        => $subject,
		'groups'         => $groups,
		'is_cloudsponge' => $is_cloudsponge,
		'recipient_name' => $recipient_name,
	);

	$invite = new Invite_Anyone_Invitation();

	$id = $invite->create( $args );

	return $id;
}


/**
 * Retrieve invitations sent by a specific user.
 *
 * This function provides a convenient way to query all invitations sent by
 * a particular user. It supports optional parameters for ordering, pagination,
 * and limiting the number of results returned. The function is commonly used
 * to display a user's sent invitations in their profile or dashboard.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param int          $inviter_id     The ID of the user whose invitations to retrieve.
 * @param string|false $orderby        Optional. The field to order results by. Default false.
 * @param string|false $order          Optional. The order direction (ASC or DESC). Default false.
 * @param int|false    $posts_per_page Optional. Number of invitations per page. Default false.
 * @param int|false    $paged          Optional. Page number for pagination. Default false.
 *
 * @return WP_Query Query object containing the matching invitations.
 */
function invite_anyone_get_invitations_by_inviter_id( $inviter_id, $orderby = false, $order = false, $posts_per_page = false, $paged = false ) {
	$args = array(
		'inviter_id'     => $inviter_id,
		'orderby'        => $orderby,
		'order'          => $order,
		'posts_per_page' => $posts_per_page,
		'paged'          => $paged,
	);

	$invite = new Invite_Anyone_Invitation();

	return $invite->get( $args );
}

/**
 * Retrieve invitations sent to a specific email address.
 *
 * This function queries the database for all invitations that have been sent
 * to a particular email address. It handles special cases for Gmail "+" addresses
 * by converting them to the stored format and also handles URL decoding issues
 * that may occur when email addresses are passed through URLs.
 *
 * The function is commonly used during the registration process to check if
 * a user has pending invitations or to display invitation history for a
 * specific email address.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param string $email The email address to search for invitations.
 *
 * @return WP_Query Query object containing all invitations sent to the email address.
 */
function invite_anyone_get_invitations_by_invited_email( $email ) {
	// hack to make sure that gmail + email addresses work

	// If the url takes the form register/accept-invitation/username+extra%40gmail.com,
	// urldecode returns a space in place of the +. (This is not typical,
	// but we can catch it.)
	$email = str_replace( ' ', '+', $email );

	// More common: url takes the form register/accept-invitation/username%2Bextra%40gmail.com,
	// so we grab the + that urldecode returns and replace it to create a
	// usable search term.
	$email = str_replace( '+', '.PLUSSIGN.', $email );

	$args = array(
		'invitee_email'  => $email,
		'posts_per_page' => -1,
	);

	$invite = new Invite_Anyone_Invitation();

	return $invite->get( $args );
}

/**
 * Clear invitations from the Sent Invites list based on specified criteria.
 *
 * This function allows users to clean up their sent invitations list by removing
 * invitations based on various criteria. It can clear a specific invitation by ID,
 * or clear multiple invitations based on their acceptance status (accepted,
 * unaccepted, or all).
 *
 * To prevent timeout and memory issues, the function processes a limited number
 * of invitations per request (default 100). For large-scale clearing operations,
 * multiple requests may be required.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param array $args {
 *     Array of arguments for clearing invitations.
 *
 *     @type int|false    $inviter_id ID of the user whose invitations to clear. Required.
 *     @type int|false    $clear_id   ID of a specific invitation to clear. Optional.
 *     @type string|false $type       Type of invitations to clear: 'accepted', 'unaccepted', or 'all'. Optional.
 * }
 *
 * @return bool True on success, false on failure.
 */
function invite_anyone_clear_sent_invite( $args ) {
	global $post;

	/* Accepts arguments: array(
		'inviter_id' => id number of the inviter, (required)
		'clear_id' => id number of the item to be cleared,
		'type' => accepted, unaccepted, or all
	); */

	$defaults = array(
		'inviter_id' => false,
		'clear_id'   => false,
		'type'       => false,
	);
	$args     = wp_parse_args( $args, $defaults );

	$inviter_id = $args['inviter_id'];
	$clear_id   = $args['clear_id'];
	$type       = $args['type'];

	if ( empty( $inviter_id ) ) {
		return false;
	}

	$success = false;

	if ( $clear_id ) {
		$invite = new Invite_Anyone_Invitation( $clear_id );
		if ( $invite->clear() ) {
			$success = true;
		}
	} else {
		/**
		 * Number of invitations to clear during a single request.
		 *
		 * We place a limit on the number of invitations that can be cleared to
		 * avoid timeout and memory-exhaustion errors. You may adjust this
		 * using the filter.
		 *
		 * @since 1.4.8
		 *
		 * @param int $limit The number of invitations to clear during a single request.
		 */
		$limit = apply_filters( 'invite_anyone_clear_sent_invite_limit', 100 );

		$query_args = array(
			'inviter_id'     => $inviter_id,
			'posts_per_page' => $limit,
		);

		$invite = new Invite_Anyone_Invitation();

		$iobj = $invite->get( $query_args );

		if ( $iobj->have_posts() ) {
			while ( $iobj->have_posts() ) {
				$iobj->the_post();

				$clearme = false;
				switch ( $type ) {
					case 'accepted' :
						if ( get_post_meta( get_the_ID(), 'bp_ia_accepted', true ) ) {
							$clearme = true;
						}
						break;
					case 'unaccepted' :
						if ( ! get_post_meta( get_the_ID(), 'bp_ia_accepted', true ) ) {
							$clearme = true;
						}
						break;
					case 'all' :
					default :
						$clearme = true;
						break;
				}

				if ( $clearme ) {
					$this_invite = new Invite_Anyone_Invitation( get_the_ID() );
					$this_invite->clear();
				}
			}
		}
	}

	return true;
}

/**
 * Mark all invitations for a specific email address as accepted/joined.
 *
 * This function is typically called when a user registers or joins the site
 * using an email address that has received invitations. It finds all invitations
 * associated with the email address and marks them as accepted by updating
 * the 'bp_ia_accepted' meta field with the current timestamp.
 *
 * This is useful for tracking invitation success rates and providing feedback
 * to users who sent invitations about whether their invitations were successful.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param string $email The email address of the user who has joined the site.
 *
 * @return bool True on success, false on failure.
 */
function invite_anyone_mark_as_joined( $email ) {
	$invites = invite_anyone_get_invitations_by_invited_email( $email );

	if ( $invites->have_posts() ) {
		while ( $invites->have_posts() ) {
			the_post();

			$invite = new Invite_Anyone_Invitation( get_the_ID() );
			$invite->mark_accepted();
		}
	}

	return true;
}

/**
 * Check if a user has opted out of receiving email invitations.
 *
 * This function queries the database to determine if a specific email address
 * has been marked as opted out of receiving invitations. It checks for any
 * invitation records associated with the email address that have the 'opt_out'
 * meta field set to 'yes'.
 *
 * The function handles Gmail "+" addresses by converting spaces back to plus
 * signs, which may occur during URL processing. This ensures that opt-out
 * status is properly checked regardless of how the email address was formatted
 * in the URL.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param string $email The email address to check for opt-out status.
 *
 * @return bool True if the email has opted out, false otherwise.
 */
function invite_anyone_check_is_opt_out( $email ) {
	$email = str_replace( ' ', '+', $email );

	$args = array(
		'invitee_email'  => $email,
		'posts_per_page' => 1,
		'meta_key'       => 'opt_out',
		'meta_value'     => 'yes',
	);

	$invite = new Invite_Anyone_Invitation();

	$invites = $invite->get( $args );

	if ( $invites->have_posts() ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Mark all invitations for an email address as opted out.
 *
 * This function finds all invitation records associated with a specific email
 * address and marks them as opted out by setting the 'opt_out' meta field to
 * 'yes'. This prevents future invitations from being sent to the email address
 * and provides a record of the opt-out request.
 *
 * The function is typically called when a user clicks an unsubscribe link in
 * an invitation email or when they explicitly request to stop receiving
 * invitations from the site.
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @param string $email The email address to mark as opted out.
 *
 * @return bool True on success, false on failure.
 */
function invite_anyone_mark_as_opt_out( $email ) {
	$invites = invite_anyone_get_invitations_by_invited_email( $email );

	if ( $invites->have_posts() ) {
		while ( $invites->have_posts() ) {
			$invites->the_post();

			$invite = new Invite_Anyone_Invitation( get_the_ID() );
			$invite->mark_opt_out();
		}
	}

	return true;
}

/**
 * Display a migration notice to administrators when database upgrade is needed.
 *
 * This function checks whether the plugin needs to migrate data from the old
 * table-based storage system to the new custom post type system. It displays
 * an admin notice to super administrators prompting them to complete the migration.
 *
 * The function performs several checks:
 * - Only shows the notice to super administrators
 * - Checks if the database version is outdated (pre-0.8)
 * - Verifies that the old table exists and contains data
 * - Automatically handles small migrations (5 or fewer records)
 * - Provides a link to the manual migration process for larger datasets
 *
 * @since 0.8.3
 * @package Invite Anyone
 *
 * @global wpdb $wpdb The WordPress database abstraction object.
 *
 * @return void
 */
function invite_anyone_migrate_nag() {
	global $wpdb;

	// only show the nag to the network admin
	if ( ! is_super_admin() ) {
		return;
	}

	// Some backward compatibility crap
	$maybe_version = get_option( 'invite_anyone_db_version' );
	if ( empty( $maybe_version ) ) {
		$iaoptions     = get_option( 'invite_anyone' );
		$maybe_version = ! empty( $iaoptions['db_version'] ) ? $iaoptions['db_version'] : '0.7';
	}

	// If you're on the Migrate page, no need to show the message
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_migrate = ! empty( $_GET['migrate'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['migrate'] ) );
	if ( $is_migrate ) {
		return;
	}

	// Don't run this migrator if coming from IA 0.8 or greater
	if ( version_compare( $maybe_version, '0.8', '>=' ) ) {
		return;
	}

	$table_exists = $wpdb->get_var( 'SHOW TABLES LIKE %s', "%{$wpdb->base_prefix}bp_invite_anyone%" );

	if ( ! $table_exists ) {
		return;
	}

	// First, check to see whether the data table exists
	$table_name = $wpdb->base_prefix . 'bp_invite_anyone';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$invite_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

	if ( ! $invite_count ) {
		return;
	}

	// The auto-script can usually handle a migration of 5 or less
	if ( (int) $invite_count <= 5 ) {
		invite_anyone_data_migration();
		return;
	} else {
		$url = is_multisite() && function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' ) : admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' );
		$url = add_query_arg( 'migrate', '1', $url );
		$url = wp_nonce_url( $url, 'invite-anyone-migrate' );
		?>

		<div class="error">
			<p>Invite Anyone has been updated. <a href="<?php echo esc_url( $url ); ?>">Click here</a> to migrate your invitation data and complete the upgrade.</p>
		</div>

		<?php
	}
}
add_action( is_multisite() && function_exists( 'is_network_admin' ) ? 'network_admin_notices' : 'admin_notices', 'invite_anyone_migrate_nag' );


/**
 * Migrate invitation data from old table structure to custom post types.
 *
 * This function handles the migration of invitation data from the legacy
 * table-based storage system to the new custom post type system. It processes
 * invitations in batches to avoid timeout issues and provides feedback during
 * the migration process.
 *
 * The function was originally designed to process all records at once but was
 * retrofitted to work in batches of 5 records with JavaScript-based pagination
 * to handle large datasets. This approach prevents timeout and memory issues
 * but results in somewhat complex code structure.
 *
 * For each invitation record, the function:
 * - Checks if the invitation has already been migrated
 * - Creates a new custom post type record
 * - Migrates associated meta data (opt-out status, etc.)
 * - Preserves original dates and relationships
 *
 * @since 0.8
 * @package Invite Anyone
 *
 * @global wpdb $wpdb The WordPress database abstraction object.
 *
 * @param string $type  Optional. Migration type: 'full' for silent migration or 'partial' for step-by-step. Default 'full'.
 * @param int    $start Optional. The record offset to start migration from. Default 0.
 *
 * @return void
 */
function invite_anyone_data_migration( $type = 'full', $start = 0 ) {
	global $wpdb;

	$is_partial = 'full' !== $type;

	$table_name = $wpdb->base_prefix . 'bp_invite_anyone';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total_table_contents = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

	$table_contents_sql = "SELECT * FROM {$table_name}";

	$table_contents_sql .= '  ORDER BY id ASC LIMIT 5';

	if ( $is_partial ) {
		$table_contents_sql .= ' OFFSET ' . intval( $start );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$table_contents = $wpdb->get_results( $table_contents_sql );

	// If this is a stepwise migration, and the start number is too high or the table_contents
	// query is empty, it means we've gotten to the end of the migration.
	if ( $is_partial && ( (int) $start > $total_table_contents ) ) {
		// Finally, update the Invite Anyone DB version so this doesn't run again
		update_option( 'invite_anyone_db_version', BP_INVITE_ANYONE_DB_VER );

		$url = is_multisite() && function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' ) : admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' );
		?>

		<p><?php esc_html_e( 'All done!', 'invite-anyone' ); ?></p>

		<a href="<?php echo esc_url( $url ); ?>" class="button"><?php esc_html_e( 'Finish', 'invite-anyone' ); ?></a>

		<?php

		return;
	}

	// If the resulting array is empty, either there's nothing in the table or the table does
	// not exist (this is probably a new installation)
	if ( empty( $table_contents ) ) {
		return;
	}

	$record_count = 0;

	foreach ( $table_contents as $key => $invite ) {
		$success = false;

		// Instead of grabbing these from a global or something, I'm just filtering them
		// in the same way that they are in the data schema
		$post_type = apply_filters( 'invite_anyone_post_type_name', 'ia_invites' );
		$tax_name  = apply_filters( 'invite_anyone_invitee_tax_name', 'ia_invitees' );

		$invite_exists_args = array(
			'author'       => $invite->inviter_id,
			$tax_name      => $invite->email,
			'date_created' => $invite->date_invited,
			'post_type'    => $post_type,
		);

		$maybe_invite = get_posts( $invite_exists_args );

		if ( empty( $maybe_invite ) ) {
			// First, record the invitation
			$new_invite = new Invite_Anyone_Invitation();
			$args       = array(
				'inviter_id'    => $invite->inviter_id,
				'invitee_email' => $invite->email,
				'message'       => $invite->message,
				'subject'       => __( 'Migrated Invitation', 'invite-anyone' ),
				'groups'        => maybe_unserialize( $invite->group_invitations ),
				'status'        => 'publish',
				'date_created'  => $invite->date_invited,
				'date_modified' => $invite->date_joined,
			);

			$new_invite_id = $new_invite->create( $args );

			if ( $new_invite_id ) {
				// Now look to see whether the item should be opt out
				if ( $invite->is_opt_out ) {
					update_post_meta( $new_invite_id, 'opt_out', 'yes' );
				}

				$success = true;
			}

			if ( $success ) {
				++$record_count;
			}

			if ( $is_partial ) {
				$inviter = bp_core_get_user_displayname( $invite->inviter_id );
				echo esc_html(
					sprintf(
						// translators: %1$s is the inviter's name, %2$s is the email address
						__( 'Importing: %1$s invited %2$s', 'invite-anyone' ),
						$inviter,
						$invite->email
					)
				) . '<br />';
			}
		}
	}

	if ( $is_partial ) {
		$url = is_multisite() && function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' ) : admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' );
		$url = add_query_arg( 'migrate', '1', $url );
		$url = add_query_arg( 'start', $start + 5, $url );
		$url = wp_nonce_url( $url, 'invite-anyone-migrate' );

		?>
		<p><?php esc_html_e( 'If your browser doesn&#8217;t start loading the next page automatically, click this link:', 'invite-anyone' ); ?> <a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Next Invitations', 'invite-anyone' ); ?></a></p>

		<script type='text/javascript'>
			<!--
			function nextpage() {
				location.href = "<?php echo esc_js( $url ); ?>";
			}
			setTimeout( "nextpage()", 1000 );
			//-->
		</script>

		<?php
	}
}

/**
 * Display the migration interface and handle step-by-step migration process.
 *
 * This function provides the user interface for the database migration process.
 * It displays either the initial migration prompt or the progress of an ongoing
 * migration, depending on the URL parameters. The function handles both the
 * start of the migration process and the processing of individual migration steps.
 *
 * The interface includes:
 * - Initial migration prompt with "GO" button
 * - Progress display during migration
 * - JavaScript-based automatic progression between steps
 * - Completion message with link back to admin panel
 *
 * @since 0.8.3
 * @package Invite Anyone
 *
 * @return void
 */
function invite_anyone_migration_step() {
	$url = is_multisite() && function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' ) : admin_url( 'admin.php?page=invite-anyone/admin/admin-panel.php' );
	$url = add_query_arg( 'migrate', '1', $url );
	$url = add_query_arg( 'start', '0', $url );
	$url = wp_nonce_url( $url, 'invite-anyone-migrate' );

	?>
	<div class="wrap">
		<h2><?php esc_html_e( 'Invite Anyone Upgrade', 'invite-anyone' ); ?></h2>

		<?php if ( ! isset( $_GET['start'] ) ) : ?>
			<p><?php esc_html_e( 'Invite Anyone has just been updated, and needs to move some old invitation data in order to complete the upgrade. Click GO to start the process.', 'invite-anyone' ); ?></p>

			<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'GO', 'invite-anyone' ); ?></a>
		<?php else : ?>
			<?php check_admin_referer( 'invite-anyone-migrate' ); ?>

			<?php invite_anyone_data_migration( 'partial', (int) $_GET['start'] ); ?>

		<?php endif ?>
	</div>

	<?php
}

/**
 * Handle scheduled background migration of invitations to meta-based email storage.
 *
 * This function serves as the callback for the 'invite_anyone_migrate_to_meta_emails'
 * scheduled event. It processes a batch of invitations that need to be migrated
 * from taxonomy-based email storage to the new meta-based storage system.
 *
 * The function calls the static migration method and, if there are still invitations
 * to process, schedules another run in 30 seconds. This approach ensures that
 * large migrations can be completed in the background without causing timeout
 * issues or impacting site performance.
 *
 * @since 1.4.11
 * @package Invite Anyone
 *
 * @return void
 */
function invite_anyone_migrate_to_meta_emails_handler() {
	$results = Invite_Anyone_Invitation::migrate_to_meta_emails();

	// If there are more invitations to process, schedule another run.
	if ( $results['processed'] > 0 ) {
		wp_schedule_single_event( time() + 30, 'invite_anyone_migrate_to_meta_emails' );
	}
}
add_action( 'invite_anyone_migrate_to_meta_emails', 'invite_anyone_migrate_to_meta_emails_handler' );

/**
 * Manually trigger the complete migration to meta-based email storage.
 *
 * This function provides a way to manually run the complete migration process
 * from taxonomy-based email storage to meta-based storage. Unlike the scheduled
 * background migration, this function processes all invitations in a single
 * execution by running multiple batches until the migration is complete.
 *
 * The function is useful for:
 * - Administrative purposes when immediate migration is needed
 * - WP-CLI commands that require synchronous execution
 * - Testing and debugging the migration process
 *
 * The function returns detailed statistics about the migration process, including
 * the total number of invitations processed, successfully updated, and any errors
 * encountered during the migration.
 *
 * @since 1.4.11
 * @package Invite Anyone
 *
 * @return array {
 *     Results of the migration process.
 *
 *     @type int $processed Total number of invitations processed.
 *     @type int $updated   Number of invitations successfully updated.
 *     @type int $errors    Number of errors encountered during migration.
 * }
 */
function invite_anyone_manual_migrate_to_meta_emails() {
	$total_results = array(
		'processed' => 0,
		'updated'   => 0,
		'errors'    => 0,
	);

	// Run in batches until all invitations are processed.
	do {
		$results = Invite_Anyone_Invitation::migrate_to_meta_emails();

		$total_results['processed'] += $results['processed'];
		$total_results['updated']   += $results['updated'];
		$total_results['errors']    += $results['errors'];

	} while ( $results['processed'] > 0 );

	return $total_results;
}

?>
