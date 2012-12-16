<?php
class Authority_Posttype {

	public $admin_obj = null;
	public $version = 7;
	public $id_base = 'scrib-authority';
	public $post_type_name = 'scrib-authority';
	public $tools_page_id = 'scrib-authority-tools';
	public $post_meta_key = 'scrib-authority';
	public $cache_ttl = 259183; // a prime number slightly less than 3 days
	public $taxonomies = array(); // unsanitized array of supported taxonomies by tax slug
	public $taxonomy_objects = array(); // sanitized and validated array of taxonomy objects

	public function __construct()
	{
		$this->plugin_url = untrailingslashit( plugin_dir_url( __FILE__ ));

		add_action( 'init' , array( $this, 'register_post_type' ) , 11 );

		add_filter( 'template_redirect', array( $this, 'template_redirect' ) , 1 );
		add_filter( 'post_link', array( $this, 'post_link' ), 11, 2 );
		add_filter( 'post_type_link', array( $this, 'post_link' ), 11, 2 );

		add_action( 'save_post', array( $this , 'enforce_authority_on_object' ) , 9 );

		if ( is_admin() )
		{
			require_once dirname( __FILE__ ) . '/class-authority-posttype-admin.php';
			$this->admin_obj = new Authority_Posttype_Admin;
			$this->admin_obj->plugin_url = $this->plugin_url;

			require_once dirname( __FILE__ ) . '/class-authority-posttype-tools.php';
			$this->tools_obj = new Authority_Posttype_Tools;
		}
	}

	public function delete_term_authority_cache( $term )
	{

		// validate the input
		if( ! isset( $term->term_taxonomy_id ))
			return FALSE;

		wp_cache_delete( $term->term_taxonomy_id , 'scrib_authority_ttid' );
	}

	public function get_term_authority( $term )
	{

		// validate the input
		if( ! isset( $term->term_id , $term->taxonomy , $term->term_taxonomy_id ))
			return FALSE;

		if( $return = wp_cache_get( $term->term_taxonomy_id , 'scrib_authority_ttid' ))
			return $return;

		// query to find a matching authority record
		$query = array(
			'numberposts' => 10,
			'post_type' => $this->post_type_name,
			'tax_query' => array(
				array(
					'taxonomy' => $term->taxonomy,
					'field' => 'id',
					'terms' => $term->term_id,
				)
			),
			'suppress_filters' => TRUE,
		);

		// fetch the authority info
		if( $authority = get_posts( $query ))
		{
			// get the authoritative term info
			$authority_meta = $this->get_post_meta( $authority[0]->ID );

			// initialize the return value
			$return = array(
				'primary_term' => '',
				'alias_terms' => '',
				'parent_terms' => '',
				'child_terms' => '',
			);

			$return = array_intersect_key( (array) $authority_meta , $return );
			$return['post_id'] = $authority[0]->ID;

			if( 1 < count( $authority ))
			{
				foreach( $authority as $conflict )
				{
					$return['conflict_ids'][] = $conflict->ID;
				}
			}

			wp_cache_set( $term->term_taxonomy_id , (object) $return , 'scrib_authority_ttid' , $this->cache_ttl );
			return (object) $return;
		}

		// no authority records
		return FALSE;
	}

	public function template_redirect()
	{
		// get the details about the queried object
		$queried_object = get_queried_object();

		// is this a request for our post type? redirect to the taxonomy permalink if so
		if (
			isset( $queried_object->post_type ) &&
			( $this->post_type_name == $queried_object->post_type )
		)
		{
			wp_redirect( $this->post_link( '' , $queried_object ) );
			die;
		}

		// is this a taxonomy request? return if not
		if( ! isset( $queried_object->term_id ))
		{
			return;
		}


		// check for an authority record, return if none found
		if( ! $authority = $this->get_term_authority( $queried_object ))
		{
			return;
		}

		// we have an authority record, but
		// don't attempt to redirect requests for the authoritative term
		if( $queried_object->term_taxonomy_id == $authority->primary_term->term_taxonomy_id )
			return;

		// we have an authority record, and
		// we're on an alias term, redirect
		wp_redirect( get_term_link( (int) $authority->primary_term->term_id , $authority->primary_term->taxonomy ));
		die;

	}

