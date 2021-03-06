<?php
/*

This class includes the admin UI components and metaboxes, and the supporting methods they require.

*/

class Authority_Posttype_Admin extends Authority_Posttype
{
	public function __construct()
	{
		add_action( 'save_post', array( $this , 'save_post' ));

		add_action( 'admin_enqueue_scripts', array( $this , 'enqueue_scripts' ) );

		add_action( "manage_{$this->post_type_name}_posts_custom_column", array( $this, 'column' ), 10 , 2 );
		add_filter( "manage_{$this->post_type_name}_posts_columns" , array( $this, 'columns' ) , 11 );
	}

	public function enqueue_scripts()
	{
		wp_enqueue_style( 'scrib-authority' );
		wp_enqueue_script( 'scrib-authority' );
		wp_enqueue_script( 'scrib-authority-behavior' );
	}//end enqueue_scripts

	public function parse_terms_from_string( $text )
	{
		$terms = array();
		$blob = array_map( 'trim' , (array) explode( ',' , $text ));
		if( count( (array) $blob ))
		{
			foreach( (array) $blob as $blobette )
			{
				if( empty( $blobette ) )
				{
					continue;
				}

				// this is part of an workaround for terms with . in them
				$found = FALSE;

				// split the taxonomies and terms apart
				$parts = array_map( 'trim' , (array) explode( ':' , $blobette ));

				// allow a sloppy tag taxonomy
				if( 'tag' == $parts[0] ) // parts[0] is the taxonomy
					$parts[0] = 'post_tag';

				// find or insert the term
				if( $term = get_term_by( 'slug' , $parts[1] , $parts[0] ))
				{
					$terms[] = $term;
					$found = TRUE;
				}

				// test again for the term without the .
				// this is an ugly hack for a problem with matching terms with . in them
				// problem is twofold: old sanitization rules stripped . , rather than replacing it with - , as they do now
				// and terms are being submitted as names, rather than slugs or IDs
				if( 
					preg_match( '/\./' , $parts[1] ) &&
					( $term = get_term_by( 'slug' , preg_replace( '/\./' , '' , $parts[1] ) , $parts[0] )))
				{
					$terms[] = $term;
					$found = TRUE;
				}

				// as a last resort, create a new term
				if( ! $found )
				{
					// Ack! It's impossible to associate an existing term with a new taxonomy!
					// wp_insert_term() will always generate a new term with an ugly slug

					// insert the new term
					if(( $_new_term = wp_insert_term( $parts[1] , $parts[0] )) && is_array( $_new_term ))
					{
						$new_term = $this->get_term_by_ttid( $_new_term['term_taxonomy_id'] );
						$terms[] = $new_term;
					}
				}
			}
		}

		return $terms;
	}

	public function nonce_field()
	{
		wp_nonce_field( plugin_basename( __FILE__ ) , $this->id_base .'-nonce' );
	}

	public function verify_nonce()
	{
		return wp_verify_nonce( $_POST[ $this->id_base .'-nonce' ] , plugin_basename( __FILE__ ));
	}

	public function get_field_name( $field_name )
	{
		return $this->id_base . '[' . $field_name . ']';
	}

	public function get_field_id( $field_name )
	{
		return $this->id_base . '-' . $field_name;
	}
	public function metab_primary_term( $post )
	{
		$this->nonce_field();

		$this->instance = $this->get_post_meta( $post->ID );

		$taxonomies = authority_record()->simple_authority_taxonomies();
		$primary_term = array();
		$json = array();
		if ( isset( $this->instance['primary_term']->term_id ) )
		{
			$primary_term[ $this->instance['primary_term']->term_taxonomy_id ] = $this->instance['primary_term']->taxonomy .':'. $this->instance['primary_term']->slug;

			// check for any conflicts with this term
			$authority_conflicts = array();
			$authority_check = $this->get_term_authority( $this->instance['primary_term'] );
			if ( isset( $authority_check->conflict_ids ) )
			{
				$authority_conflicts[] = (object) array(
					'term' => $this->instance['primary_term'],
					'post_ids' => $authority_check->conflict_ids,
				);
			}

			// add this to the JS var of terms already on the record
			$json[] = array(
				'taxonomy' => $taxonomies[ $this->instance['primary_term']->taxonomy ],
				'term' => $this->instance['primary_term']->name,
				'data' => array(
					'term' => "{$this->instance['primary_term']->taxonomy}:{$this->instance['primary_term']->slug}",
				),
			);
		}//end if

		?>
		<script>
			ajaxurl = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
			if ( ! scrib_authority_data ) {
				var scrib_authority_data = {};
			}//end if

			if ( ! scrib_authority_taxonomies ) {
				var scrib_authority_taxonomies = <?php echo json_encode( $taxonomies ); ?>;
			}//end if

			scrib_authority_data['primary'] = <?php echo json_encode( $json ); ?>;
		</script>
		<label class="" for="<?php echo $this->get_field_id( 'primary_term' ); ?>">The primary term is the authoritative way to reference this thing or concept</label><textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'primary_term' ); ?>" id="<?php echo $this->get_field_id( 'primary_term' ); ?>"><?php echo implode( ', ' , (array) $primary_term ); ?></textarea>

