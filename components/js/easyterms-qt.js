(function(){
	jQuery.each(scriblio_easyterms, function(taxonomy, shortcode) {
		var shortcode_open = '[' + shortcode + ']',
			shortcode_close = '[/' + shortcode + ']';

		QTags.addButton( 'easytag' + taxonomy, taxonomy, shortcode_open, shortcode_close );
	});
})();
