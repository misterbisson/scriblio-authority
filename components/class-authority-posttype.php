<?php
class Authority_Posttype {

	public $admin_obj = FALSE;
	public $tools_obj = FALSE;
	public $go_opencalais = FALSE;
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

		wp_register_style( 'scrib-authority' , $this->plugin_url . '/css/scrib-authority.structure.css' , array() , $this->version );
		wp_register_script( 'scrib-authority' , $this->plugin_url . '/js/jquery.scrib-authority.js' , array('jquery') , $this->version , TRUE );
		wp_register_script( 'scrib-authority-behavior' , $this->plugin_url . '/js/scrib-authority-behavior.js' , array( 'jquery' , 'scrib-authority' ) , $this->version , TRUE );

		add_action( 'init' , array( $this, 'init' ) , 11 );
		add_action( 'wp_head', array( $this, 'wp_head' ) );
		add_action( 'rss_head', array( $this, 'rss_head' ) );
		add_action( 'rss2_head', array( $this, 'rss_head' ) );

		add_filter( 'bloginfo_rss', array( $this, 'bloginfo_rss_filter' ), 10, 2 );
		add_filter( 'template_redirect', array( $this, 'template_redirect' ) , 1 );
		add_filter( 'post_link', array( $this, 'post_link' ), 11, 2 );
		add_filter( 'post_type_link', array( $this, 'post_link' ), 11, 2 );
		add_filter( 'scriblio_facet_taxonomy_terms', array( $this, 'scriblio_facet_taxonomy_terms' ) );

		// We use save_post instead of set_object_terms for a reason
		// If we use set_object_terms taxonomies with no terms set will cause some taxonomy terms to be removed
		add_action( 'save_post', array( $this , 'enforce_authority_on_object' ), 9 );

