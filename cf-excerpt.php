<?php
/*
 * Plugin Name: CF Nice Excerpts
 * Description: Helpful excerpt functions, like "&hellip;" truncation, custom lengths, etc.
 * Version: 1.0
 * Author: Crowd Favorite
 * Author URI: http://crowdfavorite.com
 */

if (__FILE__ == $_SERVER['SCRIPT_FILENAME']) { die(); }

/**
 * Get rid of ugly [...]
 * Replace with typographically correct &hellip;
 */
function cfex_excerpt_more() {
	return '&hellip;';
}
add_filter('excerpt_more', 'cfex_excerpt_more');

/**
 * The excerpt, filtered for custom variable length
 * @uses cfex_seriously_trim_the_excerpt
 * @param $num defines the number of words the excerpt is truncated to, to the nearest space.
 */
function cfex_get_excerpt($length = 55, $more = '&hellip;') {
	$length_func = create_function('$length', "return $length;");
	$trunc_func = create_function('$more', "return '$more';");
	
	ob_start();
	
	// Custom excerpt length through our on-the-fly function.
	add_filter('excerpt_length', $length_func);
	// Custom truncation character
	add_filter('excerpt_more', $trunc_func);
	// Serious truncation we can count on.
	add_filter('wp_trim_excerpt', 'cfex_seriously_trim_the_excerpt', 10, 2);
		the_excerpt();
	// Remove all filters for safety's sake.
	remove_filter('wp_trim_excerpt', 'cfex_seriously_trim_the_excerpt');
	remove_filter('excerpt_more', $trunc_func);
	remove_filter('excerpt_length', $length_func);
	
	$excerpt = ob_get_clean();
	
	return $excerpt;
}

function cfex_the_excerpt($length = 55, $more = '&hellip;') {
	echo cfex_get_excerpt($length, $more);
}

/**
 * Re-trim the excerpt, even if it's custom.
 * 
 * Essentially the same operations as trim_the_excerpt, but truncation happens regardless.
 * Runs on trim_the_excerpt filter
 * @see trim_the_excerpt
 * @uses cfex_trim_text
 * @param $text string - finished excerpt from trim_the_excerpt
 * @param $raw_excerpt - custom excerpt field, pre-munged. May be empty.
 * @return string
 */
function cfex_seriously_trim_the_excerpt($text, $raw_excerpt) {
	$text = $raw_excerpt;
	if ( '' == $text ) {
		$text = get_the_content('');

		$text = strip_shortcodes( $text );

		$text = apply_filters('the_content', $text);
		$text = str_replace(']]>', ']]&gt;', $text);
		$text = strip_tags($text);
	}

	$excerpt_length = apply_filters('excerpt_length', 55);
	$excerpt_more = apply_filters('excerpt_more', ' ' . '[...]');

	return cfex_trim_text($text, $excerpt_length, $excerpt_more);
}

/**
 * Truncate and strip all tags from a string
 * @param $text string - text to truncate
 * @param $length int - number of words to truncate to
 * @param $more_delimieter string - character to place at the end of truncated text.
 * @return string
 */
function cfex_trim_text($text, $length, $more_delimiter) {
	$text =  strip_tags($text);
	
	$words = explode(' ', $text, $length + 1);
	if (count($words) > $length) {
		array_pop($words);
		$text = implode(' ', $words);
		$text = $text . $more_delimiter;
	}
	
	return $text;
}
?>