	public function post_link( $permalink , $post )
	{
		// return early if this isn't a request for our post type
		if ( $this->post_type_name != $post->post_type )
		{
			return $permalink;
		}

		// get the authoritative term info
		$authority = (object) $this->get_post_meta( $post->ID );

		// fail early if the primary_term isn't set
		if( ! isset( $authority->primary_term ))
		{
			return $permalink;
		}

		// test if this is a valid term
		if( ! get_term( $authority->primary_term->term_id , $authority->primary_term->taxonomy ) )
		{
			return $permalink;
		}

		// return the permalink for the primary term
		return get_term_link( (int) $authority->primary_term->term_id , $authority->primary_term->taxonomy );

	}//end post_link

	public function add_taxonomy( $taxonomy )
	{
		$this->taxonomies[ $taxonomy ] = $taxonomy;
	}

	public function supported_taxonomies( $support = null )
	{
		if ( $support )
		{
			$this->taxonomy_objects = get_taxonomies( array( 'public' => true ), 'objects' );

			$purge = array_diff( array_keys( $this->taxonomy_objects ), $support );

			foreach( $purge as $remove )
			{
				unset( $this->taxonomy_objects[ $remove ] );
			}//end foreach

			// sort taxonomies by the singular name
			uasort( $this->taxonomy_objects, array( $this , '_sort_taxonomies' ));
		}//end if

		return $this->taxonomy_objects;
	}//end supported_taxonomies

	public function _sort_taxonomies( $a , $b )
	{
		if ( $a->labels->singular_name == $b->labels->singular_name )
		{
			return 0;
		}//end if

		if ( 'post_tag' == $b->name  )
		{
			return -1;
		}//end if

		return $a->labels->singular_name < $b->labels->singular_name ? -1 : 1;
	}

	public function get_post_meta( $post_id )
	{
		$this->instance = get_post_meta( $post_id , $this->post_meta_key , TRUE );
		return $this->instance;
	}

	public function update_post_meta( $post_id , $meta_array )
	{
		// make sure meta is added to the post, not a revision
		if ( $_post_id = wp_is_post_revision( $post_id ))
			$post_id = $_post_id;

		// the terms we'll set on this object
		$object_terms = array();

		if( is_object( $meta_array ) )
		{
			$meta = (array) $meta_array;
		}
		else
		{
			$meta = $meta_array;
		}

		// primary (authoritative) taxonomy term
		if( isset( $meta['primary_term']->term_id ))
		{
			$object_terms[ $meta['primary_term']->taxonomy ][] = (int) $meta['primary_term']->term_id;

			// clear the authority cache for this term
			$this->delete_term_authority_cache( $meta['primary_term'] );

			// updating the post title is a pain in the ass, just look at what happens when we try to save it
			$post = get_post( $post_id );
			$post->post_title = $meta['primary_term']->name;
			if( ! preg_match( '/^'. $meta['primary_term']->slug .'/', $post->post_name ))
			{
				// update the title
				$post->post_name = $meta['primary_term']->slug;

				// remove revision support
				// but this post type doesn't support revisions
				// remove_post_type_support(  $this->post_type_name , 'revisions' );

				// remove the action before attempting to save the post, then reinstate it
				if( isset( $this->admin_obj ))
				{
					remove_action( 'save_post', array( $this->admin_obj , 'save_post' ));
					wp_insert_post( $post );
					add_action( 'save_post', array( $this->admin_obj , 'save_post' ));
				}
				else
				{
					wp_insert_post( $post );
				}

				// add back the revision support
				// but this post type doesn't support revisions
				// add_post_type_support( $this->post_type_name , 'revisions' );
			}
		}

		// alias terms
		$alias_dedupe = array();
		foreach( (array) $meta['alias_terms'] as $term )
		{
			$alias_dedupe[ (int) $term->term_taxonomy_id ] = $term;
		}
		$meta['alias_terms'] = $alias_dedupe;
		unset( $alias_dedupe );

		foreach( (array) $meta['alias_terms'] as $term )
		{
				// don't insert the primary term as an alias, that's just silly
				if( $term->term_taxonomy_id == $meta['primary_term']->term_taxonomy_id )
					continue;

				$object_terms[ $term->taxonomy ][] = (int) $term->term_id;
				$this->delete_term_authority_cache( $term );
		}

		// save it
		update_post_meta( $post_id , $this->post_meta_key , $meta );

		// update the term relationships for this post (add the primary and alias terms)
		foreach( (array) $object_terms as $k => $v )
			wp_set_object_terms( $post_id , $v , $k , FALSE );
	}

