<?php

namespace MediaWiki\Extension\WikiDexApp;

use \Title;

/**
 * Class that implements the hooks called from MediaWiki
 *
 * @license MIT
 */
class Hooks {

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleSquidURLs
	 *
	 * @param Title $title
	 * @param string[] $urls
	 */
	public static function onTitleSquidURLs( Title $title, &$urls ) {
		if ( ! $title ) {
			return true;
		}
		$urls[] = wfExpandUrl( UrlMapper::getAppPageUrl1( $title ), PROTO_INTERNAL );
		if ( strpos( $title->getPrefixedText(), 'MediaWiki:App/' ) === 0 ) {
			$urls[] = wfExpandUrl( UrlMapper::getAppStylesUrlBundle1(), PROTO_INTERNAL );
			$urls[] = wfExpandUrl( UrlMapper::getAppStylesUrl1( $title ), PROTO_INTERNAL );
		}
		return true;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/APIAfterExecute
	 *
	 * @param ApiBase $module The module that will handle this action
	 */
	public static function onAPIAfterExecute( &$module ) {
		$ac = new ApiCacheConfigurator( $module );
		$ac->setup();

		return true;
	}
}
