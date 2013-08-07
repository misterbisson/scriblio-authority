<?php

class Scriblio_Authority_Suggest
{
	public $ep_name_suggest = 'scriblio-authority-suggest';

	public function __construct()
	{
		add_action( 'init', array( $this, 'init' ) );
	}//end __construct

	/**
	 * hooked into the init action
	 */
	public function init()
	{
		add_rewrite_endpoint( $this->ep_name_suggest, EP_ALL );

		add_filter( 'request', array( $this, 'request' ) );

		if ( is_admin() )
		{
			add_action( 'wp_ajax_scriblio_authority_suggestions', array( $this, 'get_suggestions' ) );
			wp_localize_script( 'jquery-ui-core', 'scrib_authority_suggest', array( 'url' => admin_url( 'admin-ajax.php?action=scriblio_authority_suggestions' ) ) );
		}
		else
		{
			wp_localize_script( 'jquery', 'scrib_authority_suggest', array( 'url' => home_url( "/{$this->ep_name_suggest}" ) ) );
		}
	}//end init

	public function add_query_var( $qvars )
	{
		$qvars[] = $this->ep_name_suggest;

		return $qvars;
	}//end add_query_var

	public function request( $request )
	{
		if ( isset( $request[ $this->ep_name_suggest ] ) )
		{
			add_filter( 'template_redirect' , array( $this, 'get_suggestions' ), 0 );
		}//end if

		return $request;
	}//end request

	/*
	 * returns a json formatted list of suggestions when called via public or dashboard URLs like:
	 * http://site.org/EP_NAME/?s=$search
	 * http://site.org/wp-admin/admin-ajax.php?action=scriblio_authority_suggestions&s=$search
	 *
	 * This is a public facing, unprotected method.
	*/
	public function get_suggestions()
	{
		$s = trim( $_GET['s'] );
		$suggestions = $this->suggestions( $s );

		header('Content-Type: application/json');
		echo json_encode( $suggestions );
		die;
	}//end get_suggestions

	/**
	 * generate suggestions based on a search term
	 */
	public function suggestions( $s = '' , $_taxonomy = array() )
	{
		$cache_id = 'authority_suggestions';

		// get and validate the search string
		$s = trim( $s );

		if ( 0 === strlen( $s ) )
		{
			return FALSE; // require 1 chars for matching
		}//end if

		// identify which taxonomies we're searching
		if( ! empty( $_taxonomy ) )
		{
			if( is_string( $_taxonomy ))
			{
				$taxonomy = explode( ',' , $_taxonomy );
			}//end if
			else
			{
				$taxonomy = $_taxonomy;
			}//end else

			$taxonomy = array_filter( array_map( 'trim', $taxonomy ) , 'taxonomy_exists' );
		}//end if
		else
		{
			// @TODO: this used to be configurable in the dashboard.
			$taxonomy = array_keys( authority_record()->supported_taxonomies() );
		}//end else

		// generate a key we can use to cache these results
		$cache_key = md5( $s . implode( $taxonomy ) . (int) empty( $_taxonomy ) );

		// get results from the cache or generate them fresh if necessary
		$suggestions = wp_cache_get( $cache_key , $cache_id );

		if( ! $suggestions )
		{
			global $wpdb;

			// init the result vars
			$suggestions = array();

			// sql to get the matching terms
			$sql = "
				SELECT
					tt.term_taxonomy_id,
					( ( 100 - t.len ) * tt.count ) AS hits
				FROM
					(
						SELECT
							term_id,
							name,
							slug,
							LENGTH(name) AS len
						FROM
							{$wpdb->terms}
						WHERE
							slug LIKE ( %s )
						ORDER BY
							len ASC
						LIMIT 100
					) t
				JOIN {$wpdb->term_taxonomy} AS tt
					ON tt.term_id = t.term_id 
					AND tt.taxonomy IN ('" . implode( "','", $taxonomy ). "')
				ORDER BY
					hits DESC
				LIMIT 25;
			";

			// execute the query
			$search_string = sanitize_title_with_dashes( $s );
			$ttids = $wpdb->get_results(
				$wpdb->prepare(
					$sql,
					$search_string . '%'
				)
			);

			// process the TT IDs into actual terms
			foreach( (array) $ttids as $ttid )
			{
				$terms[ $ttid->term_taxonomy_id ] = authority_record()->get_term_by_ttid( $ttid->term_taxonomy_id );
				$terms[ $ttid->term_taxonomy_id ]->count = $ttid->hits;
			}

			// filter the terms through the authorities
			$terms = authority_record()->filter_terms_by_authority( $terms );

			if ( $terms )
			{
				// create suggestions for the matched terms
				foreach( (array) $terms as $term )
				{
					$tax = get_taxonomy( $term->taxonomy );
					$suggestion = array(
						'taxonomy' => authority_record()->simplify_taxonomy_for_json( $tax ),
						'term' => $term->name . (( isset( $term->authority_synonyms ) && ! preg_match( '/^'. $search_string .'/', $term->slug ) ) ? ' (matched for "'. current( $term->authority_synonyms )->name .'")' : '' ),
						'data' => array(),
					);

					$suggestion['data']['term'] = "{$term->taxonomy}:{$term->slug}";
					$suggestion['data']['slug'] = $term->slug;

					$suggestions[] = $suggestion;
				}//end foreach
			}//end if

			wp_cache_set( $cache_key , $suggestions , $cache_id , 300 );
		}//end if

		return $suggestions;
	}//end suggestions
}//end class

function scriblio_authority_suggest()
{
	global $scriblio_authority_suggest;

	if ( ! $scriblio_authority_suggest )
	{
		$scriblio_authority_suggest = new Scriblio_Authority_Suggest;
	}//end if

	return $scriblio_authority_suggest;
}//end scriblio_authority_suggest
