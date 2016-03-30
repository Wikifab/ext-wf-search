<?php
/**
 * Implements Special:WfSearch
 *
 * Copyright © 2004 Brion Vibber <brion@pobox.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

/**
 * implements Special:Search - Run text & title search and display the output
 * @ingroup SpecialPage
 */
class SpecialWfSearch extends SpecialPage {
	/**
	 * Current search profile. Search profile is just a name that identifies
	 * the active search tab on the search page (content, discussions...)
	 * For users tt replaces the set of enabled namespaces from the query
	 * string when applicable. Extensions can add new profiles with hooks
	 * with custom search options just for that profile.
	 * @var null|string
	 */
	protected $profile;

	/** @var SearchEngine Search engine */
	protected $searchEngine;

	/** @var string Search engine type, if not default */
	protected $searchEngineType;

	/** @var array For links */
	protected $extraParams = array();

	/** @var string No idea, apparently used by some other classes */
	protected $mPrefix;

	/**
	 * @var int
	 */
	protected $limit, $offset;

	/**
	 * @var array
	 */
	protected $namespaces;

	/**
	 * @var string
	 */
	protected $didYouMeanHtml, $fulltext;

	const NAMESPACES_CURRENT = 'sense';

	public function __construct() {
		parent::__construct( 'WfSearch' );
	}

	/**
	 * Entry point
	 *
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->allowClickjacking();
		$out->addModuleStyles( array(
			'mediawiki.special', 'mediawiki.special.search', 'mediawiki.ui', 'mediawiki.ui.button',
			'mediawiki.ui.input',
		) );

		// Strip underscores from title parameter; most of the time we'll want
		// text form here. But don't strip underscores from actual text params!
		$titleParam = str_replace( '_', ' ', $par );

		$request = $this->getRequest();

		// Fetch the search term
		$search = str_replace( "\n", " ", $request->getText( 'wfsearch', $titleParam ) );

		$this->load();
		if ( !is_null( $request->getVal( 'nsRemember' ) ) ) {
			$this->saveNamespaces();
			// Remove the token from the URL to prevent the user from inadvertently
			// exposing it (e.g. by pasting it into a public wiki page) or undoing
			// later settings changes (e.g. by reloading the page).
			$query = $request->getValues();
			unset( $query['title'], $query['nsRemember'] );
			$out->redirect( $this->getPageTitle()->getFullURL( $query ) );
			return;
		}

		$this->searchEngineType = $request->getVal( 'srbackend' );

		if ( $request->getVal( 'fulltext' )
			|| !is_null( $request->getVal( 'offset' ) )
		) {
			$this->showResults( $search );
		} else {
			$this->goResult( $search );
		}
	}

	/**
	 * Set up basic search parameters from the request and user settings.
	 *
	 * @see tests/phpunit/includes/specials/SpecialSearchTest.php
	 */
	public function load() {
		$request = $this->getRequest();
		list( $this->limit, $this->offset ) = $request->getLimitOffset( 20, '' );
		$this->mPrefix = $request->getVal( 'prefix', '' );

		$user = $this->getUser();

		# Extract manually requested namespaces
		$nslist = $this->powerSearch( $request );
		if ( !count( $nslist ) ) {
			# Fallback to user preference
			$nslist = SearchEngine::userNamespaces( $user );
		}

		$profile = null;
		if ( !count( $nslist ) ) {
			$profile = 'default';
		}

		$profile = $request->getVal( 'profile', $profile );
		$profiles = $this->getSearchProfiles();
		if ( $profile === null ) {
			// BC with old request format
			$profile = 'advanced';
			foreach ( $profiles as $key => $data ) {
				if ( $nslist === $data['namespaces'] && $key !== 'advanced' ) {
					$profile = $key;
				}
			}
			$this->namespaces = $nslist;
		} elseif ( $profile === 'advanced' ) {
			$this->namespaces = $nslist;
		} else {
			if ( isset( $profiles[$profile]['namespaces'] ) ) {
				$this->namespaces = $profiles[$profile]['namespaces'];
			} else {
				// Unknown profile requested
				$profile = 'default';
				$this->namespaces = $profiles['default']['namespaces'];
			}
		}

		$this->didYouMeanHtml = ''; # html of did you mean... link
		$this->fulltext = $request->getVal( 'fulltext' );
		$this->profile = $profile;
	}

