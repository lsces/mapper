<?php
/**
* @package mapper
* @author lsces <lester@lsces.co.uk>
* @version $Revision$
*/
namespace Bitweaver\Mapper;

use Bitweaver\Liberty\LibertyMime;

/**
* @package mapper
*/
class BitMapper extends LibertyMime
{
	var $mSettings;

	function __construct()
	{
		parent::__construct();
		$this->loadSettings();
	}

	function loadSettings() {
		global $gBitSystem;
		$this->mSettings = $this->getDefaultSettings();
		foreach( array_keys( $this->mSettings ) as $key ) {
			$keyPref = $gBitSystem->getConfig( $key, NULL );
			if( !empty( $keyPref ) ) {
				$this->mSettings[$key] = $keyPref;
			}
		}
	}

	/**
	 * Store the settings from the admin form, one kernel_config row each. A blank value, or one equal
	 * to its default, is not kept, so clearing a field puts it back to the default.
	 * Each row is written on its own with storeConfig(). It must NOT wipe the package's whole config
	 * (expungePackageConfig()), which would also delete the package_mapper active flag, the package
	 * version and the menu settings, all of which are kernel_config rows with package = 'mapper'.
	 */
	function storeSettings( &$pParamHash ) {
		global $gBitSystem;
		$defaults = $this->getDefaultSettings();
		$get = fn( $pKey ) => is_string( $pParamHash[$pKey] ?? null ) ? trim( $pParamHash[$pKey] ) : '';
		$posted = [
			'font'      => $get( 'font' ),
			'maps_dir'  => rtrim( $get( 'maps_dir' ), '/' ),
			'autotrack' => !empty( $pParamHash['autotrack'] ) ? 'on' : '',
		];
		foreach( $posted as $key => $value ) {
			$keep = ( $value !== '' && $value != $defaults[$key] ) ? $value : '';
			$gBitSystem->storeConfig( $key, $keep, MAPPER_PKG_NAME );
			$this->mSettings[$key] = ( $keep !== '' ) ? $keep : $defaults[$key];
		}
	}


	function getDefaultSettings() {
		return( array(	"font" => "LuxiSerif.afm",
						"autotrack" => 'off',
						// folder of per-map subfolders (<name>/<name>.map) that load_map.php scans.
						// Empty means the page is off until a site sets its own - a wider default
						// would expose whatever the host happens to have mounted.
						"maps_dir" => ''
			) );
	}
}
?>
