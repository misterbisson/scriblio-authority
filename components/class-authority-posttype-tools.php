<?php

class Authority_Posttype_Tools extends Authority_Posttype
{
	public function __construct()
	{
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		add_action( 'wp_ajax_scrib_enforce_authority', array( $this, 'enforce_authority_on_corpus_ajax' ));
		add_action( 'wp_ajax_scrib_create_authority_records', array( $this, 'create_authority_records_ajax' ));
		add_filter( 'wp_ajax_scrib_term_report', array( $this, 'term_report_ajax' ) );
		add_filter( 'wp_ajax_scrib_term_suffix_cleaner', array( $this, 'term_suffix_cleaner_ajax' ) );
	}

	public function admin_menu()
	{
		add_submenu_page( 'edit.php?post_type=' . $this->post_type_name , 'Authority Record Tools' , 'Tools' , 'edit_posts' , $this->tools_page_id, array( $this , 'tools_page' ) );
	}

	public function tools_page()
	{
		include_once __DIR__ . '/templates/tools.php';
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

}//end Authority_Posttype_Tools class