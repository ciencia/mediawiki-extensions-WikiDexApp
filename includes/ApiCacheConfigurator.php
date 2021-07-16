<?php

namespace MediaWiki\Extension\WikiDexApp;

use \Title;
use \MediaWiki\MediaWikiServices;

/**
 * Configures API response
 *
 * @license MIT
 */
class ApiCacheConfigurator {

	/** @var ApiBase */
	private $mModule = null;

	/**
	 * @param ApiBase $module The module that will handle this action
	 */
	public function __construct( $module ) {
		$this->mModule = $module;
	}

	public function setup() {
		global $wgCdnMaxAge;
		if ( $this->mModule == null ) {
			return;
		}
		header( 'X-ACD: 1' );
		$apiMain = $this->mModule->getMain();
		$request = $apiMain->getRequest();
		# FauxRequest siempre retorna error al llamar a getRequestURL()
		if ( is_a( $request, 'FauxRequest' ) ) {
			header( 'X-ACD: 2' );
			return;
		}
		if ( $request->getMethod() != 'GET' && $request->getMethod() != 'HEAD' ) {
			header( 'X-ACD: 3' );
			return;
		}
		if ( $this->isHelpModule() ) {
			header( 'X-ACD: 4' );
			$this->setHelpCache();
			return;
		}
		if ( $this->isOpenSearchModule() ) {
			header( 'X-ACD: 5' );
			$this->setOpeSearchCache();
			return;
		}
		$cacheURL = '';
		$action = $request->getVal( 'action' );
		header( 'X-ACD: 8' );
		if ( $action == 'parse' ) {
			header( 'X-ACD: 6' );
			$this->setParseActionCache( $request );
		} else if ( $action = 'query' ) {
			header( 'X-ACD: 7' );
			$this->setQueryActionCache( $request );
		}
	}

	private function isHelpModule() {
		return is_a( $this->mModule, 'ApiHelp' );
	}

	private function isOpenSearchModule() {
		return is_a( $this->mModule, 'ApiOpenSearch' );
	}

	private function setHelpCache() {
		$apiMain = $this->mModule->getMain();
		$apiMain->setCacheMode( 'public' );
		$apiMain->setCacheControl( [
			'public' => true,
			'max-age' => 30,
			's-maxage' => 3600
		] );
	}

	private function setOpeSearchCache() {
		$apiMain = $this->mModule->getMain();
		$apiMain->setCacheMode( 'public' );
		$apiMain->setCacheControl( [
			'public' => true,
			'max-age' => 30,
			's-maxage' => 10800
		] );
	}

	private function isSameRequestUrl( $request, $url ) {
		// getRequestURL always strips until host (and port)
		// Adapt $url making it root-relative
		if ( $url[0] == '/' ) {
			// Path relative?
			$url = preg_replace( '!^/+!', '/', $url );
		} else {
			// full URL
			$url = preg_replace( '!^[^:]+://[^/]+/+!', '/', $url );
		}
		header( sprintf( 'X-Compare1: %s', $url ) );
		header( sprintf( 'X-Compare2: %s', $request->getRequestURL() ) );
		return $url == $request->getRequestURL();
	}

	/**
	 * @param WebRequest $request
	 */
	private function setQueryActionCache( $request ) {
		// /api.php?action=query&list=search&srprop=&srlimit=3&format=json&srsearch=morelike%3ADeseo
		if ( $request->getVal( 'list' ) == 'search' &&
			$request->getVal( 'srlimit' ) == '3' )
		{
			$this->setLooseCaching();
			return;
		}
		$page = $request->getVal( 'titles' );
		if ( ! $page ) {
			return;
		}
		// Multiple titles
		if ( strpos( $page, '|' ) !== false ) {
			// /api.php?action=query&prop=pageimages&pithumbsize=400&format=json&titles=D%C3%ADa%20soleado%7CDescanso%7CProtecci%C3%B3n
			if ( $request->getVal( 'prop' ) == 'pageimages' ) {
				if ( $request->getVal( 'list' ) === null &&
					$request->getVal( 'generator' ) === null )
				{
					$this->setLooseCaching();
				}
				return;
			}
			$cacheURL = UrlMapper::getAppStylesUrlBundle1();
			if ( $this->isSameRequestUrl( $request, $cacheURL ) ) {
				$this->setStandardCaching();
			}
			return;
		}
		$title = Title::newFromText( $page );
		if ( ! $title ) {
			return;
		}
		$cacheURL = UrlMapper::getProtectionUrl1( $title );
		if ( $this->isSameRequestUrl( $request, $cacheURL ) ) {
			$this->setLooseCaching();
			return;
		}
		$cacheURL = UrlMapper::getAppStylesUrl1( $title );
		if ( $this->isSameRequestUrl( $request, $cacheURL ) ) {
			$this->setStandardCaching();
			return;
		}
	}

	/**
	 * @param WebRequest $request
	 */
	private function setParseActionCache( $request ) {
		$page = $request->getVal( 'page' );
		if ( ! $page ) {
			return;
		}
		$title = Title::newFromText( $page );
		if ( ! $title ) {
			return;
		}
		$cacheURL = UrlMapper::getAppPageUrl1( $title );
		header( 'X-ACD-Action: 3' );
		if ( $this->isSameRequestUrl( $request, $cacheURL ) ) {
			header( 'X-ACD-Action: 4' );
			$apiMain = $this->mModule->getMain();
			$result = $apiMain->getResult();
			if ( is_a( $result, 'ApiResult' ) ) {
				header( 'X-ACD-Action: 5' );
				$transforms = [ 'Strip' => 'all' ]; // Strip all metadata
				$redirects = $result->getResultData( [ 'parse', 'redirects' ], $transforms );
				if ( !empty( $redirects ) ) {
					header( 'X-ACD-Action: 6' );
					header( sprintf( 'X-Result: %s', preg_replace( '/\n/', '', print_r( $redirects, true ) ) ) );
					// It resolved a redirect. We can't purge redirects
					$this->setMicroCaching();
				} else {
					header( 'X-ACD-Action: 7' );
					$this->setStandardCaching();
				}
			} else {
				header( sprintf( 'X-Result: %s', preg_replace( '/\n/', '', get_class( $result ) ) ) );
			}
		}
	}

	private function setStandardCaching() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->setCaching( $config->get( 'CdnMaxAge' ) );
	}

	private function setLooseCaching() {
		$this->setCaching( 86400 ); // 1 day
	}

	private function setMicroCaching() {
		$this->setCaching( 3600 ); // 1 hour
	}

	private function setCaching( $sMaxAge ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$apiMain = $this->mModule->getMain();
		$apiMain->setCacheMode( 'public' );
		$apiMain->setCacheControl( [
			'public' => true,
			'must-revalidate' => true,
			'max-age' => 60,
			's-maxage' => $sMaxAge
		] );
	}

}
