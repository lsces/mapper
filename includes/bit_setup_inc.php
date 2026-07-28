<?php
/**
 * @package mapper
 */
namespace Bitweaver\Mapper;

global $gBitSystem;

$pRegisterHash = [
	'package_name' => 'mapper',
	'package_path' => dirname( dirname( __FILE__ ) ).'/',
	'homeable' => true,
];

// fix to quieten down VS Code which can't see the dynamic creation of these ...
define( 'MAPPER_PKG_NAME', $pRegisterHash['package_name'] );
define( 'MAPPER_PKG_URL', BIT_ROOT_URL . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'MAPPER_PKG_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'MAPPER_PKG_INCLUDE_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/');
define( 'MAPPER_PKG_CLASS_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/classes/');
define( 'MAPPER_PKG_ADMIN_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/admin/');

$gBitSystem->registerPackage( $pRegisterHash );

if( $gBitSystem->isPackageActive( 'mapper' ) ) {

	$menuHash = [
		'package_name'  => MAPPER_PKG_NAME,
		'index_url'     => MAPPER_PKG_URL.'index.php',
		'menu_template' => 'bitpackage:mapper/menu_mapper.tpl',
	];
	$gBitSystem->registerAppMenu( $menuHash );
}
