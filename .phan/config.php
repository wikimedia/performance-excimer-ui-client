<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['target_php_version'] = '7.4';


$cfg['autoload_internal_extension_signatures'] = [
	'excimer' => '.phan/internal_stubs/excimer.php',
];

$cfg['exclude_analysis_directory_list'] = [
	'.phan',
];

$cfg['directory_list'] = [
	'src',
	// No need to include vendor, it's a standalone class
];

return $cfg;
