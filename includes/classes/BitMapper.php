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

	function verifySettings( &$pParamHash ) {
		$defaults = $this->getDefaultSettings();
		
		// let's trim out all whitespace
		foreach( array_keys( $pParamHash ) as $key ) {
			$pParamHash[$key] = trim( $pParamHash[$key] );
		}

		if( isset( $pParamHash['font'] ) && ( $pParamHash['font'] != $defaults['font'] ) ) {
			$pParamHash['setting_store']['font'] = $pParamHash['font'];
		}

		if( isset( $pParamHash['autotrack'] ) ) {
			$pParamHash['setting_store']['autotrack'] = $pParamHash['autotrack'];
		}

		return( !empty( $pParamHash['setting_store'] ) );
	}

	function storeSettings( &$pParamHash ) {
		global $gBitSystem;
		$gBitSystem->expungePackagePreferences( MAPPER_PKG_NAME );
		if( $this->verifySettings( $pParamHash ) ) {
			foreach( array_keys( $pParamHash['setting_store'] ) as $key ) {
				$gBitSystem->storeConfig( $key, $pParamHash['setting_store'][$key],  MAPPER_PKG_NAME );
				$this->mSettings[$key] = $pParamHash['setting_store'][$key];
			}
		}
	}


	function getDefaultSettings() {
		return( array(	"font" => "LuxiSerif.afm",
						"autotrack" => 'off'
			) );
	}
}
?>
