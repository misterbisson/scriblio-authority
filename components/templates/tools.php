<div class="wrap go-featured-admin">
	<?php screen_icon( 'tools' ); ?>
	<h2>Authority Record Tools</h2>

	<div class="tool-box">
		<h3 class="title">Report on term usage and authority by taxonomy</h3>
		<ul>
			<?php
			foreach( authority_record()->supported_taxonomies() as $tax_obj )
			{
			?>
				<li>Download <a href="<?php echo admin_url( 'admin-ajax.php?action=scrib_term_report&taxonomy=' . $tax_obj->name ); ?>"><?php echo strtolower( $tax_obj->label ); ?> CSV</a></li>
			<?php
			}
			?>
		</ul>
	</div>
	
	<?php 
	if( current_user_can( 'manage_options' ))
	{
	?>
		<div class="tool-box">
			<h3 class="title">Advanced: Clean numeric term slug suffixes</h3>
			<p>Warning: don't attempt this unless you've read the code and know exactly what it does.</p>

			<p><a href="<?php echo admin_url( 'admin-ajax.php?action=scrib_term_suffix_cleaner' ); ?>" target="_blank">Clean numeric term slug suffixes</a>, use same term_id for same term_name in different taxonomies</p>
		</div>

		<div class="tool-box">
			<h3 class="title">Advanced: Create authority records</h3>
			<p>Warning: don't attempt this unless you've read the code and know exactly what it does.</p>
			<p>Create authority records for terms shared in multiple taxonomies</p>
			<ul>
				<?php
				$taxs = authority_record()->supported_taxonomies();
				
				foreach( $taxs as $tax_obj_a )
				{
					foreach( $taxs as $tax_obj_b )
					{
						if( $tax_obj_a->name === $tax_obj_b->name )
							continue;
						?>
						<li><a href="<?php echo admin_url( 'admin-ajax.php?action=scrib_create_authority_records&old_tax='. $tax_obj_b->name .'&new_tax='. $tax_obj_a->name .'&paged=0&posts_per_page=3' ); ?>">Make <?php echo strtolower( $tax_obj_b->label ); ?> authoritative over <?php echo strtolower( $tax_obj_a->label ); ?></a> where their terms intersect</li>
						<?php
					}
				}
				?>
			</ul>
	<?php
	}
	?>

</div>