	public function register_post_type()
	{
		$taxonomies = $this->supported_taxonomies( $this->taxonomies );

		register_post_type( $this->post_type_name,
			array(
				'labels' => array(
					'name' => __( 'Authority Records' ),
					'singular_name' => __( 'Authority Record' ),
				),
				'supports' => array(
					'title',
					'excerpt',
//					'editor',
					'thumbnail',
				),
				'register_meta_box_cb' => array( $this->admin_obj , 'metaboxes' ),
				'public' => TRUE,
				'taxonomies' => array_keys( $taxonomies ),
			)
		);
	}

	// WP sometimes fails to update this count during regular operations, so this fixes that
	// it's not actually called anywhere, though
	function _update_term_counts()
	{
		global $wpdb;

		$wpdb->get_results('
			UPDATE '. $wpdb->term_taxonomy .' tt
			SET tt.count = (
				SELECT COUNT(*)
				FROM '. $wpdb->term_relationships .' tr
				WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
			)'
		);
	}

	public function enforce_authority_on_object( $object_id )
	{
		// don't run on post revisions (almost always happens just before the real post is saved)
		if( wp_is_post_revision( $object_id ) )
			return;

		if( ! $object_id )
			return;

		// get and check the post
		$post = get_post( $object_id );

		// don't mess with authority posts
		if( ! isset( $post->post_type ) || $this->post_type_name == $post->post_type )
			return;

		// get the terms to work with
		$terms = wp_get_object_terms( $object_id , array_keys( $this->supported_taxonomies() ) );

		$delete_terms = array();

		$new_object_terms = $terms_to_delete = array();
		foreach( $terms as $term )
		{
			if( $authority = $this->get_term_authority( $term ))
			{
				// add the preferred term to list of terms to add to the object
				$new_object_terms[ $authority->primary_term->taxonomy ][] = (int) $authority->primary_term->term_id;

				// if the current term is not in the same taxonomy as the preferred term, list it for removal from the object
				if( $authority->primary_term->taxonomy != $term->taxonomy )
					$delete_terms[] = $term->term_taxonomy_id;

			}
		}

		// remove the alias terms that are not in primary taxonomy
		if( count( $delete_terms ))
			$this->delete_terms_from_object_id( $object_id , $delete_terms );

		// add the alias and parent terms to the object
		if( count( $new_object_terms ))
		{
			foreach( (array) $new_object_terms as $k => $v )
			{
				wp_set_object_terms( $object_id , $v , $k , TRUE );
			}

			update_post_cache( $post );

		}
	}

	// WP has no convenient method to delete a single term from an object, but this is what's used in wp-includes/taxonomy.php
	public function delete_terms_from_object_id( $object_id , $delete_terms )
	{
		global $wpdb;
		$in_delete_terms = "'". implode( "', '", $delete_terms ) ."'";
		do_action( 'delete_term_relationships', $object_id, $delete_terms );
		$wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id IN ( $in_delete_terms )" , $object_id ));
		do_action( 'deleted_term_relationships', $object_id, $delete_terms );
		wp_update_term_count( $delete_terms , $taxonomy_info->name );

		update_post_cache( get_post( $object_id ));

		return;
	}

	// I'm pretty sure the only reason why terms aren't fetchable by TTID has to do with the history of WPMU and sitewide terms.
	// In this case, we need a UI that accepts terms from multiple taxonomies, so we use the TTID to represent the term in the form element,
	// and we need this function to translate those TTIDs into real terms for storage when the form is submitted.
	public function get_term_by_ttid( $tt_id )
	{
		global $wpdb;

		$term_id_and_tax = $wpdb->get_row( $wpdb->prepare( "SELECT term_id , taxonomy FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d LIMIT 1" , $tt_id ) , OBJECT );

		if( ! $term_id_and_tax )
		{
			$error = new WP_Error( 'invalid_ttid' , 'Invalid term taxonomy ID' );
			return $error;
		}

		return get_term( (int) $term_id_and_tax->term_id , $term_id_and_tax->taxonomy );
	}

