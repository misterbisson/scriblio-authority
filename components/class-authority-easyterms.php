<?php

/**
 * Attach terms to an object by surrounding keywords in your
 * post with a shortcode. Adds buttons to the HTML and Visual
 * editors for easy shortcode wrapping.
 */
class Authority_EasyTerms
{
	/**
	 * Array in the format $taxonomy => $shortcode
	 */
	public $taxonomies = array();

	/**
	 * Set to TRUE when we are parsing the shortcodes on save.
	 */
	public $is_save_post = false;

	/**
	 * Store $post_ID from save_post action.
	 */
	public $post_ID = null;

	/**
	 * Store $post from save_post action.
	 */
	public $post = null;

	/**
	 * Always the array_flip() of $taxonomies.
	 */
	public $shortcodes = array();

	public function __construct()
	{
		add_action( 'init', array( $this, 'add_buttons' ) );
		add_action( 'init', array( $this, 'add_shortcodes' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'action_enqueue_scripts' ) );
		add_action( 'admin_print_scripts', array( $this, 'action_print_scripts' ), 1 );
		add_action( 'save_post', array( $this, 'parse_shortcodes' ), 3, 2 );

	}

	public function add_taxonomy( $taxonomy )
	{
		$this->taxonomies[$taxonomy] = $this->taxonomy2shortcode( $taxonomy );

		$this->shortcodes = array_flip( $this->taxonomies );
	}

	public function action_print_scripts()
	{
		?>
<script type="text/javascript">
(function(){
	var scriblio_easyterms = <?php echo json_encode( $this->taxonomies ); ?>;
	window.scriblio_easyterms = scriblio_easyterms;
})();
</script><?php
	}

	public function action_enqueue_scripts()
	{
		wp_enqueue_script( 'easyterms-qt', plugins_url( 'js/easyterms-qt.js', __FILE__ ), array('quicktags') );
	}

	public function add_buttons()
	{
		if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') )
			return;

		if ( 'true' == get_user_option('rich_editing') )
		{
			add_filter( 'mce_external_plugins', array( $this, 'tinymce_plugins' ) );
			add_filter( 'mce_buttons', array( $this, 'tinymce_buttons' ) );
		}
	}

	public function tinymce_plugins( $plugins )
	{
		$plugins['easyterms'] = plugins_url( 'js/easyterms-mce.js', __FILE__ );
		return $plugins;
	}

	public function tinymce_buttons( $buttons )
	{
		if( 0 === count( $this->taxonomies ) )
			return $buttons;

		array_push( $buttons, 'separator' );

		foreach( $this->taxonomies as $taxonomy => $shortcode )
		{
			array_push( $buttons, $taxonomy );
		}

		return $buttons;
	}

	public function taxonomy2shortcode( $taxonomy )
	{
		return $taxonomy;
	}

	public function add_shortcodes()
	{
		foreach( $this->taxonomies as $taxonomy => $shortcode )
		{
			add_shortcode( $shortcode, array( $this, 'do_shortcode' ) );
		}
	}

	public function parse_shortcodes( $post_ID, $post )
	{
		// only operate on proper posts
		// @TODO: make this configurable
		if( 'post' !== $post->post_type )
			return;

		// check that this isn't an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		// don't run on post revisions (almost always happens just before the real post is saved)
		if( wp_is_post_revision( $post->ID ))
			return;

		$this->is_save_post = true;
		$this->post_ID = $post->ID;
		$this->post = $post;

		$content = $post->post_content;
		do_shortcode( $content );

		$this->is_save_post = false;
		$this->post_ID = null;
		$this->post = null;
	}

	/**
	 * Intercept the shortcode handling during save_post, and adds the
	 * found content to the post as a term.
	 *
	 * @todo What to do if the term isn't valid for the taxonomy?
	 * @uses apply_filters() Calls 'easytags_term' on the found term
	 */
	public function do_shortcode_save( $atts, $content, $tag )
	{
		global $wpdb;

		$taxonomy = $this->shortcodes[ $tag ];

		$term_str = wp_kses( $content, array() );
		$term_obj = get_term_by( 'name' , $wpdb->escape( $term_str ) , $taxonomy );

		$term_str = apply_filters( 'easytags_term', $term_str , $term_obj );

		wp_set_object_terms( $this->post_ID, $term_str , $taxonomy , TRUE );
	}

	public function do_shortcode( $atts, $content, $tag )
	{
		if( $this->is_save_post )
			return $this->do_shortcode_save( $atts, $content, $tag );

		$taxonomy = $this->shortcodes[$tag];

		$link = get_term_link( $content, $taxonomy );
		if( is_wp_error( $link ) )
		{
			$error = $link;
			// @todo Do something with $error?
			return $content;
		}

		return sprintf( '<a href="%s">%s</a>', htmlentities( $link ), $content );
	}
}
