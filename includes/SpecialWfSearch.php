<?php
use MediaWiki\MediaWikiServices;
use MediaWiki\Widget\Search\BasicSearchResultSetWidget;
use MediaWiki\Widget\Search\FullSearchResultWidget;
use MediaWiki\Widget\Search\InterwikiSearchResultWidget;
use MediaWiki\Widget\Search\InterwikiSearchResultSetWidget;
use MediaWiki\Widget\Search\SimpleSearchResultSetWidget;
use Wikifab\Widget\Search\WikifabSimpleSearchResultWidget;
use Wikifab\Widget\Search\WikifabFullSearchResultWidget;
use Wikifab\Widget\Search\WikifabBasicSearchResultSetWidget;
use Wikifab\Widget\Search\WikifabSearchFormWidget;

/**
 * Implements Special:WfSearch
 *
 *
 * This Special Page override the Special:Search
 * What do this class do :
 * change display widget, too change output layout
 *  -> change SimpleSearchResultSetWidget to WikifabSimpleSearchResultWidget -> USELESS
 *  -> change FullSearchResultWidget to WikifabFullSearchResultWidget
 *  -> change BasicSearchResultSetWidget to WikifabBasicSearchResultSetWidget
 *  -> change WikifabSearchFormWidget to SearchFormWidget
 *  -> use 'wfsearch' instead of 'search' for keyword in the GET param,
 *  	because 'search' keywords automaticaly redirect to Special:Search
 *
 *
 * @file
 * @ingroup SpecialPage
 */
class SpecialWfSearch extends SpecialSearch {


	public function __construct() {

		// we can't call parent contructor, because it rename page name,
		// so here is a copy all code that would be executed by parents :

		// this is magic , and doesn't work : we call sub parent contructor
		// (SpecialSearch extends  SpecialPage)
		//to call specialPage constructor without call SpecialSearch contructor
		//(because this last one set the name of the special page)
		$this->mName = 'WfSearch';
		$this->mRestriction = '';
		$this->mListed = true;
		$this->mIncludable = false;
		//var_dump(" SpecialWfSearch-> __construct()");die('yoyo');
		//SpecialPage::__construct( 'WfSearch' );
		$this->searchConfig = MediaWikiServices::getInstance()->getSearchEngineConfig();
	}

	public function execute( $par ) {
		$request = $this->getRequest();

		// param 'search whould ba absent for request,
		// we virtually set it with 'wfsearch' param
		$request->setVal('search',  $request->getText( 'wfsearch' ));

		parent::execute($par);
	}


