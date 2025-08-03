<?php

/**
 * Invite Anyone By Link functionality.
 *
 * This module provides functionality for creating shareable invitation links
 * that allow users to join the site or specific groups without requiring
 * email addresses. Links can be configured with expiration dates and
 * usage limits.
 *
 * @package Invite Anyone
 * @since 1.5.0
 */

// Include the main class.
require_once BP_INVITE_ANYONE_DIR . 'invite-link/class-invite-anyone-link.php';
require_once BP_INVITE_ANYONE_DIR . 'invite-link/class-invite-anyone-link-rest-controller.php';

// Initialize the invite link functionality.
new Invite_Anyone_Link();
// Initialize REST controller for group invite links.
new Invite_Anyone_Link_REST_Controller();
