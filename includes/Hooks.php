<?php

namespace MediaWiki\Extension\WikiDexApp;

use \Title;
use \Parser;
use \PPFrame;

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
		$urls[] = wfExpandUrl( UrlMapper::getAppPageUrlWikiDexPage1( $title ), PROTO_INTERNAL );
		// old
		$urls[] = wfExpandUrl( UrlMapper::getAppPageUrl1( $title ), PROTO_INTERNAL );
		// old
		if ( strpos( $title->getPrefixedText(), 'MediaWiki:App/' ) === 0 ) {
			$urls[] = wfExpandUrl( UrlMapper::getAppStylesUrlBundle1(), PROTO_INTERNAL );
			$urls[] = wfExpandUrl( UrlMapper::getAppStylesUrlBundle2(), PROTO_INTERNAL );
			$urls[] = wfExpandUrl( UrlMapper::getAppStylesUrl1( $title ), PROTO_INTERNAL );
		}
		// This can be any gadget, some of them are included in the bundle, or may be loaded
		if ( strpos( $title->getPrefixedText(), 'MediaWiki:Gadget-' ) === 0 ) {
			$urls[] = wfExpandUrl( UrlMapper::getAppStylesUrlBundle2(), PROTO_INTERNAL );
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

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserOptionsRegister
	 *
	 * @param array &$defaults: Options and their defaults
	 * @param array &$inCacheKey: Whether each option splits the parser cache
	 * @param array &$lazyOptions: Initializers for lazy-loaded options
	 */
	public static function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyOptions ) {
		$defaults['wikidexapp'] = '';
		$inCacheKey['wikidexapp'] = true;

		return true;
	}

	public static function onTabberTranscludeRenderLazyLoadedTab( &$tabBody, &$dataProps, Parser $parser, PPFrame $frame ) {
		if ( $parser->getOptions()->getOption( 'wikidexapp' ) == '1' ) {
			// Make lazy load tabs full load
			$pageName = $dataProps['page-title'];
			$tabBody = $parser->recursiveTagParseFully(
				sprintf( '{{:%s}}', $pageName ),
				$frame
			);
			unset( $dataProps['pending-load'] );
			unset( $dataProps['load-url'] );
		}
	}
}
