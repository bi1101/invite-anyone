<?php
/**
 * REST API controller for Invite Anyone group invite links.
 *
 * @package Invite Anyone
 * @since 1.5.1
 */
class Invite_Anyone_Link_REST_Controller extends WP_REST_Controller {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'invite-anyone/v1';
		$this->rest_base = 'groups';
		// Register REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/invite-link',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_invite_link' ),
					'permission_callback' => '__return_true', // Publicly readable.
					'args'                => array(
						'id' => array(
							'description' => __( 'A unique numeric ID for the Group.', 'invite-anyone' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/invite-link/regenerate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate_invite_link' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'A unique numeric ID for the Group.', 'invite-anyone' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Returns the JSON schema for the group invite link endpoints.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'invite-anyone-group-invite-link',
			'type'       => 'object',
			'properties' => array(
				'invite_link' => array(
					'description' => __( 'Group invite link URL.', 'invite-anyone' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'token'       => array(
					'description' => __( 'Group invite token.', 'invite-anyone' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
			),
		);
	}

	/**
	 * Get the invite link for a group.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function get_invite_link( $request ) {
		$group_id = (int) $request['id'];
		if ( ! $group_id || ! function_exists( 'groups_get_groupmeta' ) ) {
			return rest_ensure_response( new WP_Error( 'invalid_group', __( 'Invalid group ID.', 'invite-anyone' ), array( 'status' => 404 ) ) );
		}
		$invite     = new Invite_Anyone_Link();
		$invite_url = $invite->get_group_invite_url( $group_id );
		if ( ! $invite_url ) {
			return rest_ensure_response( new WP_Error( 'no_invite_link', __( 'No invite link found for this group.', 'invite-anyone' ), array( 'status' => 404 ) ) );
		}
		return rest_ensure_response( array( 'invite_link' => $invite_url ) );
	}

	/**
	 * Regenerate the invite link for a group.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function regenerate_invite_link( $request ) {
		$group_id = (int) $request['id'];
		if ( ! $group_id || ! function_exists( 'groups_get_groupmeta' ) ) {
			return rest_ensure_response( new WP_Error( 'invalid_group', __( 'Invalid group ID.', 'invite-anyone' ), array( 'status' => 404 ) ) );
		}
		$invite     = new Invite_Anyone_Link();
		$token      = $invite->regenerate_group_invite_token( $group_id );
		$invite_url = $invite->get_group_invite_url( $group_id );
		return rest_ensure_response(
			array(
				'invite_link' => $invite_url,
				'token'       => $token,
			)
		);
	}

	/**
	 * Permission check for regenerating invite link.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		$group_id = (int) $request['id'];
		$user_id  = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in to regenerate invite links.', 'invite-anyone' ), array( 'status' => 401 ) );
		}
		$invite = new Invite_Anyone_Link();
		if ( $invite->user_can_generate_invite_link( $user_id, $group_id ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'You do not have permission to regenerate invite links for this group.', 'invite-anyone' ), array( 'status' => 403 ) );
	}
}
