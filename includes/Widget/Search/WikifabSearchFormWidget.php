<?php

namespace Wikifab\Widget\Search;

use Hooks;
use Html;
use MediaWiki\Widget\SearchInputWidget;
use MWNamespace;
use SearchEngineConfig;
use SpecialSearch;
use Xml;
use MediaWiki\Widget\Search\SearchFormWidget;

class WikifabSearchFormWidget extends SearchFormWidget {

	/**
	 * @param string $profile The current search profile
	 * @param string $term The current search term
	 * @param int $numResults The number of results shown
	 * @param int $totalResults The total estimated results found
	 * @param int $offset Current offset in search results
	 * @param bool $isPowerSearch Is the 'advanced' section open?
	 * @return string HTML
	 */
	public function render(
			$profile,
			$term,
			$numResults,
			$totalResults,
			$offset,
			$isPowerSearch
			) {
				return Xml::openElement(
						'form',
						[
								'id' => $isPowerSearch ? 'powersearch' : 'wfsearch',
								'method' => 'get',
								'action' => wfScript(),
						]
						) .
						'<div id="mw-search-top-table">' .
						$this->shortDialogHtml( $profile, $term, $numResults, $totalResults, $offset ) .
						'</div>' .
						"<div class='mw-search-visualclear'></div>" .
						$this->optionsHtml( $term, $isPowerSearch, $profile ) .
						'</form>';
	}

	/**
	 * @param string $profile The current search profile
	 * @param string $term The current search term
	 * @param int $numResults The number of results shown
	 * @param int $totalResults The total estimated results found
	 * @param int $offset Current offset in search results
	 * @return string HTML
	 */
	protected function shortDialogHtml( $profile, $term, $numResults, $totalResults, $offset ) {
		$html = '';

		$searchWidget = new SearchInputWidget( [
			'id' => 'searchText',
			'name' => 'wfsearch',
			'autofocus' => trim( $term ) === '',
			'value' => $term,
			'dataLocation' => 'content',
			'infusable' => true,
		] );

		$layout = new \OOUI\ActionFieldLayout( $searchWidget, new \OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'label' => $this->specialSearch->msg( 'searchbutton' )->text(),
			'flags' => [ 'progressive', 'primary' ],
		] ), [
			'align' => 'top',
		] );

		$html .= $layout;

		if ( $totalResults > 0 && $offset < $totalResults ) {
			$html .= Xml::tags(
				'div',
				[
					'class' => 'results-info',
					'data-mw-num-results-offset' => $offset,
					'data-mw-num-results-total' => $totalResults
				],
				$this->specialSearch->msg( 'search-showingresults' )
					->numParams( $offset + 1, $offset + $numResults, $totalResults )
					->numParams( $numResults )
					->parse()
			);
		}

		$html .=
			Html::hidden( 'title', $this->specialSearch->getPageTitle()->getPrefixedText() ) .
			Html::hidden( 'profile', $profile ) .
			Html::hidden( 'fulltext', '1' );

		return $html;
	}
}