<?php

class Authority_Posttype_Tools extends Authority_Posttype
{
	public $stemmer_loaded = FALSE;

	public function __construct()
	{
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		add_action( 'wp_ajax_authority_enforce_authority', array( $this, 'enforce_authority_on_corpus_ajax' ));
		add_action( 'wp_ajax_authority_enforce_all_authority', array( $this, 'wp_ajax_authority_enforce_all_authority' ));
		add_action( 'wp_ajax_authority_create_authority_records', array( $this, 'create_authority_records_ajax' ));
		add_filter( 'wp_ajax_authority_spell_report', array( $this, 'spell_report_ajax' ) );
		add_filter( 'wp_ajax_authority_stem_report', array( $this, 'stem_report_ajax' ) );
		add_filter( 'wp_ajax_authority_term_report', array( $this, 'term_report_ajax' ) );
		add_filter( 'wp_ajax_authority_term_suffix_cleaner', array( $this, 'term_suffix_cleaner_ajax' ) );
		add_filter( 'wp_ajax_authority_update_term_counts', array( $this, 'update_term_counts_ajax' ) );
	}

	public function admin_menu()
	{
		add_submenu_page( 'edit.php?post_type=' . $this->post_type_name , 'Authority Record Tools' , 'Tools' , 'edit_posts' , $this->tools_page_id, array( $this , 'tools_page' ) );
	}

	public function tools_page()
	{
		include_once __DIR__ . '/templates/tools.php';
	}

	public function enforce_all_authority_url( $post_id, $authority_page = 0, $posts_per_page = 5, $page_num = 0 )
	{
		return admin_url('admin-ajax.php?action=authority_enforce_all_authority&authority_post_id='. (int) $post_id .'&authority_page=' . (int) $authority_page . '&posts_per_page='. (int) $posts_per_page .'&page_num='. (int) $page_num );
	}//end enforce_all_authority_url

	public function wp_ajax_authority_enforce_all_authority()
	{
		if ( ! current_user_can( 'manage_options' ) )
		{
			return FALSE;
		}
		
		$post_id        = (int) $_REQUEST['authority_post_id'];
		$posts_per_page = is_numeric( $_REQUEST['posts_per_page'] ) ? (int) $_REQUEST['posts_per_page'] : 50;
		$page_num       = is_numeric( $_REQUEST['page_num'] ) ? (int) $_REQUEST['page_num'] : 0;
		$authority_page = is_numeric( $_REQUEST['authority_page'] ) ? (int) $_REQUEST['authority_page'] : 0;

		// if a post isn't specified, grab one
		if ( ! $post_id )
		{
			$args = array(
				'posts_per_page' => 1,
				'paged'          => $authority_page,
				'post_type'      => $this->post_type_name,
				'post_status'    => 'publish',
				'order'          => 'DESC',
				'orderby'        => 'post_modified_gmt',
			);

			$query = new WP_Query( $args );

			if ( $query->have_posts() )
			{
				$query->the_post();

				$post_id = $query->post->ID;
			}//end if

			// if we can't get a new authority record, that means we're done
			if ( ! $post_id )
			{
				echo 'Complete!';
				die;
			}//end if
		}//end if

		if( $post_id && $this->get_post_meta( $post_id ) )
		{
			echo "<h2>Enforcing Authority on ID {$post_id} &mdash; Page {$page_num}</h2>";
			$result = $this->enforce_authority_on_corpus( $post_id, $posts_per_page, $page_num );
		}//end if

		// are we done with enforcing a single authority record?
		if ( ! is_object( $result ) || ! $result->next_paged )
		{
			// clear out the authority post ID to force a new authority post fetch
			$post_id = 0;
			$authority_page++;
		}//end if
		elseif ( is_object( $result ) )
		{
			echo "<p>Processed {$result->processed_count} record(s)</p>";
			echo '<code>' . print_r( $result->post_ids, TRUE ) . '</code>';
		}//end else
?>
<script>
window.location = "<?php echo $this->enforce_all_authority_url( $post_id, $authority_page, $posts_per_page, $result->next_paged ); ?>";
</script>
<?php

		die;
	}//end wp_ajax_authority_enforce_all_authority

