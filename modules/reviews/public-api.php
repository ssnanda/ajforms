<?php
/** Public v1 presentation API. Returned Google content must never be persistently cached. */
defined( 'ABSPATH' ) || exit;

function ajcore_get_featured_google_reviews( $limit = 6, $order = 'manual' ) { return AJCore_Reviews::featured( $limit, $order ); }
function ajcore_get_google_review_count() { return AJCore_Reviews::status()['valid_count']; }
function ajcore_get_google_location_summary() { $data = AJCore_Reviews::snapshot(); return array_intersect_key( $data['summary'] ?? array(), array_flip( array( 'total', 'rating', 'title', 'maps_url', 'write_url', 'location' ) ) ); }
function ajcore_get_reviews_status() { return AJCore_Reviews::status(); }
function ajcore_get_reviews_last_sync() { return AJCore_Reviews::status()['last_success']; }
function ajcore_get_featured_testimonials( $limit = 6, $order = 'manual' ) { return AJCore_Testimonials::collection( $limit, $order ); }
function ajcore_get_review_collections( $limit = 6, $order = 'manual' ) { return array( 'google' => ajcore_get_featured_google_reviews( $limit, $order ), 'manual' => ajcore_get_featured_testimonials( $limit, $order ) ); }
function ajcore_get_reviews_display_settings() {
	$data = (array) get_option( 'ajcore_reviews_display', array() );
	return array( 'fallback' => sanitize_text_field( $data['fallback'] ?? '' ), 'order' => ( $data['order'] ?? '' ) === 'date' ? 'date' : 'manual' );
}