		if ( is_admin() )
		{
			$this->admin();
			$this->tools();

			add_filter( 'wp_import_post_meta', array( $this, 'wp_import_post_meta' ), 10, 3 );
		}
	}

	public function init()
	{
		$this->register_post_type();
		$this->go_opencalais();
	}

	public function admin()
	{
		if ( ! $this->admin_obj )
		{
			require_once __DIR__ . '/class-authority-posttype-admin.php';
			$this->admin_obj = new Authority_Posttype_Admin;
			$this->admin_obj->plugin_url = $this->plugin_url;
		}

		return $this->admin_obj;
	}

	public function tools()
	{
		if ( ! $this->tools_obj )
		{
			require_once __DIR__ . '/class-authority-posttype-tools.php';
			$this->tools_obj = new Authority_Posttype_Tools;
		}

		return $this->tools_obj;
	}

	// a singleton for the go_opencalais integration object
	public function go_opencalais()
	{

		// sanity check to make sure the go-opencalais plugin is loaded
		if ( ! is_callable( array( 'go_opencalais', 'admin' ) ) )
		{
			return FALSE;
		}

		if ( ! $this->go_opencalais )
		{
			require_once __DIR__ . '/class-authority-go-opencalais.php';
			$this->go_opencalais = new Authority_GO_OpenCalais();
		}

		return $this->go_opencalais;
	} // END go_opencalais

	/**
	 * hooked into the wp_head function
	 */
	public function wp_head()
	{
		$authority = $this->queried_authority_data();

		if ( $authority && ! is_wp_error( $authority->post ) && ! empty( $authority->post->post_excerpt ) )
		{
			echo '<meta name="description" content="' . esc_attr( $authority->post->post_excerpt ) . '">';
		}//end if
	}//end wp_head

	/**
	 * hooked into the rss_head action to insert a thumbnail image if available
	 */
	public function rss_head()
	{
		$authority = $this->queried_authority_data();

		if ( ! $authority || is_wp_error( $authority->post ) )
		{
			return;
		}//end if

		if ( has_post_thumbnail( $authority->post->ID ) )
		{
			$image_url = wp_get_attachment_image_src( get_post_thumbnail_id( $authority->post->ID ), 'large' );
			$image_url = $image_url[0];

			$image  = '<image>';
			$image .= '<url>' . esc_url( $image_url ) . '</url>';
			$image .= '<title>' . wp_kses( $authority->post->post_title, array() ) . '</title>';
			$image .= '<link>' . esc_url( get_permalink( $authority->post->ID ) ) . '</link>';
			$image .= '</image>';

			echo $image;

			unset( $image, $image_url );
		}//end if
	}//end rss_head

	/**
	 * hooked into the bloginfo_rss filter to override the description of the feed based on the term
	 * authority record
	 */
	public function bloginfo_rss_filter( $data, $which )
	{
		if ( 'description' != $which )
		{
			return $data;
		}//end if

		$authority = $this->queried_authority_data();

		if ( ! $authority || is_wp_error( $authority->post ) )
		{
			return $data;
		}//end if

		return wp_kses( $authority->post->post_excerpt, array() );
	}//end bloginfo_rss_filter

	/**
	 * grab the queried object and determine if it is an authority record. Return authority data
	 * or FALSE depending on the result
	 */
	public function queried_authority_data()
	{
		// let's cache the record so we don't make unnecessary queries
		static $authority = null;

		if ( null !== $authority )
		{
			return $authority;
		}//end if

		$term      = get_queried_object();
		$authority = $this->get_term_authority( $term );

		if ( ! $authority )
		{
			$authority = FALSE;

			return $authority;
		}//end if

		$authority->post = get_post( $authority->post_id );

		return $authority;
	}//end queried_authority_data

	public function delete_term_authority_cache( $term )
	{

		// validate the input
		if( ! isset( $term->term_taxonomy_id ))
			return FALSE;

		wp_cache_delete( $term->term_taxonomy_id , 'scrib_authority_ttid_'. $this->version );
	}

	public function get_term_authority( $term )
	{

		// validate the input
		if( ! isset( $term->term_id , $term->taxonomy , $term->term_taxonomy_id ) )
		{
			return FALSE;
		}

		if( $return = wp_cache_get( $term->term_taxonomy_id , 'scrib_authority_ttid_'. $this->version ) )
		{
			return $return;
		}

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
			if( ! isset( $authority[0]->post_type ) || $this->post_type_name != $authority[0]->post_type )
			{
				return FALSE;
			}
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

			// sanity check to make sure this authority record contains
			// valid terms in our system
			if ( ! empty( $return['primary_term'] ) )
			{
				if ( ! isset( $return['primary_term']->term_id ) || ! isset( $return['primary_term']->taxonomy ) )
				{
					return FALSE;
				}
				$term_candidate = get_term( $return['primary_term']->term_id, $return['primary_term']->taxonomy );
				if ( ! $term_candidate || is_wp_error( $term_candidate ) )
				{
					return FALSE;
				}
			}
			if ( ! empty( $return['alias_terms'] ) )
			{
				foreach( $return['alias_terms'] as $ttid => $alias_term )
				{
					if ( ! isset( $alias_term->term_id ) || ! isset( $alias_term->taxonomy ) )
					{
						return FALSE;
					}
					$term_candidate = get_term( $alias_term->term_id, $alias_term->taxonomy );
					if ( ! $term_candidate || is_wp_error( $term_candidate ) )
					{
						return FALSE;
					}
				}
			}
					 
			if( 1 < count( $authority ))
			{
				foreach( $authority as $conflict )
				{
					$return['conflict_ids'][] = $conflict->ID;
				}
			}

			wp_cache_set( $term->term_taxonomy_id , (object) $return , 'scrib_authority_ttid_'. $this->version , $this->cache_ttl );
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
		{
			return;
		}

		// we have an authority record, and
		// we're on an alias term, redirect
		$primary_term_link   = get_term_link( (int) $authority->primary_term->term_id, $authority->primary_term->taxonomy );
		$requested_term_link = get_term_link( (int) $queried_object->term_id, $queried_object->taxonomy );

		// check to make sure neither link is an error
		if ( is_wp_error( $primary_term_link ) || is_wp_error( $requested_term_link ) )
		{
			return;
		}

		wp_redirect( str_replace( $requested_term_link, $primary_term_link, home_url( $_SERVER['REQUEST_URI'] ) ) );
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
		if ( ! isset( $authority->primary_term ) )
		{
			return $permalink;
		}

		// test if this is a valid term
		$term = get_term( $authority->primary_term->term_id , $authority->primary_term->taxonomy );
		if ( ! $term || is_wp_error( $term ) )
		{
			return $permalink;
		}

		// return the permalink for the primary term
		$term_link = get_term_link( (int) $authority->primary_term->term_id, $authority->primary_term->taxonomy );
		// check to make sure the term_link isn't an error
		if ( is_wp_error( $term_link ) )
		{
			return home_url();
		}
		return $term_link;

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

	/**
	 * simplified registered taxonomies (for JSON output)
	 */
	public function simple_authority_taxonomies()
	{
		static $taxonomies = array();

		if ( ! $taxonomies )
		{
			$taxonomy_objects = $this->taxonomies;
			foreach( $taxonomy_objects as $key => $taxonomy ) {
				if(
					'category' == $key ||
					'post_format' == $key
				) {
					continue;
				}//end if

				$taxonomy = get_taxonomy( $taxonomy );

				$taxonomies[ $key ] = $this->simplify_taxonomy_for_json( $taxonomy );
			}//end foreach
		}//end if

		return $taxonomies;
	}//end simple_authority_taxonomies

	/**
	 * simplifies a taxonomy object so that it only includes the elements
	 * that matter to JSON transporting
	 */
	public function simplify_taxonomy_for_json( $taxonomy )
	{
		$tax = new StdClass;

		$tax->name = $taxonomy->name;
		$tax->labels = new StdClass;
		$tax->labels->name = $taxonomy->labels->name;
		$tax->labels->singular_name = $taxonomy->labels->singular_name;

		return $tax;
	}//end simplify_taxonomy_for_json

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

		if ( ! is_wp_error( $this->instance ) && isset( $this->instance->primary_term ) )
		{
			$this->instance->primary_term = $this->sanitize_term( $this->instance->primary_term );
		}//end if

		return $this->instance;
	}

	public function update_post_meta( $post_id, $meta_array )
	{
		// make sure meta is added to the post, not a revision
		if ( $_post_id = wp_is_post_revision( $post_id ) )
		{
			$post_id = $_post_id;
		}//end if

		// the terms we'll set on this object
		$object_terms = array();

		if( is_object( $meta_array ) )
		{
			$meta = (array) $meta_array;
		}//end if
		else
		{
			$meta = $meta_array;
		}//end else

		// primary (authoritative) taxonomy term
		if( isset( $meta['primary_term']->term_id ) )
		{
			// synchronize primary term
			$meta['primary_term'] = $this->sanitize_term( $meta['primary_term'] );

			$object_terms[ $meta['primary_term']->taxonomy ][] = (int) $meta['primary_term']->term_id;

			// clear the authority cache for this term
			$this->delete_term_authority_cache( $meta['primary_term'] );

			// updating the post title is a pain in the ass, just look at what happens when we try to save it
			$post = get_post( $post_id );
			$post->post_title = $meta['primary_term']->name;
			if( ! preg_match( '/^'. $meta['primary_term']->slug .'/', $post->post_name ) )
			{
				// update the title
				$post->post_name = $meta['primary_term']->slug;

				// remove revision support
				// but this post type doesn't support revisions
				// remove_post_type_support(  $this->post_type_name , 'revisions' );

				// remove the action before attempting to save the post, then reinstate it
				if ( is_admin() )
				{
					remove_action( 'save_post', array( $this->admin(), 'save_post' ));
					wp_insert_post( $post );
					add_action( 'save_post', array( $this->admin(), 'save_post' ));
				}//end if
				else
				{
					wp_insert_post( $post );
				}//end else

				// add back the revision support
				// but this post type doesn't support revisions
				// add_post_type_support( $this->post_type_name , 'revisions' );
			}//end if
		}//end if

		$term_groups = array(
			'alias_terms',
			'parent_terms',
			'child_terms',
		);

		foreach ( $term_groups as $group )
		{
			if ( ! isset( $meta[ $group ] ) )
			{
				continue;
			}//end if

			$dedupe = array();
			foreach( (array) $meta[ $group ] as $term )
			{
				// synchronize term
				$term = $this->sanitize_term( $term );

				$dedupe[ (int) $term->term_taxonomy_id ] = $term;
			}//end foreach

			$meta[ $group ] = $dedupe;
			unset( $dedupe );

			foreach( (array) $meta[ $group ] as $term )
			{
				// don't insert the primary term as an alias/child/parent, that's just silly
				if( $term->term_taxonomy_id == $meta['primary_term']->term_taxonomy_id )
				{
					continue;
				}//end if

				// Add alias terms to the taxonomy system
				// IMPORTANT: don't add parent or child terms, that causes undesirable behavior
				if( 'alias_terms' == $group )
				{
					$object_terms[ $term->taxonomy ][] = (int) $term->term_id;
				}

				// delete the authority cache for these terms
				$this->delete_term_authority_cache( $term );

			}//end foreach
		}//end foreach

		// save it
		update_post_meta( $post_id, $this->post_meta_key, $meta );

		// update the term relationships for this post (add the primary and alias terms)
		foreach( $object_terms as $k => $v )
		{
			wp_set_object_terms( $post_id, $v, $k, FALSE );
		}//end foreach
	}//end update_post_meta

	/**
	 * Hook into the WordPress importer to hijack and sanitize scrib-authority term/taxonomy IDs
	 *
	 * @param $meta array Post meta
	 * @param $post_id int Post ID
	 * @param $post WP_Post Post object
	 */
	public function wp_import_post_meta( $meta, $post_id, $post )
	{
		$authority_key = null;

		// find the scrib-authority meta data and meta index ($authority_key)
		foreach ( $meta as $key => $data )
		{
			if ( 'scrib-authority' == $data['key'] )
			{
				$authority     = maybe_unserialize( $data['value'] );
				$authority_key = $key;
			}//end if
		}//end foreach

		if ( null === $authority_key )
		{
			// no scrib-authority data was found. Return the meta data as is
			return $meta;
		}//end if

		if (
			   ! isset( $authority['primary_term'] )
			&& ! isset( $authority['alias_terms'] )
			&& ! isset( $authority['child_terms'] )
			&& ! isset( $authority['parent_terms'] )
		)
		{
			// if there aren't any primary, alias, parent, or child terms, just return the post meta
			return $meta;
		}//end if

		// let's insert the authority data on the post.  We're using update_post_meta
		// because it sanitizes the scrib-authority terms
		$this->update_post_meta( $post_id, $authority );

		// since the meta has been assigned to the post a la update_post_meta, we can unset
		// it from the meta array handled by the wordpress importer
		unset( $meta[ $authority_key ] );

		// return the rest of the meta data
		return $meta;
	}//end wp_import_post_meta

	/**
	 * ensures that authority posts have accurate term/taxonomy ids in the scrib-authority
	 * meta.  If a term doesn't exist, it is created.
	 *
	 * @param $sync Object Term object
	 */
	public function sanitize_term( $sync )
	{
		// let's try and get a term with the same ID
		$term = get_term_by( 'id', $sync->term_id, $sync->taxonomy );

		// if the term can be found with an ID and the name/description/slug match, we've found a
		// match.  Return it.
		if (
			   ! is_wp_error( $term )
			&& $term->slug == $sync->slug
			&& $term->name == $sync->name
			&& $term->description == $sync->description
		)
		{
			return $term;
		}//end if

		$term_args = array(
			'slug' => $sync->slug,
			'description' => $sync->description,
		);

		$override = FALSE;

		// since we couldn't find the term by ID, let's look for it by slug
		if ( ! ( $term = get_term_by( 'slug', $sync->slug, $sync->taxonomy ) ) )
		{
			// the slug wasn't found.  Insert the term.
			$term     = wp_insert_term( $sync->name, $sync->taxonomy, $term_args );
			$override = TRUE;
		}//end if
		elseif ( $term->name != $sync->name || $term->description != $sync->description )
		{
			// the slug was found, but some of the details are different.  Synchronize them
			$term     = wp_update_term( $term->term_id, $term->taxonomy, $term_args );
			$override = TRUE;
		}//end elseif

		if ( $override && ! is_wp_error( $term ) )
		{
			// if we get in here, the term was either created or updated and we need
			// to pull a new object
			$sync = get_term_by( 'id', $term['term_id'], $sync->taxonomy );
		}//end if
		else
		{
			$sync = $term;
		}//end else

		// we return $sync rather than term in the event that we get all the way down here with dirty
		// data.  We'll keep it dirty (and present) until it can be fixed.
		return $sync;
	}//end sanitize_term

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
				'publicly_queryable' => FALSE,
				'exclude_from_search' => TRUE,
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
		if ( wp_is_post_revision( $object_id ) )
		{
			return;
		}

		if ( ! $object_id )
		{
			return;
		}

		// get and check the post
		$post = get_post( $object_id );

		// don't mess with authority posts
		if ( ! isset( $post->post_type ) || $this->post_type_name == $post->post_type )
		{
			return;
		}

		// get the terms to work with
		$terms = wp_get_object_terms( $object_id , array_keys( $this->supported_taxonomies() ) );

		$delete_terms = array();

		$new_object_terms = $terms_to_delete = array();
		foreach( $terms as $term )
		{
			if ( $authority = $this->get_term_authority( $term ))
			{
				// add the preferred term to list of terms to add to the object
				$new_object_terms[ $authority->primary_term->taxonomy ][] = (int) $authority->primary_term->term_id;

				// if the current term is not in the same taxonomy as the preferred term,
				// list it for removal from the object
				if( $authority->primary_term->taxonomy != $term->taxonomy )
				{
					$delete_terms[] = $term->term_taxonomy_id;
				}

			}
		}

		// are the caches dirtied by changes below?
		$dirty = FALSE;

		// remove the alias terms that are not in primary taxonomy
		if ( count( $delete_terms ) )
		{
			$this->delete_terms_from_object_id( $object_id, $delete_terms );
			$dirty = TRUE;
		}

		// add the alias and parent terms to the object
		if ( count( $new_object_terms ) )
		{
			foreach( (array) $new_object_terms as $k => $v )
			{
				wp_set_object_terms( $object_id, $v, $k, TRUE );
			}

			// @TODO: this may be wasted here, see the TODO below
			$dirty = TRUE;
		}

		// clean the post cache and update the object term cache
		if ( $dirty )
		{
			clean_post_cache( $object_id );
			$post = get_post( $object_id );
			if ( isset( $post->post_type ) )
			{
				update_object_term_cache( $object_id, $post->post_type );
			}
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

		// clean the object term cache
		$post = get_post( $object_id );
		if ( isset( $post->post_type ) )
		{
			clean_object_term_cache( $object_id, $post->post_type );
		}

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

	public function filter_terms_by_authority( $input_terms , $exclude_ttids = array(), $honor_input_counts = FALSE )
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
					// If this list of terms came from a facet we don't want to override the existing count values
					if ( ! $honor_input_counts )
					{
						// take the highest count value
						// note: summing the counts leads to lies, results in double-counts and worse
						$output_terms[ $authority->primary_term->term_taxonomy_id ]->count = max( $input_term->count , $output_terms[ $authority->primary_term->term_taxonomy_id ]->count );
					} // END if
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
				$term_candidate = get_term( $input_term->term_id , $input_term->taxonomy );
				if ( ! empty( $term_candidate ) && ! is_wp_error( $term_candidate ) )
				{
					$output_terms[ $input_term->term_taxonomy_id ] = $term_candidate;
					if ( $honor_input_counts )
					{
						$output_terms[ $input_term->term_taxonomy_id ]->count = $input_term->count;
					} // END if
				}
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

	public function scriblio_facet_taxonomy_terms( $terms )
	{
		return $this->filter_terms_by_authority( $terms, '', TRUE );
	} // END scriblio_facet_taxonomy_terms
}//end Authority_Posttype class
