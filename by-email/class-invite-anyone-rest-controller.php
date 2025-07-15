<?php
/**
 * Invite Anyone REST API Controller.
 *
 * This class provides the REST API endpoints for managing invitations
 * within the Invite Anyone plugin. It extends the WP_REST_Controller
 * to provide a complete CRUD interface for invitation management.
 *
 * @package Invite Anyone
 * @since 1.5.0
 */
class Invite_Anyone_REST_Controller extends WP_REST_Controller {

	/**
	 * The namespace for the REST API.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	protected $namespace = 'invite-anyone/v1';

	/**
	 * The base of this controller's route.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	protected $rest_base = 'invitations';

	/**
	 * The post type for the invitations.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	protected $post_type = 'ia_invites';

	/**
	 * Constructor for the controller.
	 *
	 * Sets up the post type property using the filtered value to ensure
	 * consistency with the rest of the plugin. Also registers the REST API
	 * routes on the 'rest_api_init' hook.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->post_type = apply_filters( 'invite_anyone_post_type_name', 'ia_invites' );

		// Register the REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * This method registers the REST API routes for managing invitations,
	 * including endpoints for listing, creating, retrieving, updating,
	 * and deleting invitations.
	 *
	 * @since 1.5.0
	 * @see register_rest_route()
	 */
	public function register_routes() {
		// Route for getting all invitations and creating new ones.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Route for getting, updating, and deleting individual invitations.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the invitation.', 'invite-anyone' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to bypass trash and force deletion.', 'invite-anyone' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Additional route for bulk operations.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'batch_items' ),
					'permission_callback' => array( $this, 'batch_items_permissions_check' ),
					'args'                => array(
						'clear_type' => array(
							'type'        => 'string',
							'enum'        => array( 'all', 'accepted', 'unaccepted' ),
							'default'     => 'all',
							'description' => __( 'Type of invitations to clear.', 'invite-anyone' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to get invitations.
	 *
	 * This function ensures that only authorized users can retrieve a list
	 * of invitations. By default, it might be restricted to administrators
	 * or users with specific capabilities.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view invitations.', 'invite-anyone' ),
				array( 'status' => 401 )
			);
		}

		// Users can only see their own invitations unless they have admin permissions for invitations.
		if ( ! current_user_can( 'edit_others_ia_invitations' ) && ! isset( $request['inviter_id'] ) ) {
			$request['inviter_id'] = get_current_user_id();
		}

		return true;
	}

	/**
	 * Retrieves a collection of invitations.
	 *
	 * This method handles the logic for fetching a paginated list of invitations,
	 * applying any filters or search criteria specified in the request.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$args = array(
			'inviter_id'     => $request['inviter_id'] ?? get_current_user_id(),
			'posts_per_page' => $request['per_page'] ?? 10,
			'paged'          => $request['page'] ?? 1,
			'orderby'        => $request['orderby'] ?? 'post_date',
			'order'          => $request['order'] ?? 'DESC',
		);

		// Add email filter if provided.
		if ( ! empty( $request['email'] ) ) {
			$args['invitee_email'] = $request['email'];
		}

		// Add status filter if provided.
		if ( ! empty( $request['status'] ) ) {
			$args['status'] = $request['status'];
		}

		// Add accepted filter if provided.
		if ( isset( $request['accepted'] ) ) {
			if ( $request['accepted'] ) {
				$args['meta_key']     = 'bp_ia_accepted';
				$args['meta_value']   = '';
				$args['meta_compare'] = '!=';
			} else {
				$args['meta_key']   = 'bp_ia_accepted';
				$args['meta_value'] = '';
			}
		}

		$invitation = new Invite_Anyone_Invitation();
		$query      = $invitation->get( $args );

		$invitations = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$invitation_data = $this->prepare_item_for_response( get_post(), $request );
				$invitations[]   = $this->prepare_response_for_collection( $invitation_data );
			}
		}

		wp_reset_postdata();

		$response = rest_ensure_response( $invitations );

		// Add pagination headers.
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Checks if a given request has access to get a specific invitation.
	 *
	 * This function verifies that the current user has permission to view a
	 * single invitation. For example, a user should only be able to see
	 * invitations they have sent, or an administrator should have access to all.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view this invitation.', 'invite-anyone' ),
				array( 'status' => 401 )
			);
		}

		$post = get_post( $request['id'] );
		if ( ! $post || $this->post_type !== $post->post_type ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid invitation ID.', 'invite-anyone' ),
				array( 'status' => 404 )
			);
		}

		// Check if user can view this invitation.
		if ( ! current_user_can( 'edit_others_ia_invitations' ) && (int) $post->post_author !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view this invitation.', 'invite-anyone' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Retrieves one invitation from the collection.
	 *
	 * This method retrieves the details of a single invitation based on its ID.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$post = get_post( $request['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$data = $this->prepare_item_for_response( $post, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to create an invitation.
	 *
	 * This function ensures that only logged-in users with the appropriate
	 * permissions can create new invitations.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create items, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to create invitations.', 'invite-anyone' ),
				array( 'status' => 401 )
			);
		}

		// Check if user has the capability to send invitations.
		if ( ! current_user_can( 'edit_ia_invitations' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to create invitations.', 'invite-anyone' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Creates one invitation from the collection.
	 *
	 * This method handles the creation of a new invitation, including validating
	 * the request data and sending the invitation email.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$args = array(
			'inviter_id'     => get_current_user_id(),
			'invitee_email'  => $request['email'],
			'message'        => $request['message'],
			'subject'        => $request['subject'],
			'groups'         => $request['groups'] ?? false,
			'is_cloudsponge' => $request['is_cloudsponge'] ?? false,
		);

		// Validate required fields.
		if ( empty( $args['invitee_email'] ) || empty( $args['message'] ) || empty( $args['subject'] ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Missing required fields: email, message, and subject are required.', 'invite-anyone' ),
				array( 'status' => 400 )
			);
		}

		// Validate email format.
		if ( ! is_email( $args['invitee_email'] ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Invalid email address.', 'invite-anyone' ),
				array( 'status' => 400 )
			);
		}

		// Check if user has already opted out.
		if ( invite_anyone_check_is_opt_out( $args['invitee_email'] ) ) {
			return new WP_Error(
				'rest_invitation_opted_out',
				__( 'This email address has opted out of receiving invitations.', 'invite-anyone' ),
				array( 'status' => 400 )
			);
		}

		$invitation    = new Invite_Anyone_Invitation();
		$invitation_id = $invitation->create( $args );

		if ( ! $invitation_id ) {
			return new WP_Error(
				'rest_invitation_create_failed',
				__( 'Failed to create invitation.', 'invite-anyone' ),
				array( 'status' => 500 )
			);
		}

		$post = get_post( $invitation_id );
		$data = $this->prepare_item_for_response( $post, $request );

		$response = rest_ensure_response( $data );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $invitation_id ) ) );

		return $response;
	}

	/**
	 * Checks if a given request has access to update a specific invitation.
	 *
	 * This function verifies that the current user has permission to modify an
	 * existing invitation.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the item, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$permission_check = $this->get_item_permissions_check( $request );
		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		$post = get_post( $request['id'] );
		if ( ! current_user_can( 'edit_others_ia_invitations' ) && (int) $post->post_author !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to update this invitation.', 'invite-anyone' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Updates one invitation from the collection.
	 *
	 * This method allows for updating an invitation's status, such as marking it
	 * as accepted or clearing it from the sender's list.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$invitation = new Invite_Anyone_Invitation( $request['id'] );

		// Handle specific actions.
		if ( isset( $request['action'] ) ) {
			switch ( $request['action'] ) {
				case 'mark_accepted':
					$invitation->mark_accepted();
					break;
				case 'mark_opt_out':
					$invitation->mark_opt_out();
					break;
				case 'clear':
					$invitation->clear();
					break;
			}
		}

		$post = get_post( $request['id'] );
		$data = $this->prepare_item_for_response( $post, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to delete a specific invitation.
	 *
	 * This function ensures that only authorized users can delete an invitation.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to delete the item, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->update_item_permissions_check( $request );
	}

	/**
	 * Deletes one invitation from the collection.
	 *
	 * This method handles the deletion of an invitation, which may involve
	 * moving it to the trash rather than permanently deleting it.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$post = get_post( $request['id'] );
		$data = $this->prepare_item_for_response( $post, $request );

		$invitation = new Invite_Anyone_Invitation( $request['id'] );
		$result     = $invitation->clear();

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_delete',
				__( 'The invitation cannot be deleted.', 'invite-anyone' ),
				array( 'status' => 500 )
			);
		}

		$response = rest_ensure_response( $data );
		$response->set_data(
			array(
				'deleted'  => true,
				'previous' => $data->get_data(),
			)
		);

		return $response;
	}

	/**
	 * Checks if a given request has access to batch operations on invitations.
	 *
	 * This function ensures that only authorized users can perform batch operations.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to batch operations, WP_Error object otherwise.
	 */
	public function batch_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to perform batch operations.', 'invite-anyone' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Performs batch operations on invitations.
	 *
	 * This method handles bulk clearing of invitations based on specified criteria.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function batch_items( $request ) {
		$args = array(
			'inviter_id' => get_current_user_id(),
			'type'       => $request['clear_type'] ?? 'all',
		);

		$result = invite_anyone_clear_sent_invite( $args );

		if ( ! $result ) {
			return new WP_Error(
				'rest_batch_failed',
				__( 'Batch operation failed.', 'invite-anyone' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Prepares the invitation for the REST response.
	 *
	 * This method formats the invitation data, ensuring it conforms to the
	 * schema and is suitable for a JSON response.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_Post         $item    Post object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data = array(
			'id'             => $item->ID,
			'subject'        => $item->post_title,
			'message'        => $item->post_content,
			'inviter_id'     => $item->post_author,
			'date_created'   => $item->post_date,
			'date_modified'  => $item->post_modified,
			'status'         => $item->post_status,
			'email'          => get_post_meta( $item->ID, 'invitee_email', true ),
			'accepted'       => get_post_meta( $item->ID, 'bp_ia_accepted', true ),
			'opt_out'        => get_post_meta( $item->ID, 'opt_out', true ),
			'is_cloudsponge' => get_post_meta( $item->ID, 'bp_ia_is_cloudsponge', true ),
		);

		// Get associated groups.
		$groups         = wp_get_post_terms( $item->ID, apply_filters( 'invite_anyone_invited_group_tax_name', 'ia_invited_groups' ) );
		$data['groups'] = wp_list_pluck( $groups, 'name' );

		// Add inviter information.
		$inviter = get_userdata( $item->post_author );
		if ( $inviter ) {
			$data['inviter'] = array(
				'id'           => $inviter->ID,
				'display_name' => $inviter->display_name,
				'email'        => $inviter->user_email,
			);
		}

		$context = $request['context'] ?? 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		$response = rest_ensure_response( $data );

		// Add self link.
		$response->add_link( 'self', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $item->ID ) ) );

		return apply_filters( 'invite_anyone_rest_prepare_invitation', $response, $item, $request );
	}

	/**
	 * Retrieves the invitation's schema, conforming to JSON Schema.
	 *
	 * This method defines the structure and properties of the invitation resource,
	 * which is used for validation and generating documentation.
	 *
	 * @since 1.5.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'invitation',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'description' => __( 'Unique identifier for the invitation.', 'invite-anyone' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'subject'        => array(
					'description' => __( 'The subject line of the invitation email.', 'invite-anyone' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'message'        => array(
					'description' => __( 'The content of the invitation email.', 'invite-anyone' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'email'          => array(
					'description' => __( 'The email address of the invitation recipient.', 'invite-anyone' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'inviter_id'     => array(
					'description' => __( 'The ID of the user who sent the invitation.', 'invite-anyone' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'inviter'        => array(
					'description' => __( 'Information about the user who sent the invitation.', 'invite-anyone' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'id'           => array(
							'type' => 'integer',
						),
						'display_name' => array(
							'type' => 'string',
						),
						'email'        => array(
							'type' => 'string',
						),
					),
				),
				'date_created'   => array(
					'description' => __( 'The date the invitation was created.', 'invite-anyone' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'  => array(
					'description' => __( 'The date the invitation was last modified.', 'invite-anyone' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'status'         => array(
					'description' => __( 'The status of the invitation.', 'invite-anyone' ),
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft' ),
					'context'     => array( 'view', 'edit' ),
				),
				'accepted'       => array(
					'description' => __( 'The date the invitation was accepted, if applicable.', 'invite-anyone' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'opt_out'        => array(
					'description' => __( 'Whether the recipient has opted out of invitations.', 'invite-anyone' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'is_cloudsponge' => array(
					'description' => __( 'Whether the email came from CloudSponge.', 'invite-anyone' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'groups'         => array(
					'description' => __( 'Groups that the invitation invites the user to join.', 'invite-anyone' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'action'         => array(
					'description' => __( 'Action to perform on the invitation.', 'invite-anyone' ),
					'type'        => 'string',
					'enum'        => array( 'mark_accepted', 'mark_opt_out', 'clear' ),
					'context'     => array( 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Retrieves the query params for the collection.
	 *
	 * This method defines the parameters that can be used to filter and sort
	 * the collection of invitations.
	 *
	 * @since 1.5.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['inviter_id'] = array(
			'description' => __( 'Limit results to invitations sent by a specific user.', 'invite-anyone' ),
			'type'        => 'integer',
		);

		$params['email'] = array(
			'description' => __( 'Limit results to invitations sent to a specific email address.', 'invite-anyone' ),
			'type'        => 'string',
			'format'      => 'email',
		);

		$params['accepted'] = array(
			'description' => __( 'Limit results to accepted or unaccepted invitations.', 'invite-anyone' ),
			'type'        => 'boolean',
		);

		$params['orderby'] = array(
			'description' => __( 'Sort collection by object attribute.', 'invite-anyone' ),
			'type'        => 'string',
			'default'     => 'post_date',
			'enum'        => array( 'post_date', 'post_title', 'email', 'accepted' ),
		);

		return $params;
	}
}
