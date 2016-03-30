<?php
# Alert the user that this is not a valid access point to MediaWiki if they try to access the special pages file directly.
if ( !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}
 
$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'WfSearch',
	'author' => 'Pierre Boutet',
	//'url' => 'https://www.mediawiki.org/wiki/Extension:WfSearch',
	'descriptionmsg' => 'wfsearch-desc',
	'version' => '0.1.0',
);
$wgAutoloadClasses['SpecialWfSearch'] = __DIR__ . '/includes/SpecialWfSearch.php'; # Location of the SpecialWfSearch class (Tell MediaWiki to load this file)
$wgAutoloadClasses['WikifabSearchResultFormatter'] = __DIR__ . '/includes/WikifabSearchResultFormatter.php'; # Location of the WikifabSearchResultFormatter class 
$wgAutoloadClasses['WfTutorialUtils'] = __DIR__ . '/includes/WfTutorialUtils.php'; # tools for using tutorial forms pages
$wgMessagesDirs['WfSearch'] = array( __DIR__ . "/i18n"); # Location of localisation files (Tell MediaWiki to load them)
$wgExtensionMessagesFiles['WfSearchAlias'] = __DIR__ . '/WfSearch.alias.php'; # Location of an aliases file 
$wgSpecialPages['WfSearch'] = 'SpecialWfSearch'; # Tell MediaWiki about the new special page and its class name