<?php

namespace MediaWiki\Extension\WikiDexApp\Api;

use \ApiBase;
use \ApiMessage;
use \ApiResult;
use \IDBAccessObject;
use \PoolCounterWorkViaCallback;
use \MediaWiki\MediaWikiServices;
use \MediaWiki\Permissions\UserAuthority;
use \MediaWiki\Revision\RevisionRecord;
use \MediaWiki\Revision\SlotRecord;
use \ParserOptions;
use \Status;
use \Title;
use \WikiPage;

/**
 * A module that retrieves all the information of a page to be displayed
 * on the app.
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiWikiDexPage extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();

		$this->requireAtLeastOneParameter( $params, 'title' );

		$titleObj = Title::newFromText( $params['title'] );
		if ( !$titleObj || $titleObj->isExternal() ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}
		if ( !$titleObj->canExist() ) {
			$this->dieWithError( 'apierror-pagecannotexist' );
		}

		// Current user not needed
		$anonUser = MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
		$anonAuthority = new UserAuthority( $anonUser, MediaWikiServices::getInstance()->getPermissionManager() );
		if ( !$anonAuthority->authorizeRead( 'read', $titleObj ) ) {
			$this->dieWithError(
				[ 'apierror-cannotviewtitle', wfEscapeWikiText( $titleObj->getPrefixedText() ) ]
			);
		}

		$pageObj = WikiPage::factory( $titleObj );
		$apiResult = $this->getResult();
		$result_array = [];

		if ( $titleObj->isRedirect() ) {
			$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
			$titles = $revisionLookup->getRevisionByTitle( $titleObj )
				->getContent( SlotRecord::MAIN )
				->getRedirectChain();
			$redirValues = [];

			/** @var Title $newTitle */
			foreach ( $titles as $id => $newTitle ) {
				$titles[$id - 1] = $titles[$id - 1] ?? $titleObj;

				$redirValues[] = [
					'from' => $titles[$id - 1]->getPrefixedText(),
					'to' => $newTitle->getPrefixedText()
				];

				$titleObj = $newTitle;
			}

			$result_array['redirects'] = $redirValues;
			ApiResult::setIndexedTagName( $redirValues, 'r' );

			// Since the page changed, update $pageObj
			$pageObj = WikiPage::factory( $titleObj );
		}
		$result_array['title'] = $titleObj->getPrefixedText();

		$pageObj->loadPageData();

		if ( !$titleObj->exists() ) {
			$this->dieWithError( 'apierror-missingtitle' );
		}

		// getParserOutput will save to Parser cache if able
		$pout = $this->getPageParserOutput( $pageObj );
		$text = $pout->getText( [
			'allowTOC' => false,
			'enableSectionEditLinks' => true,
		] );
		ApiResult::setContentValue( $result_array, 'text', $text );
		$displayTitle = $pout->getProperty( 'displaytitle' );
		if ( $displayTitle ) {
			$result_array['displaytitle'] = $displayTitle;
		}
		$pp = $pout->getProperties();
		if ( isset( $pp['noeditsection'] ) ) {
			$result_array['noeditsection'] = true;
		}
		if ( isset( $pp[ 'notoc' ] ) ) {
			$result_array['notoc'] = true;
		}
		$result_array['sections'] = $pout->getSections();
		ApiResult::setIndexedTagName( $result_array['sections'], 's' );
		$result_array['categories'] = $this->formatCategoryLinks( $pout->getCategories() );
		ApiResult::setIndexedTagName( $result_array['categories'], 'cl' );

		// Edit permissions
		$user = $this->getUser();
		$result_array['userCanEdit'] = $user->definitelyCan(
			$titleObj->exists() ? 'edit' : 'create',
			$titleObj
		);

		// Set the cache mode
		$this->getMain()->setCacheMode( 'anon-public-user-private' );

		$apiResult->addValue( null, $this->getModuleName(), $result_array );
	}

	public function getAllowedParams() {
		$params = [
			'title' => [
				ApiBase::PARAM_TYPE => 'string',
			],
		];

		return $params;
	}

	protected function getExamplesMessages() {
		return [
			'action=wikidexpage&format=json&formatversion=2&' .
				'title=Gu%C3%ADa%20de%20Detective%20Pikachu%2FP%C3%A1gina%201'
				=> 'apihelp-wikidexpage-example-page',
		];
	}

	public function getHelpUrls() {
		return 'https://github.com/ciencia/mediawiki-extensions-WikiDexApp/wiki/ApiWikiDexPage';
	}

	private function getPoolKey(): string {
		$ip = $this->getRequest()->getIP() ?? '';
		$poolKey = wfWikiID() . ':ApiWikiDexPage:a:' . $ip;
		return $poolKey;
	}

	private function getPageParserOutput( WikiPage $page ) {
		$worker = new PoolCounterWorkViaCallback( 'ApiWikiDexPage', $this->getPoolKey(),
			[
				'doWork' => static function () use ( $page ) {
					return $page->getParserOutput( ParserOptions::newFromAnon() );
				},
				'error' => function () {
					$this->dieWithError( 'apierror-concurrency-limit' );
				},
			]
		);
		return $worker->execute();
	}

	/**
	 * Method copied from ApiParse
	 */
	private function formatCategoryLinks( $links ) {
		$result = [];

		if ( !$links ) {
			return $result;
		}

		// Fetch hiddencat property
		$linkBatchFactory = MediaWikiServices::getInstance()->getLinkBatchFactory();
		$lb = $linkBatchFactory->newLinkBatch();
		$lb->setArray( [ NS_CATEGORY => $links ] );
		$db = $this->getDB();
		$res = $db->select( [ 'page', 'page_props' ],
			[ 'page_title', 'pp_propname' ],
			$lb->constructSet( 'page', $db ),
			__METHOD__,
			[],
			[ 'page_props' => [
				'LEFT JOIN', [ 'pp_propname' => 'hiddencat', 'pp_page = page_id' ]
			] ]
		);
		$hiddencats = [];
		foreach ( $res as $row ) {
			$hiddencats[$row->page_title] = isset( $row->pp_propname );
		}

		$linkCache = MediaWikiServices::getInstance()->getLinkCache();

		foreach ( $links as $link => $sortkey ) {
			$entry = [];
			$entry['sortkey'] = $sortkey;
			// array keys will cast numeric category names to ints, so cast back to string
			ApiResult::setContentValue( $entry, 'category', (string)$link );
			if ( !isset( $hiddencats[$link] ) ) {
				$entry['missing'] = true;

				// We already know the link doesn't exist in the database, so
				// tell LinkCache that before calling $title->isKnown().
				$title = Title::makeTitle( NS_CATEGORY, $link );
				$linkCache->addBadLinkObj( $title );
				if ( $title->isKnown() ) {
					$entry['known'] = true;
				}
			} elseif ( $hiddencats[$link] ) {
				$entry['hidden'] = true;
			}
			$result[] = $entry;
		}

		return $result;
	}

}
