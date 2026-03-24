<?php
/**
 * PSR-4–style autoloader: namespace path mirrors includes/ (WPHUBPRO\ = includes root).
 * Sub-namespace classes use short names; explicit map ties FQCN to filenames under includes/.
 * Root-level `WPHUBPRO\\{Name}` classes (Bridge, Config, Connect, …) are explicitly mapped to `{Name}.php`.
 * Unmapped sub-namespace classes resolve to `{subdir}/{PascalCaseShort}.php` (hyphens/underscores folded into PascalCase).
 *
 * @package WPHubPro
 */

namespace WPHUBPRO;

class Autoloader {

	/**
	 * FQCN => path under includes/.
	 *
	 * @var array<string, string>
	 */
	private static $mapped_classes = array(
		'WPHUBPRO\\Bridge'                          => 'Bridge.php',
		'WPHUBPRO\\Config'                          => 'Config.php',
		'WPHUBPRO\\Connect'                         => 'Connect.php',
		'WPHUBPRO\\ConnectionStatus'                => 'ConnectionStatus.php',
		'WPHUBPRO\\Crypto'                          => 'Crypto.php',
		'WPHUBPRO\\Details'                         => 'Details.php',
		'WPHUBPRO\\Logger'                          => 'Logger.php',
		'WPHUBPRO\\Recovery'                        => 'Recovery.php',
		'WPHUBPRO\\Admin\\Admin'                    => 'Admin/Admin.php',
		'WPHUBPRO\\Api\\API'                        => 'Api/Api.php',
		'WPHUBPRO\\Api\\ApiLogger'                  => 'Api/ApiLogger.php',
		'WPHUBPRO\\Api\\Heartbeat'                 => 'Api/Heartbeat.php',
		'WPHUBPRO\\Api\\Health'                     => 'Api/Health.php',
		'WPHUBPRO\\Api\\Sync'                       => 'Api/Sync.php',
		'WPHUBPRO\\Api\\Updater'                    => 'Api/Updater.php',
		'WPHUBPRO\\Auth\\Auth'                      => 'Auth/Auth.php',
		'WPHUBPRO\\Plugin\\Bridge_Guard'            => 'Plugin/BridgeGuard.php',
		'WPHUBPRO\\Plugin\\Plugins'                 => 'Plugin/Plugins.php',
		'WPHUBPRO\\Plugin\\Params'                  => 'Plugin/Params.php',
		'WPHUBPRO\\Plugin\\Upgrader_Helper'         => 'Plugin/UpgraderHelper.php',
		'WPHUBPRO\\Theme\\Themes'                   => 'Theme/Themes.php',
		'WPHUBPRO\\Theme\\Params'                   => 'Theme/Params.php',
		'WPHUBPRO\\Theme\\Upgrader_Helper'          => 'Theme/UpgraderHelper.php',
		'WPHUBPRO\\Cron\\Scheduler'                 => 'Cron/Scheduler.php',
		'WPHUBPRO\\Cron\\JobInterface'              => 'Cron/JobInterface.php',
		'WPHUBPRO\\Cron\\Job\\Heartbeat'            => 'Cron/Job/Heartbeat.php',
		'WPHUBPRO\\Cron\\Job\\Health'               => 'Cron/Job/Health.php',
	);

	/**
	 * Register spl_autoload_register handler.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * PascalCase stem from a class short name (e.g. API → Api, connection_status → ConnectionStatus).
	 *
	 * @param string $short Class name segment (may contain underscores).
	 * @return string Basename without `.php`.
	 */
	private static function pascal_basename_from_short_class( $short ) {
		$s     = str_replace( '_', '-', strtolower( $short ) );
		$parts = explode( '-', $s );
		$out   = '';
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$out .= ucfirst( $p );
		}
		return $out;
	}

	/**
	 * @param string $short Class name segment (may contain underscores).
	 * @return string Filename including `.php`.
	 */
	private static function pascal_filename_from_short_class( $short ) {
		return self::pascal_basename_from_short_class( $short ) . '.php';
	}

	/**
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload( $class ) {
		if ( strncmp( $class, 'WPHUBPRO\\', 9 ) !== 0 ) {
			return;
		}

		$relative = substr( $class, 9 );
		if ( $relative === 'Autoloader' ) {
			return;
		}

		$base = dirname( __DIR__ ) . '/includes/';

		if ( isset( self::$mapped_classes[ $class ] ) ) {
			$path = $base . self::$mapped_classes[ $class ];
			if ( file_exists( $path ) ) {
				require $path;
			}
			return;
		}

		$parts = explode( '\\', $relative );
		$short = array_pop( $parts );

		// includes/Error/BaseError.php — class BaseError, namespace WPHUBPRO\Error
		if ( count( $parts ) === 1 && $parts[0] === 'Error' ) {
			$file = $base . 'Error/' . $short . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}

		$subdir = ! empty( $parts ) ? implode( '/', $parts ) . '/' : '';

		if ( '' === $subdir ) {
			$filename = 'Class' . self::pascal_basename_from_short_class( $short ) . '.php';
		} else {
			$filename = self::pascal_filename_from_short_class( $short );
		}

		$path = $base . $subdir . $filename;

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
}
