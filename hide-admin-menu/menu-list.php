<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- Assets ---------- */

function bhm_enqueue_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'hide-admin-menu' ) ) {
		return;
	}
	wp_enqueue_style( 'bhm-admin', plugin_dir_url( __FILE__ ) . 'css/style-admin.css', array(), BHM_VERSION );
}
add_action( 'admin_enqueue_scripts', 'bhm_enqueue_assets' );

/* ---------- Form handling (runs before any output, so redirects work) ---------- */

function bhm_handle_form() {
	if ( ! isset( $_POST['bhm_action'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || 'hide-admin-menu' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'bhm_save_menus' );

	$action      = sanitize_key( wp_unslash( $_POST['bhm_action'] ) );
	$valid_roles = array_keys( wp_roles()->roles );

	if ( 'default' === $action ) {
		update_option( 'hide_menu_bh_plugin', '' );
		update_option( 'hide_sub_menu_bh_plugin', '' );
		update_option( 'hide_top_menu_bh_plugin', '' );
		$message = 'default';
	} elseif ( 'save' === $action ) {
		$side = isset( $_POST['menu_list'] ) ? bhm_clean_list( wp_unslash( $_POST['menu_list'] ), $valid_roles ) : array();
		$sub  = isset( $_POST['sub_menu_list'] ) ? bhm_clean_list( wp_unslash( $_POST['sub_menu_list'] ), $valid_roles ) : array();
		$top  = isset( $_POST['top_menu_list'] ) ? bhm_clean_list( wp_unslash( $_POST['top_menu_list'] ), $valid_roles ) : array();

		update_option( 'hide_menu_bh_plugin', wp_json_encode( $side ) );
		update_option( 'hide_sub_menu_bh_plugin', wp_json_encode( $sub ) );
		update_option( 'hide_top_menu_bh_plugin', wp_json_encode( $top ) );
		$message = 'save';
	} else {
		return;
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'hide-admin-menu', 'message' => $message ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'bhm_handle_form' );

/**
 * Clean posted checkbox data: only known roles, only non-empty string values.
 */
function bhm_clean_list( $raw, $valid_roles ) {
	$clean = array();
	if ( ! is_array( $raw ) ) {
		return $clean;
	}
	foreach ( $raw as $role => $items ) {
		$role = sanitize_key( $role );
		if ( ! in_array( $role, $valid_roles, true ) || ! is_array( $items ) ) {
			continue;
		}
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = sanitize_text_field( $item );
			if ( '' !== $item ) {
				$clean[ $role ][] = $item;
			}
		}
	}
	return $clean;
}

/* ---------- Render helpers ---------- */

function bhm_is_checked( $data, $role, $value ) {
	if ( empty( $data[ $role ] ) || ! is_array( $data[ $role ] ) ) {
		return false;
	}
	return in_array( (string) $value, array_map( 'strval', $data[ $role ] ), true );
}

function bhm_checkbox_cell( $field, $role_key, $value, $checked, $label ) {
	echo '<td class="bhm-check"><input type="checkbox" name="' . esc_attr( $field . '[' . $role_key . '][]' ) . '" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . ' aria-label="' . esc_attr( $label ) . '" /></td>';
}

/**
 * Sidebar parent items that are real menus (skip separators and empty rows).
 */
function bhm_side_items( $menu ) {
	$items = array();
	if ( ! is_array( $menu ) ) {
		return $items;
	}
	foreach ( $menu as $row ) {
		if ( empty( $row[0] ) || empty( $row[2] ) ) {
			continue;
		}
		if ( isset( $row[4] ) && false !== strpos( (string) $row[4], 'wp-menu-separator' ) ) {
			continue;
		}
		$items[] = $row;
	}
	return $items;
}

/**
 * Group top bar nodes into parents and children.
 */
function bhm_build_top_tree( $nodes ) {
	$tree = array();
	if ( ! is_array( $nodes ) ) {
		return $tree;
	}
	foreach ( $nodes as $id => $node ) {
		if ( 'menu-toggle' === $id ) {
			continue;
		}
		$parent = (string) $node->parent;
		if ( '' === $parent || 'top-secondary' === $parent ) {
			$tree[ $id ] = array( 'node' => $node, 'children' => array() );
		}
	}
	foreach ( $nodes as $id => $node ) {
		$parent = (string) $node->parent;
		if ( '' === $parent || 'top-secondary' === $parent || 'menu-toggle' === $id ) {
			continue;
		}
		if ( 'wp-logo-external' === $parent ) {
			$parent = 'wp-logo';
		} elseif ( 'user-actions' === $parent ) {
			$parent = 'my-account';
		}
		if ( isset( $tree[ $parent ] ) ) {
			$tree[ $parent ]['children'][] = $node;
		}
	}
	return $tree;
}

/* ---------- Settings page ---------- */

function bhm_get_menu_list() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'hide-admin-menu' ) );
	}

	global $bhm_session;

	$roles       = wp_roles()->roles;
	$hidden_side = bhm_read_option( 'hide_menu_bh_plugin' );
	$hidden_sub  = bhm_read_option( 'hide_sub_menu_bh_plugin' );
	$hidden_top  = bhm_read_option( 'hide_top_menu_bh_plugin' );

	$side_menu = isset( $bhm_session['side'] ) ? $bhm_session['side'] : array();
	$side_sub  = isset( $bhm_session['side_sub'] ) ? $bhm_session['side_sub'] : array();
	$top_tree  = bhm_build_top_tree( isset( $bhm_session['top'] ) ? $bhm_session['top'] : array() );

	$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
	$notice  = '';
	if ( 'save' === $message ) {
		$notice = __( 'Your changes have been saved.', 'hide-admin-menu' );
	} elseif ( 'default' === $message ) {
		$notice = __( 'Default settings restored. All menu items are visible again.', 'hide-admin-menu' );
	}

	$col_count = 1 + count( $roles );
	$side_rows = bhm_side_items( $side_menu );
	?>
	<div class="wrap bhm-wrap">

		<header class="bhm-header">
			<h1 class="bhm-title"><?php esc_html_e( 'Hide Admin Menu', 'hide-admin-menu' ); ?></h1>
			<p class="bhm-subtitle"><?php esc_html_e( 'Choose which sidebar and top bar items each user role should not see.', 'hide-admin-menu' ); ?></p>
		</header>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="" class="bhm-form">
			<?php wp_nonce_field( 'bhm_save_menus' ); ?>

			<div class="bhm-layout">
				<div class="bhm-main">

					<section class="bhm-card">
						<div class="bhm-card-head">
							<h2><?php esc_html_e( 'Sidebar menu', 'hide-admin-menu' ); ?></h2>
							<span class="bhm-count"><?php echo esc_html( count( $side_rows ) ); ?> <?php esc_html_e( 'items', 'hide-admin-menu' ); ?></span>
						</div>
						<div class="bhm-table-wrap">
							<table class="bhm-table">
								<thead>
									<tr>
										<th scope="col" class="bhm-name-col"><?php esc_html_e( 'Menu item', 'hide-admin-menu' ); ?></th>
										<?php foreach ( $roles as $role ) : ?>
											<th scope="col" class="bhm-role-col"><?php echo esc_html( $role['name'] ); ?></th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
								<?php if ( empty( $side_rows ) ) : ?>
									<tr><td colspan="<?php echo esc_attr( $col_count ); ?>" class="bhm-empty"><?php esc_html_e( 'No sidebar menus found. Open this page again after the other plugins have loaded.', 'hide-admin-menu' ); ?></td></tr>
								<?php endif; ?>

								<?php foreach ( $side_rows as $row ) :
									$slug     = $row[2];
									$label    = wp_strip_all_tags( $row[0] );
									$icon     = ( isset( $row[6] ) && is_string( $row[6] ) && 0 === strpos( $row[6], 'dashicons-' ) ) ? $row[6] : 'dashicons-admin-generic';
									$children = isset( $side_sub[ $slug ] ) ? (array) $side_sub[ $slug ] : array();
									?>
									<tr class="bhm-parent">
										<td class="bhm-name">
											<span class="dashicons <?php echo esc_attr( $icon ); ?> bhm-icon" aria-hidden="true"></span>
											<span class="bhm-label"><?php echo esc_html( $label ); ?></span>
										</td>
										<?php foreach ( $roles as $role_key => $role ) :
											bhm_checkbox_cell( 'menu_list', $role_key, $slug, bhm_is_checked( $hidden_side, $role_key, $slug ), $label . ' - ' . $role['name'] );
										endforeach; ?>
									</tr>

									<?php foreach ( $children as $child ) :
										if ( empty( $child[0] ) || empty( $child[2] ) ) {
											continue;
										}
										$child_value = $slug . '__con__' . $child[2];
										$child_label = wp_strip_all_tags( $child[0] );
										?>
										<tr class="bhm-sub">
											<td class="bhm-name"><span class="bhm-label"><?php echo esc_html( $child_label ); ?></span></td>
											<?php foreach ( $roles as $role_key => $role ) :
												bhm_checkbox_cell( 'sub_menu_list', $role_key, $child_value, bhm_is_checked( $hidden_sub, $role_key, $child_value ), $child_label . ' - ' . $role['name'] );
											endforeach; ?>
										</tr>
									<?php endforeach; ?>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>

					<section class="bhm-card">
						<div class="bhm-card-head">
							<h2><?php esc_html_e( 'Top bar menu', 'hide-admin-menu' ); ?></h2>
						</div>
						<div class="bhm-table-wrap">
							<table class="bhm-table">
								<thead>
									<tr>
										<th scope="col" class="bhm-name-col"><?php esc_html_e( 'Menu item', 'hide-admin-menu' ); ?></th>
										<?php foreach ( $roles as $role ) : ?>
											<th scope="col" class="bhm-role-col"><?php echo esc_html( $role['name'] ); ?></th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
								<?php
								$has_top = false;
								foreach ( $top_tree as $id => $item ) :
									$title = wp_strip_all_tags( (string) $item['node']->title );
									if ( '' === trim( $title ) ) {
										continue;
									}
									$has_top = true;
									?>
									<tr class="bhm-parent">
										<td class="bhm-name"><span class="bhm-label"><?php echo esc_html( $title ); ?></span></td>
										<?php foreach ( $roles as $role_key => $role ) :
											bhm_checkbox_cell( 'top_menu_list', $role_key, $id, bhm_is_checked( $hidden_top, $role_key, $id ), $title . ' - ' . $role['name'] );
										endforeach; ?>
									</tr>
									<?php foreach ( $item['children'] as $child ) :
										$child_title = wp_strip_all_tags( (string) $child->title );
										if ( '' === trim( $child_title ) ) {
											continue;
										}
										?>
										<tr class="bhm-sub">
											<td class="bhm-name"><span class="bhm-label"><?php echo esc_html( $child_title ); ?></span></td>
											<?php foreach ( $roles as $role_key => $role ) :
												bhm_checkbox_cell( 'top_menu_list', $role_key, $child->id, bhm_is_checked( $hidden_top, $role_key, $child->id ), $child_title . ' - ' . $role['name'] );
											endforeach; ?>
										</tr>
									<?php endforeach; ?>
								<?php endforeach; ?>
								<?php if ( ! $has_top ) : ?>
									<tr><td colspan="<?php echo esc_attr( $col_count ); ?>" class="bhm-empty"><?php esc_html_e( 'No top bar menus found.', 'hide-admin-menu' ); ?></td></tr>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
					</section>

				</div>

				<aside class="bhm-aside">
					<div class="bhm-card">
						<h2><?php esc_html_e( 'How it works', 'hide-admin-menu' ); ?></h2>
						<ul class="bhm-list">
							<li><?php esc_html_e( 'Tick an item to hide it for that role.', 'hide-admin-menu' ); ?></li>
							<li><?php esc_html_e( 'Untick it and save to show it again.', 'hide-admin-menu' ); ?></li>
							<li><?php esc_html_e( 'This hides menu links only. Use role capabilities to block direct page access.', 'hide-admin-menu' ); ?></li>
						</ul>
					</div>
					<div class="bhm-card bhm-support">
						<h2><?php esc_html_e( 'Support the development', 'hide-admin-menu' ); ?></h2>
						<p><?php esc_html_e( 'If this plugin helps you, consider a small donation. Thank you!', 'hide-admin-menu' ); ?></p>
						<a class="button" href="https://www.paypal.me/BThummar/10" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Donate via PayPal', 'hide-admin-menu' ); ?></a>
					</div>
				</aside>
			</div>

			<div class="bhm-savebar">
				<button type="submit" name="bhm_action" value="save" class="button button-primary button-large"><?php esc_html_e( 'Save changes', 'hide-admin-menu' ); ?></button>
				<button type="submit" name="bhm_action" value="default" class="button button-large" onclick="return confirm('<?php echo esc_js( __( 'Reset all hidden menus for every role?', 'hide-admin-menu' ) ); ?>');"><?php esc_html_e( 'Reset to default', 'hide-admin-menu' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}