	public function get_ttids_in_authority( $post_id )
	{
		// get the terms for the requested post
		$this->instance = $this->get_post_meta( $post_id );

		// sanity check ourselves
		if( ! isset( $this->instance['primary_term'] ))
		{
			return FALSE;
		}

		// build an array of the primary and alias TT IDs
		$search_ttids = array();
		if( isset( $this->instance['primary_term']->term_taxonomy_id ) )
		{
			$search_ttids[ (int) $this->instance['primary_term']->term_taxonomy_id ] = (int) $this->instance['primary_term']->term_taxonomy_id;
		}

		if( isset( $this->instance['alias_terms'] ) )
		{
			foreach( (array) $this->instance['alias_terms'] as $term )
			{
				$search_ttids[ (int) $term->term_taxonomy_id ] = (int) $term->term_taxonomy_id;
			}
		}

		// add an array of all TT IDs, including parent and child terms
		$exclude_ttids = $search_ttids;
		if( isset( $this->instance['parent_terms'] ) )
		{
			foreach( (array) $this->instance['parent_terms'] as $term )
			{
				$exclude_ttids[ (int) $term->term_taxonomy_id ] = (int) $term->term_taxonomy_id;
			}
		}

		if( isset( $this->instance['child_terms'] ) )
		{
			foreach( (array) $this->instance['child_terms'] as $term )
			{
				$exclude_ttids[ (int) $term->term_taxonomy_id ] = (int) $term->term_taxonomy_id;
			}
		}

		return (object) array( 'synonym_ttids' => $search_ttids , 'all_ttids' => $exclude_ttids );
	}

	public function get_related_terms_for_authority( $post_id )
	{

		// get TT IDs
		$ttids = $this->get_ttids_in_authority( $post_id );
		$search_ttids = $ttids->synonym_ttids;
		$exclude_ttids = $ttids->all_ttids;

		// sanity check ourselves
		if( empty( $search_ttids ))
		{
			return FALSE;
		}

		// find those term taxonomy IDs in the DB, turn them into an array of real term objects
		global $wpdb;
		$coincidences = array();
		foreach( (array) $wpdb->get_results('
			SELECT t.term_taxonomy_id , COUNT(*) AS hits
			FROM '. $wpdb->term_relationships .' t
			JOIN '. $wpdb->term_relationships .' p ON p.object_id = t.object_id
			WHERE p.term_taxonomy_id IN( '. implode( ',' , $search_ttids ) .' )
			AND t.term_taxonomy_id NOT IN( '. implode( ',' , $exclude_ttids ) .' )
			GROUP BY t.term_taxonomy_id
			ORDER BY hits DESC
			LIMIT 200
		') as $ttid )
		{
			$coincidences[ $ttid->term_taxonomy_id ] = $this->get_term_by_ttid( $ttid->term_taxonomy_id );
			$coincidences[ $ttid->term_taxonomy_id ]->count = $ttid->hits;
		}

		return $this->filter_terms_by_authority( $coincidences , $exclude_ttids );

	}//end get_related_terms_for_authority

	public function filter_terms_by_authority( $input_terms , $exclude_ttids = array() )
	{

		// sanity check
		if( ! is_array( $input_terms ) )
		{
			return FALSE;
		}

		// iterate through the array, lookup the authority
		$output_terms = array();
		foreach( $input_terms as $input_term )
		{
			// is there an authority record for this term?
			if( $authority = $this->get_term_authority( $input_term ) )
			{
				// have we already created an output term with this authority?
				if( ! isset( $output_terms[ $authority->primary_term->term_taxonomy_id ] ) )
				{
					$output_terms[ $authority->primary_term->term_taxonomy_id ] = $authority->primary_term;

					// override the count with that from the input terms for more accurate sorting
					$output_terms[ $authority->primary_term->term_taxonomy_id ]->count = $input_term->count;				
				}
				else
				{
					// take the highest count value
					// note: summing the counts leads to lies, results in double-counts and worse
					$output_terms[ $authority->primary_term->term_taxonomy_id ]->count = max( $input_term->count , $output_terms[ $authority->primary_term->term_taxonomy_id ]->count );				
				}

				// save the non-authoritative input term as an element inside this term
				if( $input_term->term_taxonomy_id != $authority->primary_term->term_taxonomy_id )
				{
					$output_terms[ $authority->primary_term->term_taxonomy_id ]->authority_synonyms[ $input_term->term_taxonomy_id ] = $input_term;
				}
			}
			// okay, does the input term at least smell like a real term?
			elseif( isset( $input_term->term_id , $input_term->taxonomy , $input_term->term_taxonomy_id , $input_term->count ))
			{
				$output_terms[ $input_term->term_taxonomy_id ] = get_term( $input_term->term_id , $input_term->taxonomy );
			}
		}

		if( ! empty( $exclude_ttids ))
		{
			$output_terms = array_diff_key( $output_terms , $exclude_ttids ); 
		}

		// sort the new term array
		usort( $output_terms, array( $this , '_sort_filtered_terms' ));

		return $output_terms;
	}

	public function _sort_filtered_terms( $a , $b )
	{
		// reverse compare so items with higher counts are at the top of the array
		return $a->count > $b->count ? -1 : 1;
	}
}//end Authority_Posttype class