	public function enforce_authority_on_corpus_url( $post_id , $posts_per_page = 5 , $paged = 0 )
	{
		return admin_url('admin-ajax.php?action=authority_enforce_authority&authority_post_id='. (int) $post_id .'&posts_per_page='. (int) $posts_per_page .'&paged='. (int) $paged );
	}

	public function enforce_authority_on_corpus_ajax()
	{
		if ( ! current_user_can( 'manage_options' ) )
		{
			return FALSE;
		}

		if( $_REQUEST['authority_post_id'] && $this->get_post_meta( (int) $_REQUEST['authority_post_id'] ))
			$result = $this->enforce_authority_on_corpus(
				(int) $_REQUEST['authority_post_id'] ,
				( is_numeric( $_REQUEST['posts_per_page'] ) ? (int) $_REQUEST['posts_per_page'] : 50 ) ,
				( is_numeric( $_REQUEST['paged'] ) ? (int) $_REQUEST['paged'] : 0 )
		);

		print_r( $result );

		if( is_object( $result ) && $result->next_paged )
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
		if ( isset( $authority['parent_terms'] ) )
		{
			foreach( (array) $authority['parent_terms'] as $term )
			{
				$add_terms[ $term->taxonomy ][] = (int) $term->term_id;
			}
		}//end if

		// section of terms to delete from each post
		// create a list of terms to delete from each post
		$delete_terms = array();

		// delete alias terms that are not in the same taxonomy as the primary term
		if ( isset( $authority['alias_terms'] ) )
		{
			foreach( $authority['alias_terms'] as $term )
			{
				if( $term->taxonomy != $authority['primary_term']->taxonomy )
				{
					$delete_taxs[ $term->taxonomy ] = $term->taxonomy;
					$delete_tt_ids[] = (int) $term->term_taxonomy_id;
				}
			}
		}//end if

		// Section of terms to search by
		// create a list of terms to search for posts by
		$search_terms = array();

		// include the primary term among those used to fetch posts
		$search_terms[ $authority['primary_term']->taxonomy ][] = (int) $authority['primary_term']->term_id;

		// add alias terms in the list
		if ( isset( $authority['alias_terms'] ) )
		{
			foreach( $authority['alias_terms'] as $term )
			{
				$search_terms[ $term->taxonomy ][] = (int) $term->term_id;
			}
		}//end if

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
		{
			return FALSE;
		}

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
		if ( ! current_user_can( 'manage_options' ) )
		{
			return FALSE;
		}

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
window.location = "<?php echo admin_url('admin-ajax.php?action=authority_create_authority_records&old_tax='. $_REQUEST['old_tax'] .'&new_tax='. $_REQUEST['new_tax'] .'&paged='. $result->next_paged .'&posts_per_page='. (int) $_REQUEST['posts_per_page']); ?>";
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

		return( (object) array( 'post_ids' => $post_ids , 'total_count' => $total_count ,'processed_count' => ( 1 + $paged ) * $posts_per_page , 'next_paged' => ( count( $post_ids ) == $posts_per_page ? 1 + $paged : FALSE ) ));
	}

