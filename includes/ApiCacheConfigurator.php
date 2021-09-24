<?php

namespace MediaWiki\Extension\WikiDexApp;

use \Title;
use \MediaWiki\MediaWikiServices;

/**
 * Configures API responses for modules used by the app to boost caching
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
		if ( $this->mModule == null ) {
			return;
		}
		$apiMain = $this->mModule->getMain();
		$request = $apiMain->getRequest();
		# FauxRequest siempre retorna error al llamar a getRequestURL()
		if ( is_a( $request, 'FauxRequest' ) ) {
			return;
		}
		if ( $request->getMethod() != 'GET' && $request->getMethod() != 'HEAD' ) {
			return;
		}
		if ( $this->isHelpModule() ) {
			$this->setLooseCaching();
			return;
		}
		if ( $this->isOpenSearchModule() ) {
			$this->setCaching( 10800 ); // 3 hours
			return;
		}
		$action = $request->getVal( 'action' );
		if ( $action == 'parse' ) {
			$this->setParseActionCache( $request );
		} else if ( $action = 'query' ) {
			$this->setQueryActionCache( $request );
		} else if ( $action = 'wikidexpage' ) {
			$this->setWikiDexPageActionCache( $request );
		}
	}

	private function isHelpModule() {
		return is_a( $this->mModule, 'ApiHelp' );
	}

	private function isOpenSearchModule() {
		return is_a( $this->mModule, 'ApiOpenSearch' );
	}

	/**
	 * Evaluates if a WebRequest is equivalent to an URL
	 *
	 * @param WebRequest $request
	 */
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
		return $url == $request->getRequestURL();
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
		if ( $this->isSameRequestUrl( $request, $cacheURL ) ) {
			$apiMain = $this->mModule->getMain();
			$result = $apiMain->getResult();
			if ( is_a( $result, 'ApiResult' ) ) {
				$transforms = [ 'Strip' => 'all' ]; // Strip all metadata
				$redirects = $result->getResultData( [ 'parse', 'redirects' ], $transforms );
				if ( !empty( $redirects ) ) {
					// It resolved a redirect. We can't purge redirects
					$this->setMicroCaching();
				} else {
					$this->setStandardCaching();
				}
			}
		}
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
	private function setWikiDexPageActionCache( $request ) {
		$cacheURL = UrlMapper::getAppPageUrlWikiDexPage1();
		if ( $this->isSameRequestUrl( $request, $cacheURL ) ) {
			$this->setStandardCaching( 'anon-public-user-private' );
		}
	}

	private function setStandardCaching( $cacheMode = 'public' ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->setCaching( $config->get( 'CdnMaxAge' ), $cacheMode );
	}

	private function setLooseCaching() {
		$this->setCaching( 86400 ); // 1 day
	}

	private function setMicroCaching() {
		$this->setCaching( 3600 ); // 1 hour
	}

	private function setCaching( $sMaxAge, $cacheMode = 'public' ) {
		$apiMain = $this->mModule->getMain();
		$apiMain->setCacheMode( $cacheMode );
		$apiMain->setCacheControl( [
			'public' => true,
			'must-revalidate' => true,
			'max-age' => 60,
			's-maxage' => $sMaxAge
		] );
	}

}