	/**
	 * If an exact title match can be found, jump straight ahead to it.
	 *
	 * @param string $term
	 */
	public function goResult( $term ) {
		$this->setupPage( $term );
		# Try to go to page as entered.
		$title = Title::newFromText( $term );
		# If the string cannot be used to create a title
		if ( is_null( $title ) ) {
			$this->showResults( $term );

			return;
		}
		# If there's an exact or very near match, jump right there.
		$title = SearchEngine::getNearMatch( $term );

		if ( !is_null( $title ) ) {
			$this->getOutput()->redirect( $title->getFullURL() );

			return;
		}
		# No match, generate an edit URL
		$title = Title::newFromText( $term );
		if ( !is_null( $title ) ) {
			wfRunHooks( 'SpecialSearchNogomatch', array( &$title ) );
		}
		$this->showResults( $term );
	}

	/**
	 * @param string $term
	 */
	public function showResults( $term ) {
		global $wgContLang;

		$profile = new ProfileSection( __METHOD__ );
		$search = $this->getSearchEngine();
		$search->setLimitOffset( $this->limit, $this->offset );
		$search->setNamespaces( $this->namespaces );
		$search->prefix = $this->mPrefix;
		$term = $search->transformSearchTerm( $term );

		wfRunHooks( 'SpecialSearchSetupEngine', array( $this, $this->profile, $search ) );

		$this->setupPage( $term );

		$out = $this->getOutput();

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
			$textMatches = null;
		}

		// did you mean... suggestions
		if ( $showSuggestion && $textMatches && !$textStatus && $textMatches->hasSuggestion() ) {
			# mirror Go/Search behavior of original request ..
			$didYouMeanParams = array( 'wfsearch' => $textMatches->getSuggestionQuery() );

			if ( $this->fulltext != null ) {
				$didYouMeanParams['fulltext'] = $this->fulltext;
			}

			$stParams = array_merge(
				$didYouMeanParams,
				$this->powerSearchOptions()
			);

			$suggestionSnippet = $textMatches->getSuggestionSnippet();

			if ( $suggestionSnippet == '' ) {
				$suggestionSnippet = null;
			}

			$suggestLink = Linker::linkKnown(
				$this->getPageTitle(),
				$suggestionSnippet,
				array(),
				$stParams
			);

			$this->didYouMeanHtml = '<div class="searchdidyoumean">'
				. $this->msg( 'search-suggest' )->rawParams( $suggestLink )->text() . '</div>';
		}

		if ( !wfRunHooks( 'SpecialSearchResultsPrepend', array( $this, $out, $term ) ) ) {
			# Hook requested termination
			return;
		}

		// start rendering the page
		$out->addHtml(
			Xml::openElement(
				'form',
				array(
					'id' => ( $this->profile === 'advanced' ? 'powersearch' : 'wfsearch' ),
					'method' => 'get',
					'action' => wfScript(),
				)
			)
		);

		// Get number of results
		$titleMatchesNum = $textMatchesNum = $numTitleMatches = $numTextMatches = 0;
		if ( $titleMatches ) {
			$titleMatchesNum = $titleMatches->numRows();
			$numTitleMatches = $titleMatches->getTotalHits();
		}
		if ( $textMatches ) {
			$textMatchesNum = $textMatches->numRows();
			$numTextMatches = $textMatches->getTotalHits();
		}
		$num = $titleMatchesNum + $textMatchesNum;
		$totalRes = $numTitleMatches + $numTextMatches;

		$out->addHtml(
			# This is an awful awful ID name. It's not a table, but we
			# named it poorly from when this was a table so now we're
			# stuck with it
			Xml::openElement( 'div', array( 'id' => 'mw-search-top-table' ) ) .
			$this->shortDialog( $term, $num, $totalRes ) .
			Xml::closeElement( 'div' ) .
			$this->formHeader( $term ) .
			Xml::closeElement( 'form' )
		);