		<?php
		if ( isset( $this->instance['primary_term']->term_id ) )
		{
			?>
			(<a href="<?php echo get_edit_term_link( $this->instance['primary_term']->term_id , $this->instance['primary_term']->taxonomy );; ?>">edit term</a>)
			<?php
		}
	}

	public function metab_alias_terms( $post )
	{
		$taxonomies = array();
		$taxonomy_objects = authority_record()->supported_taxonomies();
		foreach( $taxonomy_objects as $key => $taxonomy )
		{
			if( 'post_format' == $key)
			{
				continue;
			}//end if

			$taxonomies[ $key ] = authority_record()->simplify_taxonomy_for_json( $taxonomy );
		}//end foreach

		$aliases = array();
		$json = array();
		if ( isset( $this->instance['alias_terms'] ) && is_array( $this->instance['alias_terms'] ) )
		{
			$authority_conflicts = array();
			foreach( $this->instance['alias_terms'] as $term )
			{
				// make sure this is a working taxonomy
				if ( ! $taxonomies[ $term->taxonomy ] )
				{
					continue;
				}//end if

				$aliases[ $term->term_taxonomy_id ] = $term->taxonomy .':'. $term->slug;

				// check for any conflicts with this term
				$authority_check = $this->get_term_authority( $term );
				if ( isset( $authority_check->conflict_ids ) )
				{
					$authority_conflicts[] = (object) array(
						'term' => $term,
						'post_ids' => $authority_check->conflict_ids,
					);
				}

				// add this to the JS var of terms already on the record
				$json[] = array(
					'taxonomy' => $taxonomies[ $term->taxonomy ],
					'term' => $term->name,
					'data' => array(
						'term' => "{$term->taxonomy}:{$term->slug}",
					),
				);
			}//end foreach
		}//end if
?>
		<script>
			if ( ! scrib_authority_data ) {
				var scrib_authority_data = {};
			}//end if

			scrib_authority_data['alias'] = <?php echo json_encode( $json ); ?>;
		</script>
		<label class="" for="<?php echo $this->get_field_id( 'alias_terms' ); ?>">Alias terms are synonyms of the primary term</label><textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'alias_terms' ); ?>" id="<?php echo $this->get_field_id( 'alias_terms' ); ?>"><?php echo implode( ', ' , (array) $aliases ); ?></textarea>

		<?php
		if( count( $authority_conflicts ))
		{
			echo '<h4>Ambiguous term warning!</h4><ul>';
			foreach( $authority_conflicts as $conflict )
			{
				echo '<li>&#147;'. $conflict->term->name .'&#148; is referenced in '. ( count( $conflict->post_ids ) - 1 ) .' additional authority records<ol>';

				foreach( $conflict->post_ids as $conflict_post_id )
				{
					if( $post->ID == $conflict_post_id )
					{
						continue;
					}

					echo '<li><a href="'. get_edit_post_link( $conflict_post_id ) .'">'.  get_the_title( $conflict_post_id ) .'</a></li>';
				}
				echo '</ol></li>';
			}
			echo '</ul>';
		}
	}

	public function _metab_family_prep( $which, $collection )
	{
		$return = new stdClass;
		$return->data = array();
		$return->detail = array();
		$key = $which . '_terms';

		if ( ! isset( $collection[ $key ] ) )
		{
			$collection[ $key ] = array();
		}//end if

		foreach ( $collection[ $key ] as $term )
		{
			$return->data[ $term->term_taxonomy_id ] = "{$term->taxonomy}:{$term->slug}";
			$tax = get_taxonomy( $term->taxonomy );
			$return->detail[] = array(
				'taxonomy' => authority_record()->simplify_taxonomy_for_json( $tax ),
				'term' => $term->name,
				'data' => array(
					'term' => "{$term->taxonomy}:{$term->slug}",
				),
			);
		}//end foreach

		return $return;
	}//end metab_family_prep

	public function metab_family_terms( $post )
	{
		$children_prep = $this->_metab_family_prep( 'child', $this->instance );
		$parents_prep = $this->_metab_family_prep( 'parent', $this->instance );

		$children = $children_prep->data;
		$parents = $parents_prep->data;

?>
		<script>
			if ( ! scrib_authority_data ) {
				var scrib_authority_data = {};
			}//end if

			scrib_authority_data['children'] = <?php echo json_encode( $children_prep->detail ); ?>;
			scrib_authority_data['parents'] = <?php echo json_encode( $parents_prep->detail ); ?>;
		</script>

		<label for="<?php echo $this->get_field_id( 'parent_terms' ); ?>">Parent terms describe the broader categories into which the primary term fits</label>
		<textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'parent_terms' ); ?>" id="<?php echo $this->get_field_id( 'parent_terms' ); ?>"><?php echo implode( ', ' , $parents ); ?></textarea>

		<label for="<?php echo $this->get_field_id( 'child_terms' ); ?>">Child terms describe more specific variations of the primary term</label>
		<textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'child_terms' ); ?>" id="<?php echo $this->get_field_id( 'child_terms' ); ?>"><?php echo implode( ', ' , $children ); ?></textarea>
