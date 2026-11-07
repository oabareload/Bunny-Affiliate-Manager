<?php
// Mock WordPress upgrade.php for standalone unit testing
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '' ) {
		return array();
	}
}
