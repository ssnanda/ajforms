<?php
defined( 'ABSPATH' ) || exit;

final class AJCore_Testimonials {
	const TYPE = 'ajcore_testimonial';
	const META = '_ajcore_testimonial';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ) );
	}

	public static function register() {
		$capabilities = array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'manage_options' );
		register_post_type( self::TYPE, array(
			'labels' => array( 'name' => __( 'Manual Testimonials', 'ajcore' ), 'singular_name' => __( 'Manual Testimonial', 'ajcore' ), 'add_new_item' => __( 'Add Manual Testimonial', 'ajcore' ), 'edit_item' => __( 'Edit Manual Testimonial', 'ajcore' ) ),
			'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
			'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
			'rewrite' => false, 'query_var' => false, 'has_archive' => false,
			'capabilities' => $capabilities, 'map_meta_cap' => false,
			'supports' => array( 'title', 'editor', 'revisions', 'custom-fields' ),
		) );
		register_post_meta( self::TYPE, self::META, array( 'type' => 'object', 'single' => true, 'show_in_rest' => false, 'revisions_enabled' => true, 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'auth_callback' => function() { return current_user_can( 'manage_options' ); } ) );
	}

	public static function fields() {
		return array( 'initials' => __( 'Initials', 'ajcore' ), 'organization' => __( 'Organization or relationship', 'ajcore' ), 'rating' => __( 'Rating (optional, 1–5)', 'ajcore' ), 'date' => __( 'Date (YYYY-MM-DD)', 'ajcore' ), 'image_id' => __( 'Image attachment ID', 'ajcore' ), 'source_label' => __( 'Source label', 'ajcore' ), 'source_url' => __( 'Source URL', 'ajcore' ), 'order' => __( 'Display order', 'ajcore' ), 'notes' => __( 'Internal notes (never public)', 'ajcore' ) );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array(); $data = array();
		foreach ( self::fields() as $key => $label ) { $data[$key] = isset( $input[$key] ) && is_scalar( $input[$key] ) ? sanitize_text_field( (string) $input[$key] ) : ''; }
		$data['notes'] = isset( $input['notes'] ) && is_string( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : '';
		$data['source_url'] = esc_url_raw( $data['source_url'], array( 'http', 'https' ) );
		$data['rating'] = preg_match( '/^[1-5]$/D', $data['rating'] ) ? (int) $data['rating'] : null;
		$data['order'] = max( -10000, min( 10000, (int) $data['order'] ) );
		$data['image_id'] = absint( $data['image_id'] );
		if ( $data['image_id'] && ! wp_attachment_is_image( $data['image_id'] ) ) { $data['image_id'] = 0; }
		$date = DateTime::createFromFormat( '!Y-m-d', $data['date'] );
		$data['date'] = $date && $date->format( 'Y-m-d' ) === $data['date'] ? $data['date'] : '';
		$data['featured'] = ! empty( $input['featured'] );
		return $data;
	}

	public static function add_box() { add_meta_box( 'ajcore-testimonial-details', __( 'Testimonial details', 'ajcore' ), array( __CLASS__, 'box' ), self::TYPE ); }
	public static function box( $post ) {
		$data = (array) get_post_meta( $post->ID, self::META, true );
		wp_nonce_field( 'ajcore_testimonial_save', 'ajcore_testimonial_nonce' );
		echo '<p>' . esc_html__( 'Use the title for the display name and the editor for the testimonial text. Publish only content you have permission to use. These records are independent of synchronized Google reviews.', 'ajcore' ) . '</p>';
		foreach ( self::fields() as $key => $label ) {
			echo '<p><label for="ajct-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br>';
			if ( $key === 'notes' ) { echo '<textarea class="widefat" id="ajct-notes" name="ajcore_testimonial[notes]">' . esc_textarea( $data[$key] ?? '' ) . '</textarea>'; }
			else { echo '<input class="regular-text" id="ajct-' . esc_attr( $key ) . '" name="ajcore_testimonial[' . esc_attr( $key ) . ']" value="' . esc_attr( $data[$key] ?? '' ) . '">'; }
			echo '</p>';
		}
		echo '<p><label><input type="checkbox" name="ajcore_testimonial[featured]" value="1" ' . checked( ! empty( $data['featured'] ), true, false ) . '> ' . esc_html__( 'Featured', 'ajcore' ) . '</label></p>';
	}

	public static function save( $id ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) || ! isset( $_POST['ajcore_testimonial_nonce'] ) || ! is_string( $_POST['ajcore_testimonial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajcore_testimonial_nonce'] ) ), 'ajcore_testimonial_save' ) ) { return; }
		$data = isset( $_POST['ajcore_testimonial'] ) && is_array( $_POST['ajcore_testimonial'] ) ? wp_unslash( $_POST['ajcore_testimonial'] ) : array();
		update_post_meta( $id, self::META, self::sanitize( $data ) );
	}

	public static function collection( $limit = 6, $order = 'manual' ) {
		// Only published and explicitly featured records. No notes or arbitrary post meta leave this API.
		$posts = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => self::META, 'suppress_filters' => true ) );
		$items = array();
		foreach ( $posts as $post ) {
			$m = self::sanitize( get_post_meta( $post->ID, self::META, true ) );
			if ( ! $m['featured'] || $post->post_password !== '' || trim( wp_strip_all_tags( $post->post_title ) ) === '' || trim( wp_strip_all_tags( $post->post_content ) ) === '' ) { continue; }
			$items[] = array( 'kind' => 'manual', 'id' => $post->ID, 'name' => wp_strip_all_tags( $post->post_title ), 'text' => wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 'initials' => $m['initials'], 'organization' => $m['organization'], 'rating' => $m['rating'], 'date' => $m['date'], 'avatar' => $m['image_id'] ? ( wp_get_attachment_image_url( $m['image_id'], 'thumbnail' ) ?: '' ) : '', 'source_label' => $m['source_label'], 'source_url' => $m['source_url'], 'order' => $m['order'] );
		}
		usort( $items, function( $a, $b ) use ( $order ) { $cmp = $order === 'date' ? strcmp( $b['date'], $a['date'] ) : $a['order'] <=> $b['order']; return $cmp ?: $a['id'] <=> $b['id']; } );
		return array_slice( $items, 0, max( 1, min( 50, (int) $limit ) ) );
	}
}
