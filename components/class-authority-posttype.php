<?php
class Authority_Posttype {

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
			$this->admin_class = new Authority_Posttype_Admin;
			$this->admin_class->plugin_url = $this->plugin_url;

			require_once dirname( __FILE__ ) . '/class-authority-posttype-tools.php';
			$this->tools_class = new Authority_Posttype_Tools;

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

		// return the permalink for the primary term
		return get_term_link( (int) $authority->primary_term->term_id , $authority->primary_term->taxonomy );

	}//end post_link

	/**
	 * Check if an authority record has an alias.
	 *
	 * @param $term_authority The term authority record to check, as return by get_term_authority()
	 * @param $alias_term The alias term to check
	 * @return boolean
	 */
	public function authority_has_alias( $term_authority, $alias_term )
	{
		if( ! is_array( $term_authority->alias_terms ) )
		{
			return false;
		}

		foreach( $term_authority->alias_terms as $term )
		{
			if( $term->term_id == $alias_term->term_id )
			{
				return true;
			}
		}

		return false;
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
				if( isset( $this->admin_class ))
				{
					remove_action( 'save_post', array( $this->admin_class , 'save_post' ));
					wp_insert_post( $post );
					add_action( 'save_post', array( $this->admin_class , 'save_post' ));				
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
				'register_meta_box_cb' => array( $this->admin_class , 'metaboxes' ),
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
		if( wp_is_post_revision( $post_id ))
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

	public function enforce_authority_on_corpus_url( $post_id , $posts_per_page = 5 , $paged = 0 )
	{
		return admin_url('admin-ajax.php?action=scrib_enforce_authority&authority_post_id='. (int) $post_id .'&posts_per_page='. (int) $posts_per_page .'&paged='. (int) $paged );

	}

	public function enforce_authority_on_corpus_ajax()
	{
		if( $_REQUEST['authority_post_id'] && $this->get_post_meta( (int) $_REQUEST['authority_post_id'] ))
			$result = $this->enforce_authority_on_corpus(
				(int) $_REQUEST['authority_post_id'] ,
				( is_numeric( $_REQUEST['posts_per_page'] ) ? (int) $_REQUEST['posts_per_page'] : 50 ) ,
				( is_numeric( $_REQUEST['paged'] ) ? (int) $_REQUEST['paged'] : 0 )
		);

		print_r( $result );

		if( $result->next_paged )
		{
?>
<script type="text/javascript">
window.location = "<?php echo $this->enforce_authority_on_corpus_url( $_REQUEST['authority_post_id'] , $_REQUEST['posts_per_page'] , $result->next_paged ); ?>";
</script>
<?php
		}

		die;
	}

	public function enforce_authority_on_corpus( $authority_post_id , $posts_per_page = 50 , $paged = 0 )
	{
		$authority = $this->get_post_meta( $authority_post_id );

		// section of terms to add to each post
		// create a list of terms to add to each post
		$add_terms = array();

		// add the primary term to all posts (yes, it's likely already attached to some posts)
		$add_terms[ $authority['primary_term']->taxonomy ][] = (int) $authority['primary_term']->term_id;

		// add parent terms to all posts (yes, they may already be attached to some posts)
		foreach( (array) $authority['parent_terms'] as $term )
			$add_terms[ $term->taxonomy ][] = (int) $term->term_id;



		// section of terms to delete from each post
		// create a list of terms to delete from each post
		$delete_terms = array();

		// delete alias terms that are not in the same taxonomy as the primary term
		foreach( $authority['alias_terms'] as $term )
		{
			if( $term->taxonomy != $authority['primary_term']->taxonomy )
			{
				$delete_taxs[ $term->taxonomy ] = $term->taxonomy;
				$delete_tt_ids[] = (int) $term->term_taxonomy_id;
			}
		}

		// Section of terms to search by
		// create a list of terms to search for posts by
		$search_terms = array();

		// include the primary term among those used to fetch posts
		$search_terms[ $authority['primary_term']->taxonomy ][] = (int) $authority['primary_term']->term_id;

		// add alias terms in the list
		foreach( $authority['alias_terms'] as $term )
			$search_terms[ $term->taxonomy ][] = (int) $term->term_id;

		// get post types, exclude this post type
		$post_types = get_post_types( array( 'public' => TRUE ));
		unset( $post_types[ $this->post_type_name ] );

		// do each taxonomy as a separate query to limit the complexity of each query
		$post_ids = array();
		foreach( $search_terms as $k => $v )
		{
			$tax_query = array( 'relation' => 'OR' );
			$tax_query[] = array(
				'taxonomy' => $k,
				'field' => 'id',
				'terms' => $v,
				'operator' => 'IN',
			);

			// construct a complete query
			$query = array(
				'posts_per_page' => (int) $posts_per_page,
				'paged' => (int) $paged,
				'post_type' => $post_types,
				'tax_query' => $tax_query,
				'fields' => 'ids',
			);

			// get a batch of posts
			$post_ids = array_merge( $post_ids , get_posts( $query ));
		}

		if( ! count( $post_ids ))
			return FALSE;

		$post_ids = array_unique( $post_ids );

		foreach( (array) $post_ids as $post_id )
		{

			// add all the terms, one taxonomy at a time
			foreach( (array) $add_terms as $k => $v )
				wp_set_object_terms( $post_id , $v , $k , TRUE );

			// get currently attached terms in preparation for deleting some of them
			$new_object_tt_ids = $delete_object_tt_ids = array();
			$new_object_terms = wp_get_object_terms( $post_id , $delete_taxs );
			foreach( $new_object_terms as $new_object_term )
				$new_object_tt_ids[] = $new_object_term->term_taxonomy_id;

			// actually delete any conflicting terms
			if( $delete_object_tt_ids = array_intersect( (array) $new_object_tt_ids , (array) $delete_tt_ids ))
				$this->delete_terms_from_object_id( $post_id , $delete_object_tt_ids );
		}

		$this->_update_term_counts();

		return( (object) array( 'post_ids' => $post_ids , 'processed_count' => ( 1 + $paged ) * $posts_per_page , 'next_paged' => ( count( $post_ids ) >= $posts_per_page ? 1 + $paged : FALSE ) ));
	}

	public function create_authority_record( $primary_term , $alias_terms )
	{

		// check primary term
		if( ! get_term( (int) $primary_term->term_id , $primary_term->taxonomy ))
			return FALSE;

		// check that there's no prior authority
		if( $this->get_term_authority( $primary_term ))
			return $this->get_term_authority( $primary_term )->post_id;

		$post = (object) array(
			'post_title' => $primary_term->name,
			'post_status' => 'publish',
			'post_name' => $primary_term->slug,
			'post_type' => $this->post_type_name,
		);

		$post_id = wp_insert_post( $post );

		if( ! is_numeric( $post_id ))
			return $post_id;

		$instance = array();

		// primary term meta
		$instance['primary_term'] = $primary_term;

		// create the meta for the alias terms
		foreach( $alias_terms as $term )
		{
			// it's totally not cool to insert the primary term as an alias
			if( $term->term_taxonomy_id == $instance['primary_term']->term_taxonomy_id )
				continue;

			$instance['alias_terms'][] = $term;
		}

		// save it
		$this->update_post_meta( $post_id , $instance );

		return $post_id;
	}

	public function create_authority_records_ajax()
	{
		// validate the taxonomies
		if( ! ( is_taxonomy( $_REQUEST['old_tax'] ) && is_taxonomy( $_REQUEST['new_tax'] )))
			return FALSE;

		$result = $this->create_authority_records(
			$_REQUEST['old_tax'] ,
			$_REQUEST['new_tax'] ,
			( is_numeric( $_REQUEST['posts_per_page'] ) ? (int) $_REQUEST['posts_per_page'] : 5 ) ,
			( is_numeric( $_REQUEST['paged'] ) ? (int) $_REQUEST['paged'] : 0 )
		);

		print_r( $result );

		if( $result->next_paged )
		{
?>
<script type="text/javascript">
window.location = "<?php echo admin_url('admin-ajax.php?action=scrib_create_authority_records&old_tax='. $_REQUEST['old_tax'] .'&new_tax='. $_REQUEST['new_tax'] .'&paged='. $result->next_paged .'&posts_per_page='. (int) $_REQUEST['posts_per_page']); ?>";
</script>
<?php
		}

		die;
	}

	// find terms that exist in two named taxonomies, update posts that have the old terms to have the new terms, then delete the old term
	public function create_authority_records( $old_tax , $new_tax , $posts_per_page = 5 , $paged = 0)
	{
		global $wpdb;

		// validate the taxonomies
		if( ! ( is_taxonomy( $old_tax ) && is_taxonomy( $new_tax )))
			return FALSE;

		// get the new and old terms
		$new_terms = $wpdb->get_col( $wpdb->prepare( 'SELECT term_id
			FROM '. $wpdb->term_taxonomy .'
			WHERE taxonomy = %s
			ORDER BY term_id
			',
			$new_tax
		));

		$old_terms = $wpdb->get_col( $wpdb->prepare( 'SELECT term_id
			FROM '. $wpdb->term_taxonomy .'
			WHERE taxonomy = %s
			ORDER BY term_id
			',
			$old_tax
		));

		// find parallel terms and get just a slice of them
		$intersection = array_intersect( $new_terms , $old_terms );
		$total_count = count( (array) $intersection );
		$intersection = array_slice( $intersection , (int) $paged * (int) $posts_per_page , (int) $posts_per_page );

		foreach( $intersection as $term_id )
		{
			$old_term = get_term( (int) $term_id , $old_tax );
			$new_term = get_term( (int) $term_id , $new_tax );

			if( $authority = $this->get_term_authority( $old_term )) // the authority record already exists for this term
			{
				$post_ids[] = $post_id = $authority->post_id;
			}
			else // no authority record exists, create one and enforce it on the corpus
			{
				$post_ids[] = $post_id = $this->create_authority_record( $new_term , array( $old_term ));
			}

			// enforce the authority on the corpus
			$this->enforce_authority_on_corpus( (int) $post_id , -1 );

		}


		$this->_update_term_counts();

		return( (object) array( 'post_ids' => $post_ids , 'total_count' => $total_count ,'processed_count' => ( 1 + $paged ) * $posts_per_page , 'next_paged' => ( count( $post_ids ) == $posts_per_page ? 1 + $paged : FALSE ) ));
	}

	public function term_report_ajax()
	{
		// example URL: https://site.org/wp-admin/admin-ajax.php?action=scrib_term_report&taxonomy=post_tag

		if( ! current_user_can( 'edit_posts' ))
			return;

		// this can use a lot of memory and time
		ini_set( 'memory_limit', '1024M' );
		set_time_limit( 900 );

		// sanitize the taxonomy we're reporting on
		$taxonomy = taxonomy_exists( $_GET['taxonomy'] ) ? $_GET['taxonomy'] : 'post_tag';

		// set the columns for the report
		$columns = array(
			'term',
			'slug',
			'count',
			'status',
			'authoritative_term',
			'alias_terms',
			'parent_terms',
			'child_terms',
			'edit_term',
			'edit_authority_record',
		);

		// get the CSV class
		$csv = new_authority_csv( $taxonomy .'-'. date( 'r' ) , $columns );

		// prepare and execute the query
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT t.name , t.term_id , t.slug , tt.taxonomy , tt.term_taxonomy_id , tt.count
			FROM $wpdb->term_taxonomy tt
			JOIN $wpdb->terms t ON t.term_id = tt.term_id
			WHERE taxonomy = %s
			AND tt.count > 0
			ORDER BY tt.count DESC
			LIMIT 3000
		" , $taxonomy );
		$terms = $wpdb->get_results( $query );

		// iterate through the results and output each row as CSV
		foreach( $terms as $term )
		{
			// each iteration increments the time limit just a bit (until we run out of memory)
			set_time_limit( 15 );

			$status = $primary = $aliases = $parents = $children = array();

			$authority = $this->get_term_authority( $term );

			if( isset( $authority->primary_term ) && ( $authority->primary_term->term_taxonomy_id == $term->term_taxonomy_id ))
			{
				$status = 'prime';
			}
			elseif( isset( $authority->primary_term ))
			{
				$status = 'alias';
			}
			else
			{
				$status = '';
			}

			$primary = isset( $authority->primary_term ) ? $authority->primary_term->taxonomy .':'. $authority->primary_term->slug : '';

			foreach( (array) $authority->alias_terms as $_term )
				$aliases[] = $_term->taxonomy .':'. $_term->slug;

			foreach( (array) $authority->parent_terms as $_term )
				$parents[] = $_term->taxonomy .':'. $_term->slug;

			foreach( (array) $authority->child_terms as $_term )
				$children[] = $_term->taxonomy .':'. $_term->slug;

			$csv->add( array(
				'term' => html_entity_decode( $term->name ),
				'slug' => $term->slug,
				'count' => $term->count,
				'status' => $status,
				'authoritative_term' => $primary,
				'alias_terms' => implode( ', ' , (array) $aliases ),
				'parent_terms' => implode( ', ' , (array) $parents ),
				'child_terms' => implode( ', ' , (array) $children ),
				'edit_term' => get_edit_term_link( $term->term_id, $term->taxonomy ),
				'edit_authority_record' => get_edit_post_link( (int) $authority->post_id , '' ),
			));
		}

		die;
	}

	public function term_suffix_cleaner_ajax()
	{
		if( ! current_user_can( 'manage_options' ))
			return;

		// don't bother updating term counts yet, it'll just slow us down and we have so much to do
		wp_defer_term_counting( TRUE );

		// prepare and execute the query
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT *
			FROM(
				SELECT *
				FROM $wpdb->terms
				WHERE 1=1
				AND slug REGEXP '-([0-9]*)$'
				AND name NOT REGEXP '[0-9]$'
			) as t
			JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id
			ORDER BY t.name, tt.term_taxonomy_id
		");
		$terms = $wpdb->get_results( $query );

		// don't bother if we have no terms
		if( ! count( (array) $terms ))
		{
			echo 'Yay, no ugly suffixed terms found!';
			die;
		}

		foreach( (array) $terms as $term )
		{
			// get a clean version of the term slug without a numeric suffix
			$clean_slug = sanitize_title( $term->name );

			// check to see if there's an existing term taxonomy record for the clean term
			if( $alternate_term = get_term_by( 'slug' , $clean_slug , $term->taxonomy ))
			{
				echo '<h3>Other term_taxonomy_record fount for  '. $term->slug .': '. $alternate_term->slug .'</h3>';
				$alternate_term_id = (int) $alternate_term->term_id;

				// get all the posts with the ugly term, update them with the clean term
				$posts = get_objects_in_term( $term->term_id , $term->taxonomy );
				echo '<p>Updating '. count( $posts ) .' posts:</p><ul>';
				foreach( $posts as $post_id )
				{
					wp_set_object_terms( $post_id, $alternate_term->term_id, $term->taxonomy, TRUE );
					echo '<li>Updated post id <a href="'. get_edit_post_link( $post_id ) .'">'. $post_id .'</a> with term '. $clean_slug .'</li>';
				}
				echo '</ul>';

				// be tidy
				wp_delete_term( $term->term_id, $term->taxonomy );
			}
			// okay, lets now see if the clean term exists in the term table at all and update the existing term taxonomy record with it
			else if( $alternate_term_id = (int) term_exists( $clean_slug ))
			{
				echo '<h3>Reassigning term_taxonomy record from '. $term->slug .' to  '. $clean_slug .'</h3>';

				$query = $wpdb->prepare( "
					UPDATE $wpdb->term_taxonomy AS tt
					SET tt.term_id = %d
					WHERE tt.term_taxonomy_id = %d
				" , $alternate_term_id , $term->term_taxonomy_id );
				$wpdb->get_results( $query );
				clean_term_cache( $term->term_id , $term->taxonomy );
			}
			// crap, didn't find a clean term, how did we get here?
			else
			{
				echo '<h3>No alternate found for '. $term->slug .'</h3>';
				continue;
			}
		}

		// be courteous
		$this->_update_term_counts();

		// know when to stop
		die;
	}

}//end Authority_Posttype class