		$filePrefix = $wgContLang->getFormattedNsText( NS_FILE ) . ':';
		if ( trim( $term ) === '' || $filePrefix === trim( $term ) ) {
			// Empty query -- straight view of search form
			return;
		}

		$out->addHtml( "<div class='searchresults'>" );

		// prev/next links
		$prevnext = null;
		if ( $num || $this->offset ) {
			// Show the create link ahead
			$this->showCreateLink( $title, $num, $titleMatches, $textMatches );
			if ( $totalRes > $this->limit || $this->offset ) {
				if ( $this->searchEngineType !== null ) {
					$this->setExtraParam( 'srbackend', $this->searchEngineType );
				}
				$prevnext = $this->getLanguage()->viewPrevNext(
					$this->getPageTitle(),
					$this->offset,
					$this->limit,
					$this->powerSearchOptions() + array( 'wfsearch' => $term ),
					$this->limit + $this->offset >= $totalRes
				);
			}
		}
		wfRunHooks( 'SpecialSearchResults', array( $term, &$titleMatches, &$textMatches ) );

		$out->parserOptions()->setEditSection( false );


		$this->wikifabSearchResultFormatter = new WikifabSearchResultFormatter();


		$this->wikifabSearchResultFormatter->init($this);
		$this->wikifabSearchResultFormatter->setTerm($term);
		$this->wikifabSearchResultFormatter->setTitleMatches($titleMatches);
		$this->wikifabSearchResultFormatter->setTextMatches($textMatches);

		$this->wikifabSearchResultFormatter->render($out);

		$titleMatches->free();
		$textMatches->free();


		
		$out->addHtml( "</div>" );