	/**
	 * @param string $term
	 */
	public function showResults( $term ) {

		global $wgContLang;

		if ( $this->searchEngineType !== null ) {
			$this->setExtraParam( 'srbackend', $this->searchEngineType );
		}

		$out = $this->getOutput();
		$formWidget = new WikifabSearchFormWidget(
				$this,
				$this->searchConfig,
				$this->getSearchProfiles()
				);
		$filePrefix = $wgContLang->getFormattedNsText( NS_FILE ) . ':';
		if ( trim( $term ) === '' || $filePrefix === trim( $term ) ) {
			// Empty query -- straight view of search form
			if ( !Hooks::run( 'SpecialSearchResultsPrepend', [ $this, $out, $term ] ) ) {
				# Hook requested termination
				return;
			}
			$out->enableOOUI();
			// The form also contains the 'Showing results 0 - 20 of 1234' so we can
			// only do the form render here for the empty $term case. Rendering
			// the form when a search is provided is repeated below.
			$out->addHTML( $formWidget->render(
					$this->profile, $term, 0, 0, $this->offset, $this->isPowerSearch()
					) );
			return;
		}

		$search = $this->getSearchEngine();
		$search->setFeatureData( 'rewrite', $this->runSuggestion );
		$search->setLimitOffset( $this->limit, $this->offset );
		$search->setNamespaces( $this->namespaces );
		$search->prefix = $this->mPrefix;
		$term = $search->transformSearchTerm( $term );

		Hooks::run( 'SpecialSearchSetupEngine', [ $this, $this->profile, $search ] );
		if ( !Hooks::run( 'SpecialSearchResultsPrepend', [ $this, $out, $term ] ) ) {
			# Hook requested termination
			return;
		}

		$title = Title::newFromText( $term );
		$showSuggestion = $title === null || !$title->isKnown();
		$search->setShowSuggestion( $showSuggestion );

		// fetch search results
		$rewritten = $search->replacePrefixes( $term );

		$titleMatches = $search->searchTitle( $rewritten );
		$textMatches = $search->searchText( $rewritten );

		$textStatus = null;
		if ( $textMatches instanceof Status ) {
			$textStatus = $textMatches;
			$textMatches = $textStatus->getValue();
		}

		// Get number of results
		$titleMatchesNum = $textMatchesNum = $numTitleMatches = $numTextMatches = 0;
		if ( $titleMatches ) {
			$titleMatchesNum = $titleMatches->numRows();
			$numTitleMatches = $titleMatches->getTotalHits();
		}
		if ( $textMatches ) {
			$textMatchesNum = $textMatches->numRows();
			$numTextMatches = $textMatches->getTotalHits();
			if ( $textMatchesNum > 0 ) {
				$search->augmentSearchResults( $textMatches );
			}
		}
		$num = $titleMatchesNum + $textMatchesNum;
		$totalRes = $numTitleMatches + $numTextMatches;

		// start rendering the page
		$out->enableOOUI();
		$out->addHTML( $formWidget->render(
				$this->profile, $term, $num, $totalRes, $this->offset, $this->isPowerSearch()
				) );

		// did you mean... suggestions
		if ( $textMatches ) {
			$dymWidget = new MediaWiki\Widget\Search\DidYouMeanWidget( $this );
			$out->addHTML( $dymWidget->render( $term, $textMatches ) );
		}

		$out->addHTML( "<div class='searchresults'>" );

		$hasErrors = $textStatus && $textStatus->getErrors();
		$hasOtherResults = $textMatches &&
		$textMatches->hasInterwikiResults( SearchResultSet::INLINE_RESULTS );

		if ( $hasErrors ) {
			list( $error, $warning ) = $textStatus->splitByErrorType();
			if ( $error->getErrors() ) {
				$out->addHTML( Html::rawElement(
						'div',
						[ 'class' => 'errorbox' ],
						$error->getHTML( 'search-error' )
						) );
			}
			if ( $warning->getErrors() ) {
				$out->addHTML( Html::rawElement(
						'div',
						[ 'class' => 'warningbox' ],
						$warning->getHTML( 'search-warning' )
						) );
			}
		}

		// Show the create link ahead
		$this->showCreateLink( $title, $num, $titleMatches, $textMatches );

		Hooks::run( 'SpecialSearchResults', [ $term, &$titleMatches, &$textMatches ] );

		// If we have no results and have not already displayed an error message
		if ( $num === 0 && !$hasErrors ) {
			$out->wrapWikiMsg( "<p class=\"mw-search-nonefound\">\n$1</p>", [
					$hasOtherResults ? 'search-nonefound-thiswiki' : 'search-nonefound',
					wfEscapeWikiText( $term )
			] );
		}

		// Although $num might be 0 there can still be secondary or inline
		// results to display.
		$linkRenderer = $this->getLinkRenderer();
		//$mainResultWidget = new FullSearchResultWidget( $this, $linkRenderer );
		$mainResultWidget = new WikifabFullSearchResultWidget( $this, $linkRenderer );

		if ( $search->getFeatureData( 'enable-new-crossproject-page' ) ) {

			$sidebarResultWidget = new InterwikiSearchResultWidget( $this, $linkRenderer );
			$sidebarResultsWidget = new InterwikiSearchResultSetWidget(
					$this,
					$sidebarResultWidget,
					$linkRenderer,
					MediaWikiServices::getInstance()->getInterwikiLookup()
					);
		} else {
			//$sidebarResultWidget = new SimpleSearchResultWidget( $this, $linkRenderer );
			$sidebarResultWidget = new WikifabSimpleSearchResultWidget( $this, $linkRenderer );
			$sidebarResultsWidget = new SimpleSearchResultSetWidget(
					$this,
					$sidebarResultWidget,
					$linkRenderer,
					MediaWikiServices::getInstance()->getInterwikiLookup()
					);
		}

		$widget = new WikifabBasicSearchResultSetWidget( $this, $mainResultWidget, $sidebarResultsWidget );

		$out->addHTML( $widget->render(
				$term, $this->offset, $titleMatches, $textMatches
				) );

		if ( $titleMatches ) {
			$titleMatches->free();
		}

		if ( $textMatches ) {
			$textMatches->free();
		}

		$out->addHTML( '<div class="mw-search-visualclear"></div>' );

		// prev/next links
		if ( $totalRes > $this->limit || $this->offset ) {
			$prevnext = $this->getLanguage()->viewPrevNext(
					$this->getPageTitle(),
					$this->offset,
					$this->limit,
					$this->powerSearchOptions() + [ 'wfsearch' => $term ],
					$this->limit + $this->offset >= $totalRes
					);
			$out->addHTML( "<p class='mw-search-pager-bottom'>{$prevnext}</p>\n" );
		}

		// Close <div class='searchresults'>
		$out->addHTML( "</div>" );

		Hooks::run( 'SpecialSearchResultsAppend', [ $this, $out, $term ] );
	}


	/**
	 * override show createLink to never show them
	 *
	 * @param Title $title
	 * @param int $num The number of search results found
	 * @param null|SearchResultSet $titleMatches Results from title search
	 * @param null|SearchResultSet $textMatches Results from text search
	 */
	protected function showCreateLink( $title, $num, $titleMatches, $textMatches ) {
		return;
	}

}