<?php
	}

	public function metab_coincidences( $post )
	{
		// sanity check
		if ( ! $post_meta = $this->get_post_meta( $post->ID ) )
		{
			return FALSE;
		}//end if

		$coincidences = array_slice( (array) $this->get_related_terms_for_authority( $post->ID ) , 0 , 37 );
		?>
		<p>In addition to the terms entered above, <?php echo '<a href="'. get_term_link( $post_meta['primary_term'] ) .'" target="_blank">'. $post_meta['primary_term']->name .'</a>'; ?> is frequently used with the following terms:</p>
		<?php

		foreach ( $coincidences as $k => $v )
		{
			$coincidences[ $k ]->link = get_term_link( $v );
		}//end foreach
		echo wp_generate_tag_cloud( $coincidences );
	}//end metab_coincidences

	public function metab_enforce( $post )
	{
		echo '<a href="'. authority_record()->tools_obj->enforce_authority_on_corpus_url( $post->ID ) .'" target="_blank">Enforce this authority on all posts</a>';
	}//end metab_enforce

	public function metaboxes()
	{
		// add metaboxes
		add_meta_box( 'scrib-authority-primary' , 'Primary Term' , array( $this , 'metab_primary_term' ) , $this->post_type_name , 'normal', 'high' );
		add_meta_box( 'scrib-authority-alias' , 'Alias Terms' , array( $this , 'metab_alias_terms' ) , $this->post_type_name , 'normal', 'high' );
		add_meta_box( 'scrib-authority-family' , 'Family Terms' , array( $this , 'metab_family_terms' ) , $this->post_type_name , 'normal', 'high' );
		add_meta_box( 'scrib-authority-coincidences' , 'Related Term Clusters' , array( $this , 'metab_coincidences' ) , $this->post_type_name , 'normal', 'low' );
		add_meta_box( 'scrib-authority-enforce' , 'Enforce' , array( $this , 'metab_enforce' ) , $this->post_type_name , 'normal', 'low' );

		// @TODO: need metaboxes for links and arbitrary values (ticker symbol, etc)

		// remove the taxonomy metaboxes so we don't get confused
		$taxonomies = authority_record()->supported_taxonomies();
		foreach( $taxonomies as $taxomoy )
		{
			if( $taxomoy->hierarchical )
				remove_meta_box( $taxomoy->name .'div' , 'scrib-authority' , FALSE );
			else
				remove_meta_box( 'tagsdiv-'. $taxomoy->name , 'scrib-authority' , FALSE );
		}
	}

	public function _parse_terms( $which , $source , $target , $delete_cache = FALSE , $limit = 0 )
	{
		$target[ $which ] = array();
		foreach( (array) $this->parse_terms_from_string( $source[ $which ] ) as $term )
		{
			// don't insert the primary term as a child, that's just silly
			if(
				( $which != 'primary_term' ) &&
				( $term->term_taxonomy_id == $target['primary_term']->term_taxonomy_id )
			)
			{
				continue;
			}//end if

			$target[ $which ][] = $term;
			if ( $delete_cache )
			{
				$this->delete_term_authority_cache( $term );
			}//end if
		}//end foreach

		if( $limit )
		{
			$target[ $which ] = array_slice( $target[ $which ] , 0 , $limit );
		}

		return $target;
	}//end _parse_terms

	public function save_post( $post_id )
	{
		// check that this isn't an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		// don't run on post revisions (almost always happens just before the real post is saved)
		if( wp_is_post_revision( $post_id ))
			return;

		// get and check the post
		$post = get_post( $post_id );

		// only work on authority posts
		if( ! isset( $post->post_type ) || $this->post_type_name != $post->post_type )
			return;

		// check the nonce
		if( ! $this->verify_nonce() )
			return;

		// check the permissions
		if( ! current_user_can( 'edit_post' , $post_id ))
			return;

		// get the old data
		$instance = $this->get_post_meta( $post_id );

		// process the new data
		$new_instance = stripslashes_deep( $_POST[ $this->id_base ] );

		// primary (authoritative) taxonomy term
		$instance = $this->_parse_terms( 'primary_term', $new_instance, $instance, TRUE, 1 );
		$instance['primary_term'] = $instance['primary_term'][0];

		// alias terms
		$instance = $this->_parse_terms( 'alias_terms', $new_instance, $instance, TRUE );

		// parent terms
		$instance = $this->_parse_terms( 'parent_terms', $new_instance, $instance );

		// child terms
		$instance = $this->_parse_terms( 'child_terms', $new_instance, $instance );

		// save it
		$this->update_post_meta( $post_id , $instance );
	}//end save_post

	function column_primary_term( $post_id )
	{
		$this->get_post_meta( $post_id );
		return '<a href="'. get_edit_post_link( $post_id ) .'">'. $this->instance['primary_term']->taxonomy .':'. $this->instance['primary_term']->slug .'</a>';
	}

	function column_alias_terms( $post_id )
	{
		$this->get_post_meta( $post_id );

		$output = array();
		foreach( (array) $this->instance['alias_terms'] as $term )
			$output[] = $term->taxonomy .':'. $term->slug;

		return '<a href="'. get_edit_post_link( $post_id ) .'">'. implode( ', ' , $output ) .'</a>';
	}

	function column_parent_terms( $post_id )
	{
		$this->get_post_meta( $post_id );

		$output = array();
		foreach( (array) $this->instance['parent_terms'] as $term )
			$output[] = $term->taxonomy .':'. $term->slug;

		return '<a href="'. get_edit_post_link( $post_id ) .'">'. implode( ', ' , $output ) .'</a>';
	}

	function column_child_terms( $post_id )
	{
		$this->get_post_meta( $post_id );

		$output = array();
		foreach( (array) $this->instance['child_terms'] as $term )
			$output[] = $term->taxonomy .':'. $term->slug;

		return '<a href="'. get_edit_post_link( $post_id ) .'">'. implode( ', ' , $output ) .'</a>';
	}

	function column( $column , $post_id )
	{
		switch( $column )
		{
			case $this->id_base .'_primary_term':
				echo $this->column_primary_term( $post_id );
				break;
			case $this->id_base .'_alias_terms':
				echo $this->column_alias_terms( $post_id );
				break;
			case $this->id_base .'_parent_terms':
				echo $this->column_parent_terms( $post_id );
				break;
			case $this->id_base .'_child_terms':
				echo $this->column_child_terms( $post_id );
				break;
		}
	}

	function columns( $columns )
	{
		// preserve a couple columns
		// this is especially aggressive in removing columns added indiscriminately in other plugins
		// that's also why the filter priority is 11, so we can remove other plugins' cruft (i'm looking at you co-authors-plus)
		$columns = array_intersect_key( $columns , array( 'cb' => TRUE , 'title' => TRUE ));

		// our columns are cooler than the other columns
		$columns[ $this->id_base .'_primary_term' ] = 'Primary Term';
		$columns[ $this->id_base .'_alias_terms' ] = 'Alias Terms';
		$columns[ $this->id_base .'_parent_terms' ] = 'Parent Terms';
		$columns[ $this->id_base .'_child_terms' ] = 'Child Terms';

		return $columns;
	}
}//end Authority_Posttype_Admin class
