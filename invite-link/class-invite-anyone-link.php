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
	}

	/**
	 * Adds an invite token to group meta on group creation.
	 *
	 * @param int $group_id The group ID.
	 * @param BP_Groups_Member $member The group creator member object.
	 * @param BP_Groups_Group $group The group object.
	 */
	public function add_invite_token_to_group( $group_id, $member, $group ) {
		$token = wp_generate_uuid4(); // Generate a UUIDv4 token.
		// Store the token in group meta.
		groups_update_groupmeta( $group_id, 'invite_link_token', $token );
	}
}
