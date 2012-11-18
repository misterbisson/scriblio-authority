(function($) {
	if ( typeof scrib_authority_data != 'undefined' ) {
		$('#scrib-authority-primary_term')
			.ScribAuthority({
				taxonomies: scrib_authority_taxonomies
			})
			.ScribAuthority('items', scrib_authority_data['primary']);

		$('#scrib-authority-alias_terms')
			.ScribAuthority()
			.ScribAuthority('items', scrib_authority_data['alias']);

		$('#scrib-authority-parent_terms')
			.ScribAuthority()
			.ScribAuthority('items', scrib_authority_data['parents']);

		$('#scrib-authority-child_terms')
			.ScribAuthority()
			.ScribAuthority('items', scrib_authority_data['children']);
	}//end if
})(jQuery);
