<?php
/**
 * Group Invitation Template
 *
 * Template for displaying group invitation page.
 *
 * @package Invite Anyone
 * @since 1.5.0
 */

global $bb_invite_group_id;
$group = groups_get_group( $bb_invite_group_id );

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
				<a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=accept_group_invite&group_id=' . $bb_invite_group_id ), 'accept_group_invite_' . $bb_invite_group_id ); ?>"
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
<?php get_footer(); ?>