		if ( $prevnext ) {
			$out->addHTML( "<p class='mw-search-pager-bottom'>{$prevnext}</p>\n" );
		}
	}

	/**
	 * @param Title $title
	 * @param int $num The number of search results found
	 * @param null|SearchResultSet $titleMatches Results from title search
	 * @param null|SearchResultSet $textMatches Results from text search
	 */
	protected function showCreateLink( $title, $num, $titleMatches, $textMatches ) {
		// show direct page/create link if applicable

		// Check DBkey !== '' in case of fragment link only.
		if ( is_null( $title ) || $title->getDBkey() === ''
			|| ( $titleMatches !== null && $titleMatches->searchContainedSyntax() )
			|| ( $textMatches !== null && $textMatches->searchContainedSyntax() )
		) {
			// invalid title
			// preserve the paragraph for margins etc...
			$this->getOutput()->addHtml( '<p></p>' );

			return;
		}

		$linkClass = 'mw-search-createlink';
		if ( $title->isKnown() ) {
			$messageName = 'searchmenu-exists';
			$linkClass = 'mw-search-exists';
		} elseif ( $title->quickUserCan( 'create', $this->getUser() ) ) {
			$messageName = 'searchmenu-new';
		} else {
			$messageName = 'searchmenu-new-nocreate';
		}
		$params = array(
			$messageName,
			wfEscapeWikiText( $title->getPrefixedText() ),
			Message::numParam( $num )
		);
		wfRunHooks( 'SpecialSearchCreateLink', array( $title, &$params ) );

		// Extensions using the hook might still return an empty $messageName
		if ( $messageName ) {
			$this->getOutput()->wrapWikiMsg( "<p class=\"$linkClass\">\n$1</p>", $params );
		} else {
			// preserve the paragraph for margins etc...
			$this->getOutput()->addHtml( '<p></p>' );
		}
	}

	/**
	 * @param string $term
	 */
	protected function setupPage( $term ) {
		# Should advanced UI be used?
		$this->searchAdvanced = ( $this->profile === 'advanced' );
		$out = $this->getOutput();
		if ( strval( $term ) !== '' ) {
			$out->setPageTitle( $this->msg( 'searchresults' ) );
			$out->setHTMLTitle( $this->msg( 'pagetitle' )
				->rawParams( $this->msg( 'searchresults-title' )->rawParams( $term )->text() )
				->inContentLanguage()->text()
			);
		}
		// add javascript specific to special:search
		$out->addModules( 'mediawiki.special.search' );
	}

	/**
	 * Extract "power search" namespace settings from the request object,
	 * returning a list of index numbers to search.
	 *
	 * @param WebRequest $request
	 * @return array
	 */
	protected function powerSearch( &$request ) {
		$arr = array();
		foreach ( SearchEngine::searchableNamespaces() as $ns => $name ) {
			if ( $request->getCheck( 'ns' . $ns ) ) {
				$arr[] = $ns;
			}
		}

		return $arr;
	}

	/**
	 * Reconstruct the 'power search' options for links
	 *
	 * @return array
	 */
	protected function powerSearchOptions() {
		$opt = array();
		if ( $this->profile !== 'advanced' ) {
			$opt['profile'] = $this->profile;
		} else {
			foreach ( $this->namespaces as $n ) {
				$opt['ns' . $n] = 1;
			}
		}

		return $opt + $this->extraParams;
	}

	/**
	 * Save namespace preferences when we're supposed to
	 *
	 * @return bool Whether we wrote something
	 */
	protected function saveNamespaces() {
		$user = $this->getUser();
		$request = $this->getRequest();

		if ( $user->isLoggedIn() &&
			$user->matchEditToken(
				$request->getVal( 'nsRemember' ),
				'searchnamespace',
				$request
			)
		) {
			// Reset namespace preferences: namespaces are not searched
			// when they're not mentioned in the URL parameters.
			foreach ( MWNamespace::getValidNamespaces() as $n ) {
				$user->setOption( 'searchNs' . $n, false );
			}
			// The request parameters include all the namespaces to be searched.
			// Even if they're the same as an existing profile, they're not eaten.
			foreach ( $this->namespaces as $n ) {
				$user->setOption( 'searchNs' . $n, true );
			}

			$user->saveSettings();
			return true;
		}

		return false;
	}

	/**
	 * Generates the power search box at [[Special:Search]]
	 *
	 * @param string $term Search term
	 * @param array $opts
	 * @return string HTML form
	 */
	protected function powerSearchBox( $term, $opts ) {
		global $wgContLang;

		// Groups namespaces into rows according to subject
		$rows = array();
		foreach ( SearchEngine::searchableNamespaces() as $namespace => $name ) {
			$subject = MWNamespace::getSubject( $namespace );
			if ( !array_key_exists( $subject, $rows ) ) {
				$rows[$subject] = "";
			}

			$name = $wgContLang->getConverter()->convertNamespace( $namespace );
			if ( $name == '' ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}

			$rows[$subject] .=
				Xml::openElement( 'td' ) .
				Xml::checkLabel(
					$name,
					"ns{$namespace}",
					"mw-search-ns{$namespace}",
					in_array( $namespace, $this->namespaces )
				) .
				Xml::closeElement( 'td' );
		}

		$rows = array_values( $rows );
		$numRows = count( $rows );

		// Lays out namespaces in multiple floating two-column tables so they'll
		// be arranged nicely while still accommodating different screen widths
		$namespaceTables = '';
		for ( $i = 0; $i < $numRows; $i += 4 ) {
			$namespaceTables .= Xml::openElement(
				'table',
				array( 'cellpadding' => 0, 'cellspacing' => 0 )
			);

			for ( $j = $i; $j < $i + 4 && $j < $numRows; $j++ ) {
				$namespaceTables .= Xml::tags( 'tr', null, $rows[$j] );
			}

			$namespaceTables .= Xml::closeElement( 'table' );
		}

		$showSections = array( 'namespaceTables' => $namespaceTables );

		wfRunHooks( 'SpecialSearchPowerBox', array( &$showSections, $term, $opts ) );

		$hidden = '';
		foreach ( $opts as $key => $value ) {
			$hidden .= Html::hidden( $key, $value );
		}

		# Stuff to feed saveNamespaces()
		$remember = '';
		$user = $this->getUser();
		if ( $user->isLoggedIn() ) {
			$remember .= Xml::checkLabel(
				wfMessage( 'powersearch-remember' )->text(),
				'nsRemember',
				'mw-search-powersearch-remember',
				false,
				// The token goes here rather than in a hidden field so it
				// is only sent when necessary (not every form submission).
				array( 'value' => $user->getEditToken(
					'searchnamespace',
					$this->getRequest()
				) )
			);
		}

		// Return final output
		return Xml::openElement( 'fieldset', array( 'id' => 'mw-searchoptions' ) ) .
			Xml::element( 'legend', null, $this->msg( 'powersearch-legend' )->text() ) .
			Xml::tags( 'h4', null, $this->msg( 'powersearch-ns' )->parse() ) .
			Xml::element( 'div', array( 'id' => 'mw-search-togglebox' ), '', false ) .
			Xml::element( 'div', array( 'class' => 'divider' ), '', false ) .
			implode( Xml::element( 'div', array( 'class' => 'divider' ), '', false ), $showSections ) .
			$hidden .
			Xml::element( 'div', array( 'class' => 'divider' ), '', false ) .
			$remember .
			Xml::closeElement( 'fieldset' );
	}

	/**
	 * @return array
	 */
	protected function getSearchProfiles() {
		// Builds list of Search Types (profiles)
		$nsAllSet = array_keys( SearchEngine::searchableNamespaces() );

		$profiles = array(
			'default' => array(
				'message' => 'searchprofile-articles',
				'tooltip' => 'searchprofile-articles-tooltip',
				'namespaces' => SearchEngine::defaultNamespaces(),
				'namespace-messages' => SearchEngine::namespacesAsText(
					SearchEngine::defaultNamespaces()
				),
			),
			'images' => array(
				'message' => 'searchprofile-images',
				'tooltip' => 'searchprofile-images-tooltip',
				'namespaces' => array( NS_FILE ),
			),
			'all' => array(
				'message' => 'searchprofile-everything',
				'tooltip' => 'searchprofile-everything-tooltip',
				'namespaces' => $nsAllSet,
			),
			'advanced' => array(
				'message' => 'searchprofile-advanced',
				'tooltip' => 'searchprofile-advanced-tooltip',
				'namespaces' => self::NAMESPACES_CURRENT,
			)
		);

		wfRunHooks( 'SpecialSearchProfiles', array( &$profiles ) );

		foreach ( $profiles as &$data ) {
			if ( !is_array( $data['namespaces'] ) ) {
				continue;
			}
			sort( $data['namespaces'] );
		}

		return $profiles;
	}

	/**
	 * @param string $term
	 * @return string
	 */
	protected function formHeader( $term ) {
		$out = Xml::openElement( 'div', array( 'class' => 'mw-search-formheader' ) );

		$bareterm = $term;
		if ( $this->startsWithImage( $term ) ) {
			// Deletes prefixes
			$bareterm = substr( $term, strpos( $term, ':' ) + 1 );
		}

		$profiles = $this->getSearchProfiles();
		$lang = $this->getLanguage();

		// Outputs XML for Search Types
		$out .= Xml::openElement( 'div', array( 'class' => 'search-types' ) );
		$out .= Xml::openElement( 'ul' );
		foreach ( $profiles as $id => $profile ) {
			if ( !isset( $profile['parameters'] ) ) {
				$profile['parameters'] = array();
			}
			$profile['parameters']['profile'] = $id;

			$tooltipParam = isset( $profile['namespace-messages'] ) ?
				$lang->commaList( $profile['namespace-messages'] ) : null;
			$out .= Xml::tags(
				'li',
				array(
					'class' => $this->profile === $id ? 'current' : 'normal'
				),
				$this->makeSearchLink(
					$bareterm,
					array(),
					$this->msg( $profile['message'] )->text(),
					$this->msg( $profile['tooltip'], $tooltipParam )->text(),
					$profile['parameters']
				)
			);
		}
		$out .= Xml::closeElement( 'ul' );
		$out .= Xml::closeElement( 'div' );
		$out .= Xml::element( 'div', array( 'style' => 'clear:both' ), '', false );
		$out .= Xml::closeElement( 'div' );

		// Hidden stuff
		$opts = array();
		$opts['profile'] = $this->profile;

		if ( $this->profile === 'advanced' ) {
			$out .= $this->powerSearchBox( $term, $opts );
		} else {
			$form = '';
			wfRunHooks( 'SpecialSearchProfileForm', array( $this, &$form, $this->profile, $term, $opts ) );
			$out .= $form;
		}

		return $out;
	}

	/**
	 * @param string $term
	 * @param int $resultsShown
	 * @param int $totalNum
	 * @return string
	 */
	protected function shortDialog( $term, $resultsShown, $totalNum ) {
		$out = Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() );
		$out .= Html::hidden( 'profile', $this->profile ) . "\n";
		// Term box
		$out .= Html::input( 'wfsearch', $term, 'wfsearch', array(
			'id' => $this->profile === 'advanced' ? 'powerSearchText' : 'searchText',
			'size' => '50',
			'autofocus',
			'class' => 'mw-ui-input mw-ui-input-inline',
		) ) . "\n";
		$out .= Html::hidden( 'fulltext', 'Search' ) . "\n";
		$out .= Xml::submitButton(
			$this->msg( 'searchbutton' )->text(),
			array( 'class' => array( 'mw-ui-button', 'mw-ui-progressive' ) )
		) . "\n";

		// Results-info
		if ( $totalNum > 0 && $this->offset < $totalNum ) {
			$top = $this->msg( 'search-showingresults' )
				->numParams( $this->offset + 1, $this->offset + $resultsShown, $totalNum )
				->numParams( $resultsShown )
				->parse();
			$out .= Xml::tags( 'div', array( 'class' => 'results-info' ), $top ) .
				Xml::element( 'div', array( 'style' => 'clear:both' ), '', false );
		}

		return $out . $this->didYouMeanHtml;
	}

	/**
	 * Make a search link with some target namespaces
	 *
	 * @param string $term
	 * @param array $namespaces Ignored
	 * @param string $label Link's text
	 * @param string $tooltip Link's tooltip
	 * @param array $params Query string parameters
	 * @return string HTML fragment
	 */
	protected function makeSearchLink( $term, $namespaces, $label, $tooltip, $params = array() ) {
		$opt = $params;
		foreach ( $namespaces as $n ) {
			$opt['ns' . $n] = 1;
		}

		$stParams = array_merge(
			array(
				'wfsearch' => $term,
				'fulltext' => $this->msg( 'wfsearch' )->text()
			),
			$opt
		);

		return Xml::element(
			'a',
			array(
				'href' => $this->getPageTitle()->getLocalURL( $stParams ),
				'title' => $tooltip
			),
			$label
		);
	}

	/**
	 * Check if query starts with image: prefix
	 *
	 * @param string $term The string to check
	 * @return bool
	 */
	protected function startsWithImage( $term ) {
		global $wgContLang;

		$parts = explode( ':', $term );
		if ( count( $parts ) > 1 ) {
			return $wgContLang->getNsIndex( $parts[0] ) == NS_FILE;
		}

		return false;
	}

	/**
	 * Check if query starts with all: prefix
	 *
	 * @param string $term The string to check
	 * @return bool
	 */
	protected function startsWithAll( $term ) {

		$allkeyword = $this->msg( 'searchall' )->inContentLanguage()->text();

		$parts = explode( ':', $term );
		if ( count( $parts ) > 1 ) {
			return $parts[0] == $allkeyword;
		}

		return false;
	}

	/**
	 * @since 1.18
	 *
	 * @return SearchEngine
	 */
	public function getSearchEngine() {
		if ( $this->searchEngine === null ) {
			$this->searchEngine = $this->searchEngineType ?
				SearchEngine::create( $this->searchEngineType ) : SearchEngine::create();
		}

		return $this->searchEngine;
	}

	/**
	 * Current search profile.
	 * @return null|string
	 */
	function getProfile() {
		return $this->profile;
	}

	/**
	 * Current namespaces.
	 * @return array
	 */
	function getNamespaces() {
		return $this->namespaces;
	}

	/**
	 * Users of hook SpecialSearchSetupEngine can use this to
	 * add more params to links to not lose selection when
	 * user navigates search results.
	 * @since 1.18
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	public function setExtraParam( $key, $value ) {
		$this->extraParams[$key] = $value;
	}

	protected function getGroupName() {
		return 'pages';
	}

	/** added function for wikifab needs */

}