	public function spell( $word, $azure_datamarket_key, $verbose = FALSE )
	{
		$word = trim( $word );

		$cachekey = md5( $word );
		if ( ! $suggestion = wp_cache_get( $cachekey, 'scriblio_authority_spell' ) )
		{
			// initialize the result object
			$suggestion = (object) array( 'text' => NULL, 'status' => FALSE );

			$suggestions_json = wp_remote_get( 
				sprintf(
					'https://ignored:%1$s@api.datamarket.azure.com/Bing/Search/v1/SpellingSuggestions?$format=json%2$s&Query=%3$s',
					$azure_datamarket_key,
					'&Options=%27EnableHighlighting%27&Adult=%27Off%27',
					urlencode( '\'' . $word . '\'' )
				),
				array( 'timeout' => 2 )
			);
			$suggestions_json = wp_remote_retrieve_body( $suggestions_json );

			// detect some API failures and JSON decode errors
			if ( ! ( $suggestions_from_api = json_decode( $suggestions_json ) ) || ! is_object( $suggestions_from_api ) )
			{
				$suggestion->status = 'failed_connection_error';
			}

			// check for the response array we expect
			if (
				! isset( $suggestions_from_api->d->results ) ||
				! is_array( $suggestions_from_api->d->results )
			)
			{
				$suggestion->status = 'failed_data_unreadable';
			}

			// not sure if the API supports multiple suggestions for a single query, but this code only supports one
			if ( isset( $suggestions_from_api->d->results[0]->Value ) )
			{
				$suggestion->text = trim( $suggestions_from_api->d->results[0]->Value );
			}

			// check to see if the suggested text is the same as the provided text
			if (
				empty( $suggestion->text ) ||
				strtolower( $suggestion->text ) == strtolower( $word ) )
			{
				$suggestion->status = 'no_suggestion';
			}
			else
			{
				$suggestion->status = 'suggestion';
			}

			wp_cache_set( $cachekey, $suggestion, 'scriblio_authority_spell', 0 );
		}

		// return the object if verbose is desired, or the string if not
		if ( $verbose )
		{
			return $suggestion;
		}
		else
		{
			return $suggestion->text;
		}
	}

	public function spell_report_ajax()
	{
		// example URL: https://site.org/wp-admin/admin-ajax.php?action=authority_spell_report&key=PASTE_YOUR_AZURE_DATAMARKET_KEY_HERE

		if ( ! current_user_can( 'edit_posts' ))
		{
			wp_die( 'Whoa, not cool', 'Not authorized' );
		}

		if ( ! isset( $_GET['key'] ) || 'PASTE_YOUR_AZURE_DATAMARKET_KEY_HERE' == $_GET['key'] )
		{
			wp_die( 'Please set your <a href="http://datamarket.azure.com/dataset/bing/search">Bing Search</a> <a href="http://datamarket.azure.com">Windows Azure Marketplace</a> <a href="https://datamarket.azure.com/account/keys">account key</a> in the URL. You might also want to <a href="https://datamarket.azure.com/dataset/explore/bing/search">check your available transactions</a> before continuing.', 'Azure account key missing' );
		}

		// this can use a lot of memory and time
		ini_set( 'memory_limit', '1024M' );
		set_time_limit( 900 );

		// set the columns for the report
		$columns = array(
			'term_id',
			'term',
			'suggestion',
			'suggestion_exists',
			'authority_status',
			'slug',
			'count',
			'taxonomy',
		);

		// get the CSV class
		$csv = new_authority_csv( 'spell-report-'. date( 'r' ) , $columns );

		// prepare and execute the query
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT t.name , t.term_id, t.slug, GROUP_CONCAT( tt.taxonomy ) AS taxonomies, SUM( tt.count ) AS hits
			FROM $wpdb->terms t
			JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
			GROUP BY t.term_id
			/* generated in Authority_Posttype_Tools::spell_report_ajax() */
		" );
		$terms = $wpdb->get_results( $query );

		// iterate through the results and output each row as CSV
		foreach( $terms as $term )
		{
			// each iteration increments the time limit just a bit (until we run out of memory)
			set_time_limit( 15 );

			// get the spelling suggestions
			$spell =  $this->spell( $term->name, $_GET['key'], TRUE );

			// continue to the next term if there's no suggestion
			if ( 'suggestion' != $spell->status )
			{
				continue;
			}

			foreach( explode( ',', $term->taxonomies ) as $taxonomy )
			{
				// get a proper term object
				$term_object = get_term( $term->term_id, $taxonomy );

				// check if there's an authority record
				$authority = $this->get_term_authority( $term_object );

				if ( isset( $authority->primary_term ) && ( sanitize_title_with_dashes( $term_object->slug ) == $authority->primary_term->slug ) )
				{
					$status = 'prime';
				}
				elseif ( isset( $authority->primary_term ) )
				{
					$status = 'alias';
				}
				else
				{
					$status = '';
				}

				$csv->add( array(
					'term_id' => $term->term_id,
					'term' => html_entity_decode( $term->name ),
					'suggestion' => html_entity_decode( $spell->text ),
					'suggestion_exists' => term_exists( $spell->text ),
					'authority_status' => $status,
					'slug' => $term->slug,
					'count' => $term->hits,
					'taxonomy' => $taxonomy
				) );

				sleep( 1 );
			}
		}

		die;
	}

