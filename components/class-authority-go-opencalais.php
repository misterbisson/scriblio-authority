<?php

/**
 * This class includes code to integrate with https://github.com/Gigaom/go-opencalais.
 */
class Authority_GO_OpenCalais
{
	public function __construct()
	{
		add_filter( 'go_oc_response', array( $this, 'filter_response_add_terms' ), 2 );
	}

	public function filter_response_add_terms( $response )
	{
		$oc_config = go_opencalais()->admin()->config;

		foreach ( $response as $k => $v )
		{
			if ( isset( $v->_type, $v->name, $oc_config['mapping'][ $v->_type ] ) )
			{
				// is there a term for this, um, term?
				if ( ! $term = get_term_by( 'name', $v->name, $oc_config['mapping'][ $v->_type ] ) )
				{
					// attempt to create a proper term if none exists
					$term_id = wp_insert_term( $v->name, $oc_config['mapping'][ $v->_type ] );
					$term = get_term( $term_id['term_id'], $oc_config['mapping'][ $v->_type ] );
				}

				// if we found or create a term object for this suggestion
				if ( isset( $term->name ) )
				{

					// check if there's an authority record for this term
					if( $authority = authority_record()->get_term_authority( $term ) )
					{
						// set the suggested name to match the authoritative term name
						$response[ $k ]->name = $authority->primary_term->name;

						// replace the entity type to match the authority, if we have a mapping
						if ( $new_type = array_search( $authority->primary_term->taxonomy, $oc_config['mapping'] ) )
						{
							$response[ $k ]->_type = $new_type;
						}
					}
					else
					{
						// even if there is no authoritative term, reset the suggested name to so it matches the proper term
						$response[ $k ]->name = $term->name;
					}
				}
			}
		}

		return $response;
	}//end filter_response_add_terms
}
