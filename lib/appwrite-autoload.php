<?php
/**
 * Simple PSR-4 autoloader for Appwrite SDK.
 *
 * Maps Appwrite\* to lib/Appwrite/*
 *
 * @package WPHubPro
 */

spl_autoload_register( function ( $class ) {
	$prefix = 'Appwrite\\';
	$base   = __DIR__ . '/Appwrite/';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = $base . str_replace( '\\', '/', $relative ) . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
} );