	public function stem( $word )
	{
		if ( ! $this->stemmer_loaded )
		{
			require_once __DIR__ . '/externals/class-porterstemmer.php';
			$this->stemmer_loaded = TRUE;
		}

		return PorterStemmer::Stem( $word );
	}

	public function stem_report_ajax()
	{
		// example URL: https://site.org/wp-admin/admin-ajax.php?action=authority_stem_report

		if( ! current_user_can( 'edit_posts' ))
		{
			return;
		}

		// this can use a lot of memory and time
		ini_set( 'memory_limit', '1024M' );
		set_time_limit( 900 );

		// set the columns for the report
		$columns = array(
			'stem',
			'rstem',
			'term',
			'authority_status',
			'slug',
			'count',
			'taxonomy',
			'term_id',
		);

		// get the CSV class
		$csv = new_authority_csv( 'stem-report-'. date( 'r' ) , $columns );

		// prepare and execute the query
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT t.name , t.term_id, t.slug, GROUP_CONCAT( tt.taxonomy ) AS taxonomies, SUM( tt.count ) AS hits
			FROM $wpdb->terms t
			JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
			GROUP BY t.term_id
			/* generated in Authority_Posttype_Tools::stem_report_ajax() */
		" );
		$terms = $wpdb->get_results( $query );

		// iterate through the results and output each row as CSV
		foreach( $terms as $term )
		{
			// each iteration increments the time limit just a bit (until we run out of memory)
			set_time_limit( 15 );

			foreach( str_word_count( strtolower( trim( $term->name ) ), 1 ) as $word )
			{
				foreach( explode( ',', $term->taxonomies ) as $taxonomy )
				{
					// get a proper term object
					$term_object = get_term( $term->term_id, $taxonomy );

					// check if there's an authority record
					$authority = $this->get_term_authority( $term_object );

					if ( isset( $authority->primary_term ) && ( sanitize_title_with_dashes( $term_object->slug ) == $authority->primary_term->slug ) )
					{
						$status = 'prime';
					}
					elseif ( isset( $authority->primary_term ) )
					{
						$status = 'alias';
					}
					else
					{
						$status = '';
					}

					$csv->add( array(
						'stem' => $this->stem( $word ),
						'rstem' => strrev( $this->stem( $word ) ),
						'term' => html_entity_decode( $term->name ),
						'authority_status' => $status,
						'slug' => $term->slug,
						'count' => $term->hits,
						'taxonomy' => $taxonomy,
						'term_id' => $term->term_id
					));
				}
			}
		}

		die;
	}

	public function term_report_ajax()
	{
		// example URL: https://site.org/wp-admin/admin-ajax.php?action=authority_term_report&taxonomy=post_tag

		if( ! current_user_can( 'edit_posts' ))
		{
			return;
		}

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
			/* generated in Authority_Posttype_Tools::term_report_ajax() */
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
		{
			return;
		}

		// don't bother updating term counts yet, it'll just slow us down and we have so much to do
		wp_defer_term_counting( TRUE );

		$taxonomies = authority_record()->taxonomies;

		// bail if there aren't any registered taxonomies
		if ( ! $taxonomies )
		{
			echo "There aren't any registered taxonomies to clean.";
			die;
		}//end if

		// prepare and execute the query
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT *
			FROM (
				SELECT *
				FROM $wpdb->terms
				WHERE 1=1
				AND slug REGEXP '-([0-9]*)$'
				AND name NOT REGEXP '[0-9]$'
			) as t
			JOIN $wpdb->term_taxonomy tt
				ON tt.term_id = t.term_id
			 AND tt.taxonomy IN ('" . implode( "','", $taxonomies ) . "')
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

		// know when to stop
		die;
	}

	public function update_term_counts_ajax()
	{
		if ( ! current_user_can( 'manage_options' ) )
		{
			return FALSE;
		}

		$this->_update_term_counts();
		die;
	}
}//end Authority_Posttype_Tools class
